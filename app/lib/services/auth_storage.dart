import 'package:flutter_secure_storage/flutter_secure_storage.dart';

abstract class AuthStorage {
  Future<void> saveDeviceToken(String token);
  Future<String?> getDeviceToken();
  Future<void> clearDeviceToken();

  Future<void> setOnlineEnabled(bool enabled);
  Future<bool> isOnlineEnabled();

  Future<void> setServerUrl(String url);
  Future<String> getServerUrl();

  Future<void> setShopName(String? name);
  Future<String?> getShopName();

  Future<void> setDeviceName(String name);
  Future<String> getDeviceName();

  Future<void> clearAll();
}

class SecureAuthStorage implements AuthStorage {
  final FlutterSecureStorage _storage;

  static const _keyToken = 'device_token';
  static const _keyOnline = 'online_enabled';
  static const _keyServerUrl = 'server_url';
  static const _keyShopName = 'shop_name';
  static const _keyDeviceName = 'device_name';

  static const defaultServerUrl = 'https://techniques-under-gilbert-proceeding.trycloudflare.com';
  static const defaultDeviceName = 'Android POS 1';

  SecureAuthStorage({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(),
              iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
            );

  @override
  Future<void> saveDeviceToken(String token) async {
    // SECURITY: Do not log token in console or debug logs
    await _storage.write(key: _keyToken, value: token);
  }

  @override
  Future<String?> getDeviceToken() async {
    return _storage.read(key: _keyToken);
  }

  @override
  Future<void> clearDeviceToken() async {
    await _storage.delete(key: _keyToken);
  }

  @override
  Future<void> setOnlineEnabled(bool enabled) async {
    await _storage.write(key: _keyOnline, value: enabled ? 'true' : 'false');
  }

  @override
  Future<bool> isOnlineEnabled() async {
    final val = await _storage.read(key: _keyOnline);
    return val == 'true';
  }

  @override
  Future<void> setServerUrl(String url) async {
    await _storage.write(key: _keyServerUrl, value: url.trim());
  }

  @override
  Future<String> getServerUrl() async {
    final val = await _storage.read(key: _keyServerUrl);
    if (val != null && (val.isEmpty || val.contains('study-creek-easier-flat.trycloudflare.com'))) {
      return defaultServerUrl;
    }
    return val?.isNotEmpty == true ? val! : defaultServerUrl;
  }

  @override
  Future<void> setShopName(String? name) async {
    if (name == null) {
      await _storage.delete(key: _keyShopName);
    } else {
      await _storage.write(key: _keyShopName, value: name.trim());
    }
  }

  @override
  Future<String?> getShopName() async {
    return _storage.read(key: _keyShopName);
  }

  @override
  Future<void> setDeviceName(String name) async {
    await _storage.write(key: _keyDeviceName, value: name.trim());
  }

  @override
  Future<String> getDeviceName() async {
    final val = await _storage.read(key: _keyDeviceName);
    return val?.isNotEmpty == true ? val! : defaultDeviceName;
  }

  @override
  Future<void> clearAll() async {
    await _storage.deleteAll();
  }
}

/// In-memory storage for unit tests and local mock harnesses.
class InMemoryAuthStorage implements AuthStorage {
  final Map<String, String> _data = {};

  @override
  Future<void> saveDeviceToken(String token) async {
    _data['token'] = token;
  }

  @override
  Future<String?> getDeviceToken() async {
    return _data['token'];
  }

  @override
  Future<void> clearDeviceToken() async {
    _data.remove('token');
  }

  @override
  Future<void> setOnlineEnabled(bool enabled) async {
    _data['online'] = enabled ? 'true' : 'false';
  }

  @override
  Future<bool> isOnlineEnabled() async {
    return _data['online'] == 'true';
  }

  @override
  Future<void> setServerUrl(String url) async {
    _data['server_url'] = url;
  }

  @override
  Future<String> getServerUrl() async {
    return _data['server_url'] ?? SecureAuthStorage.defaultServerUrl;
  }

  @override
  Future<void> setShopName(String? name) async {
    if (name == null) {
      _data.remove('shop_name');
    } else {
      _data['shop_name'] = name;
    }
  }

  @override
  Future<String?> getShopName() async {
    return _data['shop_name'];
  }

  @override
  Future<void> setDeviceName(String name) async {
    _data['device_name'] = name;
  }

  @override
  Future<String> getDeviceName() async {
    return _data['device_name'] ?? SecureAuthStorage.defaultDeviceName;
  }

  @override
  Future<void> clearAll() async {
    _data.clear();
  }
}
