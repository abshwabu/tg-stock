import 'dart:convert';
import 'dart:typed_data';

import 'package:cryptography/cryptography.dart';
import 'package:uuid/uuid.dart';

import 'crockford_base32.dart';
import 'license_storage.dart';

class InstallIdService {
  final LicenseStorage _storage;
  static const String defaultBotUsername = 'yenesuq_bot';

  InstallIdService({required LicenseStorage storage}) : _storage = storage;

  /// Retrieve the 16-byte install ID (as UUIDv4) or generate and persist on first run.
  Future<String> getInstallId() async {
    final existing = await _storage.getInstallId();
    if (existing != null && existing.trim().isNotEmpty) {
      return existing.trim();
    }
    const uuid = Uuid();
    final newId = uuid.v4();
    await _storage.saveInstallId(newId);
    return newId;
  }

  /// Compute the 8-byte bind from SHA-256 of the install ID.
  Future<Uint8List> getBindBytes() async {
    final id = await getInstallId();
    final digest = await Sha256().hash(utf8.encode(id.trim()));
    return Uint8List.fromList(digest.bytes.sublist(0, 8));
  }

  /// Get the checksummed Device Code formatted as DXXX-XXXX-XXXX-XXX.
  Future<String> getDeviceCode() async {
    final bind = await getBindBytes();
    return CrockfordBase32.formatCode('D', bind);
  }

  /// Derive 8-byte bind for a shop UUID.
  static Future<Uint8List> computeShopBindBytes(String shopUuid) async {
    final digest = await Sha256().hash(utf8.encode(shopUuid.trim().toLowerCase()));
    return Uint8List.fromList(digest.bytes.sublist(0, 8));
  }

  /// Get the Telegram bot deep link to buy/activate a license for this device.
  Future<String> getTelegramDeepLink({String botUsername = defaultBotUsername}) async {
    final code = await getDeviceCode();
    return 'https://t.me/$botUsername?start=code_$code';
  }

  /// Get the ready message containing the device code and bot link.
  Future<String> getReadyMessage({String botUsername = defaultBotUsername}) async {
    final code = await getDeviceCode();
    final link = await getTelegramDeepLink(botUsername: botUsername);
    return 'TGStock Device Code: $code\nActivate / Renew License: $link';
  }
}
