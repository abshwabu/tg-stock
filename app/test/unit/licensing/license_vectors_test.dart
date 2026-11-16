import 'dart:convert';
import 'dart:io';

import 'package:app/services/licensing/crockford_base32.dart';
import 'package:app/services/licensing/license_models.dart';
import 'package:app/services/licensing/license_verifier.dart';
import 'package:cryptography/cryptography.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  late Map<String, dynamic> fixtures;

  setUpAll(() {
    // Look for license_vectors.json in ../docs/fixtures/ or docs/fixtures/
    final candidates = [
      '../docs/fixtures/license_vectors.json',
      'docs/fixtures/license_vectors.json',
      '../../docs/fixtures/license_vectors.json',
    ];
    File? found;
    for (final p in candidates) {
      final f = File(p);
      if (f.existsSync()) {
        found = f;
        break;
      }
    }
    if (found == null) {
      throw StateError('Could not find license_vectors.json fixture file');
    }
    fixtures = jsonDecode(found.readAsStringSync()) as Map<String, dynamic>;
  });

  group('Shared Device & Shop Code Fixtures', () {
    test('passes every code test case in license_vectors.json', () {
      final codeCases = fixtures['code_test_cases'] as List<dynamic>;
      expect(codeCases, isNotEmpty);

      for (final tc in codeCases) {
        final id = tc['id'] as String;
        final rawCode = tc['code'] as String;
        final expectedValid = tc['expected_valid'] as bool;
        final expectedNormalized = tc['normalized'] as String?;

        final result = CrockfordBase32.parseCode(rawCode);

        if (expectedValid) {
          expect(result.isValid, isTrue, reason: 'Test case $id should be valid: ${result.error}');
          if (expectedNormalized != null) {
            expect(result.normalized, equals(expectedNormalized), reason: 'Normalized match for $id');
          }
          final expectedBindHex = tc['bind_hex'] as String;
          final bindHex = result.bind!.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
          expect(bindHex, equals(expectedBindHex), reason: 'Bind hex match for $id');
        } else {
          expect(result.isValid, isFalse, reason: 'Test case $id should fail');
          final expectedError = tc['expected_error'] as String?;
          if (expectedError != null) {
            expect(result.error, contains(expectedError), reason: 'Error reason match for $id');
          }
        }
      }
    });

    test('formatCode matches sample outputs exactly', () {
      final sample1BindHex = fixtures['sample_entities']['device_1']['bind_hex'] as String;
      final bindBytes = <int>[];
      for (int i = 0; i < sample1BindHex.length; i += 2) {
        bindBytes.add(int.parse(sample1BindHex.substring(i, i + 2), radix: 16));
      }
      final code = CrockfordBase32.formatCode('D', bindBytes);
      expect(code, equals(fixtures['sample_entities']['device_1']['device_code']));

      final shopBindHex = fixtures['sample_entities']['shop_1']['bind_hex'] as String;
      final shopBindBytes = <int>[];
      for (int i = 0; i < shopBindHex.length; i += 2) {
        shopBindBytes.add(int.parse(shopBindHex.substring(i, i + 2), radix: 16));
      }
      final shopCode = CrockfordBase32.formatCode('S', shopBindBytes);
      expect(shopCode, equals(fixtures['sample_entities']['shop_1']['shop_code']));
    });
  });

  group('Shared License Test Vectors (§13.1)', () {
    test('passes every license test vector in license_vectors.json', () async {
      final testCases = fixtures['license_test_cases'] as List<dynamic>;
      expect(testCases, isNotEmpty);

      final trustedKeysJson = fixtures['trusted_keys'] as Map<String, dynamic>;
      final trustedKeys = <int, String>{};
      trustedKeysJson.forEach((k, v) {
        trustedKeys[int.parse(k)] = v['public_key_hex'] as String;
      });

      final revocationList = fixtures['revocation_list'] as List<dynamic>;

      for (final tc in testCases) {
        final id = tc['id'] as String;
        final token = tc['token'] as String;
        final clockDay = tc['clock_day'] as int;
        final highWaterDay = tc['high_water_day'] as int;
        final appMajor = tc['app_major_version'] as int? ?? 1;
        final expectedState = tc['expected_state'] as String;
        final expectedSuspect = tc['expected_clock_suspect'] as bool;

        // Context bind
        final contextType = tc['context_type'] as String?;
        final contextId = tc['context_id'] as String?;
        int? expectedTypeInt;
        List<int>? expectedBindBytes;

        if (contextType == 'device' && contextId != null) {
          expectedTypeInt = 1;
          final digest = await Sha256().hash(utf8.encode(contextId.trim()));
          expectedBindBytes = digest.bytes.sublist(0, 8);
        } else if (contextType == 'shop' && contextId != null) {
          expectedTypeInt = 2;
          final digest = await Sha256().hash(utf8.encode(contextId.trim().toLowerCase()));
          expectedBindBytes = digest.bytes.sublist(0, 8);
        }

        final result = await LicenseVerifier.verify(
          tokenString: token,
          expectedType: expectedTypeInt,
          expectedBind: expectedBindBytes,
          today: clockDay,
          highWater: highWaterDay,
          appMajor: appMajor,
          revocationList: revocationList,
          customTrustedKeys: trustedKeys,
        );

        expect(
          result.state.wireName,
          equals(expectedState),
          reason: 'Test case "$id" state mismatch. Reason: ${result.reason}',
        );

        expect(
          result.isClockSuspect,
          equals(expectedSuspect),
          reason: 'Test case "$id" clock_suspect mismatch',
        );

        if (tc.containsKey('expected_payload') && result.payload != null && id != 'tampered_payload') {
          final expP = tc['expected_payload'] as Map<String, dynamic>;
          expect(result.payload!.ver, equals(expP['ver']));
          expect(result.payload!.kid, equals(expP['kid']));
          expect(result.payload!.type.code, equals(expP['type_int']));
          expect(result.payload!.plan.code, equals(expP['plan_int']));
          expect(result.payload!.licenseIdHex, equals(expP['license_id_hex']));
          expect(result.payload!.bindHex, equals(expP['bind_hex']));
          expect(result.payload!.issuedDay, equals(expP['issued_day']));
          expect(result.payload!.nbfDay, equals(expP['nbf_day']));
          expect(result.payload!.expDay, equals(expP['exp_day']), reason: 'exp_day mismatch in test case $id');
          expect(result.payload!.graceDays, equals(expP['grace_days']));
          expect(result.payload!.seats, equals(expP['seats']));
          expect(result.payload!.featuresBitmask, equals(expP['features_bitmask']));
          expect(result.payload!.maxMajor, equals(expP['max_major']));
        }
      }
    });
  });
}
