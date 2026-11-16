import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

abstract class LicenseStorage {
  Future<String?> getInstallId();
  Future<void> saveInstallId(String id);

  Future<String?> getLicenseToken();
  Future<void> saveLicenseToken(String token);
  Future<void> clearLicenseToken();

  Future<int?> getHighWaterDay();
  Future<void> saveHighWaterDay(int day);

  Future<List<String>> getRevocationList();
  Future<void> saveRevocationList(List<String> list);

  Future<String?> getPairedShopUuid();
  Future<void> savePairedShopUuid(String? shopUuid);

  Future<void> clearAll();
}

class SecureLicenseStorage implements LicenseStorage {
  final FlutterSecureStorage _storage;

  static const _keyInstallId = 'license_install_id';
  static const _keyToken = 'license_token';
  static const _keyHighWater = 'license_high_water_day';
  static const _keyRevocations = 'license_revocation_list';
  static const _keyPairedShopUuid = 'license_paired_shop_uuid';

  SecureLicenseStorage({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(),
              iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
            );

  @override
  Future<String?> getInstallId() async {
    return _storage.read(key: _keyInstallId);
  }

  @override
  Future<void> saveInstallId(String id) async {
    await _storage.write(key: _keyInstallId, value: id.trim());
  }

  @override
  Future<String?> getLicenseToken() async {
    return _storage.read(key: _keyToken);
  }

  @override
  Future<void> saveLicenseToken(String token) async {
    // SECURITY: Do not log raw license token in logs or console
    await _storage.write(key: _keyToken, value: token.trim());
  }

  @override
  Future<void> clearLicenseToken() async {
    await _storage.delete(key: _keyToken);
  }

  @override
  Future<int?> getHighWaterDay() async {
    final raw = await _storage.read(key: _keyHighWater);
    if (raw == null || raw.isEmpty) return null;
    return int.tryParse(raw);
  }

  @override
  Future<void> saveHighWaterDay(int day) async {
    await _storage.write(key: _keyHighWater, value: day.toString());
  }

  @override
  Future<List<String>> getRevocationList() async {
    final raw = await _storage.read(key: _keyRevocations);
    if (raw == null || raw.isEmpty) return const [];
    try {
      final list = jsonDecode(raw) as List<dynamic>;
      return list.map((e) => e.toString()).toList();
    } catch (_) {
      return const [];
    }
  }

  @override
  Future<void> saveRevocationList(List<String> list) async {
    await _storage.write(key: _keyRevocations, value: jsonEncode(list));
  }

  @override
  Future<String?> getPairedShopUuid() async {
    return _storage.read(key: _keyPairedShopUuid);
  }

  @override
  Future<void> savePairedShopUuid(String? shopUuid) async {
    if (shopUuid == null || shopUuid.isEmpty) {
      await _storage.delete(key: _keyPairedShopUuid);
    } else {
      await _storage.write(key: _keyPairedShopUuid, value: shopUuid.trim());
    }
  }

  @override
  Future<void> clearAll() async {
    await _storage.deleteAll();
  }
}

class InMemoryLicenseStorage implements LicenseStorage {
  final Map<String, String> _data = {};

  @override
  Future<String?> getInstallId() async => _data['install_id'];

  @override
  Future<void> saveInstallId(String id) async {
    _data['install_id'] = id.trim();
  }

  @override
  Future<String?> getLicenseToken() async => _data['token'];

  @override
  Future<void> saveLicenseToken(String token) async {
    _data['token'] = token.trim();
  }

  @override
  Future<void> clearLicenseToken() async {
    _data.remove('token');
  }

  @override
  Future<int?> getHighWaterDay() async {
    final raw = _data['high_water'];
    return raw != null ? int.tryParse(raw) : null;
  }

  @override
  Future<void> saveHighWaterDay(int day) async {
    _data['high_water'] = day.toString();
  }

  @override
  Future<List<String>> getRevocationList() async {
    final raw = _data['revocations'];
    if (raw == null) return const [];
    try {
      final list = jsonDecode(raw) as List<dynamic>;
      return list.map((e) => e.toString()).toList();
    } catch (_) {
      return const [];
    }
  }

  @override
  Future<void> saveRevocationList(List<String> list) async {
    _data['revocations'] = jsonEncode(list);
  }

  @override
  Future<String?> getPairedShopUuid() async => _data['paired_shop_uuid'];

  @override
  Future<void> savePairedShopUuid(String? shopUuid) async {
    if (shopUuid == null) {
      _data.remove('paired_shop_uuid');
    } else {
      _data['paired_shop_uuid'] = shopUuid.trim();
    }
  }

  @override
  Future<void> clearAll() async {
    _data.clear();
  }
}
