import 'dart:convert';
import 'dart:math';

import 'package:cryptography/cryptography.dart';
import 'package:flutter/foundation.dart';

import 'crockford_base32.dart';
import 'date_epoch_service.dart';
import 'license_models.dart';

class LicenseVerifier {
  /// Up to 3 trusted embedded public keys keyed by kid.
  static const Map<int, String> defaultTrustedKeys = {
    1: '0110750deee76ecbe8e5b980061e61e4271929e289a7d3ccf9ce1ff6d9a90f58',
    2: '731f9b900ed6c14d00e265f590cbc89f8f5a88baae5d56173ede17b312cd1871',
    3: '0000000000000000000000000000000000000000000000000000000000000000',
  };

  /// Verify a license token according to LICENSE_SPEC.md §8.
  static Future<LicenseVerificationResult> verify({
    required String tokenString,
    int? expectedType, // 1 = device, 2 = shop
    List<int>? expectedBind, // 8 bytes
    int? today,
    int? highWater,
    int appMajor = 1,
    List<dynamic> revocationList = const [],
    Map<int, String>? customTrustedKeys,
  }) async {
    final currentToday = today ?? DateEpochService.today();
    final currentHighWater = highWater ?? currentToday;
    final isClockSuspect = currentToday < (currentHighWater - 1);

    // 1. Strip spaces, dashes, uppercase
    final raw = tokenString.replaceAll(RegExp(r'[\s\-]+'), '').toUpperCase();
    if (!raw.startsWith('LIC1')) {
      return LicenseVerificationResult(
        state: LicenseState.invalidSignature,
        isValid: false,
        isClockSuspect: isClockSuspect,
        effectiveToday: currentToday,
        reason: 'Missing LIC1 prefix',
      );
    }

    final b32Raw = raw.substring(4);
    // Normalize Crockford Base32 aliases (O -> 0, I -> 1, L -> 1) on payload
    final sb = StringBuffer();
    for (int i = 0; i < b32Raw.length; i++) {
      final c = b32Raw[i];
      if (c == 'O') {
        sb.write('0');
      } else if (c == 'I' || c == 'L') {
        sb.write('1');
      } else {
        sb.write(c);
      }
    }
    final b32 = sb.toString();

    if (b32.length != 152) {
      return LicenseVerificationResult(
        state: LicenseState.invalidSignature,
        isValid: false,
        isClockSuspect: isClockSuspect,
        effectiveToday: currentToday,
        reason: 'Invalid token length (${b32.length} != 152)',
      );
    }

    // 2. Decode Crockford Base32 -> 95 bytes
    Uint8List binary;
    try {
      binary = CrockfordBase32.decode(b32);
    } catch (e) {
      return LicenseVerificationResult(
        state: LicenseState.invalidSignature,
        isValid: false,
        isClockSuspect: isClockSuspect,
        effectiveToday: currentToday,
        reason: 'Base32 decoding failed: $e',
      );
    }

    if (binary.length != 95) {
      return LicenseVerificationResult(
        state: LicenseState.invalidSignature,
        isValid: false,
        isClockSuspect: isClockSuspect,
        effectiveToday: currentToday,
        reason: 'Decoded token length must be 95 bytes (got ${binary.length})',
      );
    }

    final payloadBytes = binary.sublist(0, 31);
    final signatureBytes = binary.sublist(31, 95);

    // 3. Parse payload fields
    LicensePayload payload;
    try {
      payload = parsePayload(payloadBytes);
    } catch (e) {
      return LicenseVerificationResult(
        state: LicenseState.invalidSignature,
        isValid: false,
        isClockSuspect: isClockSuspect,
        effectiveToday: currentToday,
        reason: 'Payload parsing failed: $e',
      );
    }

    // 4. Version check
    if (payload.ver != 1) {
      return LicenseVerificationResult(
        state: LicenseState.unsupportedVersion,
        isValid: false,
        isClockSuspect: isClockSuspect,
        effectiveToday: currentToday,
        payload: payload,
        features: payload.features,
        reason: 'Unsupported token version: ${payload.ver}',
      );
    }

    // 5. Look up trusted public key by kid
    final keys = customTrustedKeys ?? defaultTrustedKeys;
    final pubKeyHex = keys[payload.kid];
    if (pubKeyHex == null || pubKeyHex.isEmpty) {
      return LicenseVerificationResult(
        state: LicenseState.invalidSignature,
        isValid: false,
        isClockSuspect: isClockSuspect,
        effectiveToday: currentToday,
        payload: payload,
        features: payload.features,
        reason: 'Untrusted key id (kid: ${payload.kid})',
      );
    }

    final pubKeyBytes = _hexDecode(pubKeyHex);
    if (pubKeyBytes.length != 32) {
      return LicenseVerificationResult(
        state: LicenseState.invalidSignature,
        isValid: false,
        isClockSuspect: isClockSuspect,
        effectiveToday: currentToday,
        payload: payload,
        features: payload.features,
        reason: 'Invalid public key length',
      );
    }

    // 6. Verify Ed25519 signature over "LIC1" || payload (35 bytes)
    final domainSeparation = utf8.encode('LIC1');
    final dataToVerify = Uint8List(domainSeparation.length + payloadBytes.length);
    dataToVerify.setRange(0, domainSeparation.length, domainSeparation);
    dataToVerify.setRange(domainSeparation.length, dataToVerify.length, payloadBytes);

    try {
      final ed25519 = Ed25519();
      final pubKey = SimplePublicKey(pubKeyBytes, type: KeyPairType.ed25519);
      final sig = Signature(signatureBytes, publicKey: pubKey);
      final isValidSig = await ed25519.verify(dataToVerify, signature: sig);
      if (!isValidSig) {
        return LicenseVerificationResult(
          state: LicenseState.invalidSignature,
          isValid: false,
          isClockSuspect: isClockSuspect,
          effectiveToday: currentToday,
          payload: payload,
          features: payload.features,
          reason: 'Ed25519 signature verification failed',
        );
      }
    } catch (e) {
      return LicenseVerificationResult(
        state: LicenseState.invalidSignature,
        isValid: false,
        isClockSuspect: isClockSuspect,
        effectiveToday: currentToday,
        payload: payload,
        features: payload.features,
        reason: 'Signature check error: $e',
      );
    }

    // 7. Revocation check
    if (_isRevoked(payload.licenseIdHex, revocationList)) {
      return LicenseVerificationResult(
        state: LicenseState.revoked,
        isValid: false,
        isClockSuspect: isClockSuspect,
        effectiveToday: currentToday,
        payload: payload,
        features: payload.features,
        reason: 'License has been administratively revoked',
      );
    }

    // 8. Max major version check
    if (payload.maxMajor > 0 && appMajor > payload.maxMajor) {
      return LicenseVerificationResult(
        state: LicenseState.unsupportedVersion,
        isValid: false,
        isClockSuspect: isClockSuspect,
        effectiveToday: currentToday,
        payload: payload,
        features: payload.features,
        reason: 'App major version ($appMajor) exceeds max supported (${payload.maxMajor})',
      );
    }

    // 9. Type & Bind check
    if (expectedType != null && payload.type.code != expectedType) {
      return LicenseVerificationResult(
        state: LicenseState.wrongBind,
        isValid: false,
        isClockSuspect: isClockSuspect,
        effectiveToday: currentToday,
        payload: payload,
        features: payload.features,
        reason: 'License binding type mismatch',
      );
    }

    if (expectedBind != null && !listEquals(payload.bindBytes, Uint8List.fromList(expectedBind))) {
      return LicenseVerificationResult(
        state: LicenseState.wrongBind,
        isValid: false,
        isClockSuspect: isClockSuspect,
        effectiveToday: currentToday,
        payload: payload,
        features: payload.features,
        reason: 'License bind hash mismatch',
      );
    }

    // 10. Monotonic effective today calculation
    final effectiveToday = max(max(currentToday, currentHighWater), payload.issuedDay);

    // 11. Date state machine
    if (effectiveToday < payload.nbfDay) {
      return LicenseVerificationResult(
        state: LicenseState.notYetValid,
        isValid: false,
        isClockSuspect: isClockSuspect,
        effectiveToday: effectiveToday,
        payload: payload,
        features: payload.features,
        reason: 'License is not valid yet (starts on day ${payload.nbfDay})',
      );
    }

    if (payload.expDay == DateEpochService.lifetimeExpDay) {
      // Lifetime
      return LicenseVerificationResult(
        state: LicenseState.active,
        isValid: true,
        isClockSuspect: isClockSuspect,
        effectiveToday: effectiveToday,
        daysLeft: null,
        payload: payload,
        features: payload.features,
      );
    }

    final daysLeft = payload.expDay - effectiveToday;

    if (effectiveToday <= payload.expDay) {
      if (daysLeft <= 7) {
        return LicenseVerificationResult(
          state: LicenseState.expiring,
          isValid: true,
          isClockSuspect: isClockSuspect,
          effectiveToday: effectiveToday,
          daysLeft: daysLeft,
          payload: payload,
          features: payload.features,
        );
      }
      return LicenseVerificationResult(
        state: LicenseState.active,
        isValid: true,
        isClockSuspect: isClockSuspect,
        effectiveToday: effectiveToday,
        daysLeft: daysLeft,
        payload: payload,
        features: payload.features,
      );
    }

    final daysPastExp = effectiveToday - payload.expDay;
    if (daysPastExp <= payload.graceDays) {
      final graceLeft = payload.graceDays - daysPastExp;
      return LicenseVerificationResult(
        state: LicenseState.grace,
        isValid: true,
        isClockSuspect: isClockSuspect,
        effectiveToday: effectiveToday,
        daysLeft: graceLeft,
        payload: payload,
        features: payload.features,
        reason: 'Operating in grace period ($graceLeft days remaining)',
      );
    }

    return LicenseVerificationResult(
      state: LicenseState.expired,
      isValid: false,
      isClockSuspect: isClockSuspect,
      effectiveToday: effectiveToday,
      daysLeft: 0,
      payload: payload,
      features: payload.features,
      reason: 'License expired ($daysPastExp days past expiration)',
    );
  }

  /// Parse binary 31-byte payload into structured LicensePayload.
  static LicensePayload parsePayload(Uint8List payload) {
    if (payload.length != 31) {
      throw ArgumentError('Payload must be exactly 31 bytes (got ${payload.length})');
    }

    final byteData = ByteData.sublistView(payload);

    final ver = byteData.getUint8(0);
    final kid = byteData.getUint8(1);
    final typeInt = byteData.getUint8(2);
    final planInt = byteData.getUint8(3);

    // license_id: 8 bytes uint64 big-endian
    final licenseIdHigh = byteData.getUint32(4);
    final licenseIdLow = byteData.getUint32(8);
    final licenseId = (BigInt.from(licenseIdHigh) << 32) | BigInt.from(licenseIdLow);
    final licenseIdHex = licenseId.toRadixString(16).padLeft(16, '0');

    final bindBytes = payload.sublist(12, 20);
    final bindHex = _hexEncode(bindBytes);

    final issuedDay = byteData.getUint16(20);
    final nbfDay = byteData.getUint16(22);
    final expDay = byteData.getUint16(24);
    final graceDays = byteData.getUint8(26);
    final seats = byteData.getUint8(27);
    final featuresBitmask = byteData.getUint16(28);
    final maxMajor = byteData.getUint8(30);

    return LicensePayload(
      ver: ver,
      kid: kid,
      type: LicenseTypeExtension.fromCode(typeInt),
      plan: LicensePlanExtension.fromCode(planInt),
      licenseId: licenseId,
      licenseIdHex: licenseIdHex,
      bindBytes: bindBytes,
      bindHex: bindHex,
      issuedDay: issuedDay,
      nbfDay: nbfDay,
      expDay: expDay,
      graceDays: graceDays,
      seats: seats,
      featuresBitmask: featuresBitmask,
      features: LicenseFeatures.extract(featuresBitmask),
      maxMajor: maxMajor,
    );
  }

  static bool _isRevoked(String licenseIdHex, List<dynamic> revocationList) {
    var target = licenseIdHex.toLowerCase().replaceAll(RegExp(r'^0+'), '');
    if (target.isEmpty) target = '0';

    for (final entry in revocationList) {
      String entryHex;
      if (entry is int) {
        entryHex = entry.toRadixString(16);
      } else if (entry is BigInt) {
        entryHex = entry.toRadixString(16);
      } else {
        entryHex = entry.toString();
      }
      var clean = entryHex.toLowerCase().replaceAll(RegExp(r'^0+'), '');
      if (clean.isEmpty) clean = '0';
      if (clean == target) {
        return true;
      }
    }
    return false;
  }

  static Uint8List _hexDecode(String hex) {
    final clean = hex.replaceAll(RegExp(r'[^0-9a-fA-F]'), '');
    final result = Uint8List(clean.length ~/ 2);
    for (int i = 0; i < result.length; i++) {
      result[i] = int.parse(clean.substring(i * 2, i * 2 + 2), radix: 16);
    }
    return result;
  }

  static String _hexEncode(List<int> bytes) {
    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }
}
