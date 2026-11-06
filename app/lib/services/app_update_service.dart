import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:url_launcher/url_launcher.dart';

import '../repositories/ledger_repository.dart';
import 'auth_storage.dart';

class AppUpdateInfo {
  final String latest;
  final int latestCode;
  final String minSupported;
  final int minSupportedCode;
  final String downloadUrl;
  final String notesEn;
  final String notesAm;
  final bool isUpdateAvailable;
  final bool isSyncBlocked;

  const AppUpdateInfo({
    required this.latest,
    required this.latestCode,
    required this.minSupported,
    required this.minSupportedCode,
    required this.downloadUrl,
    required this.notesEn,
    required this.notesAm,
    required this.isUpdateAvailable,
    required this.isSyncBlocked,
  });

  factory AppUpdateInfo.fromJson(Map<String, dynamic> json, int currentCode, String currentVersion) {
    final latest = json['latest'] as String? ?? '1.0.0';
    final latestCode = json['latest_code'] as int? ?? 1;
    final minSupported = json['min_supported'] as String? ?? '1.0.0';
    final minSupportedCode = json['min_supported_code'] as int? ?? 1;
    final downloadUrl = json['download_url'] as String? ?? 'https://stock.yenesuq.com/app/';
    final notesEn = json['notes_en'] as String? ?? '';
    final notesAm = json['notes_am'] as String? ?? '';

    final isUpdateAvailable = latestCode > currentCode || _compareSemver(latest, currentVersion) > 0;
    final isSyncBlocked = minSupportedCode > currentCode || _compareSemver(minSupported, currentVersion) > 0;

    return AppUpdateInfo(
      latest: latest,
      latestCode: latestCode,
      minSupported: minSupported,
      minSupportedCode: minSupportedCode,
      downloadUrl: downloadUrl,
      notesEn: notesEn,
      notesAm: notesAm,
      isUpdateAvailable: isUpdateAvailable,
      isSyncBlocked: isSyncBlocked,
    );
  }

  Map<String, dynamic> toJson() => {
    'latest': latest,
    'latest_code': latestCode,
    'min_supported': minSupported,
    'min_supported_code': minSupportedCode,
    'download_url': downloadUrl,
    'notes_en': notesEn,
    'notes_am': notesAm,
  };

  static int _compareSemver(String a, String b) {
    try {
      final cleanA = a.split('-').first;
      final cleanB = b.split('-').first;
      final partsA = cleanA.split('.').map(int.parse).toList();
      final partsB = cleanB.split('.').map(int.parse).toList();
      for (int i = 0; i < 3; i++) {
        final numA = i < partsA.length ? partsA[i] : 0;
        final numB = i < partsB.length ? partsB[i] : 0;
        if (numA != numB) return numA.compareTo(numB);
      }
      return 0;
    } catch (_) {
      return 0;
    }
  }
}

class AppUpdateService {
  final AuthStorage authStorage;
  final LedgerRepository repository;
  final http.Client? httpClient;
  final String currentVersion;
  final int currentVersionCode;

  // Release builds inject the tag and CI build number; local builds use pubspec defaults.
  static const String defaultVersion = String.fromEnvironment('APP_VERSION', defaultValue: '1.0.0');
  static const int defaultVersionCode = int.fromEnvironment('APP_BUILD_NUMBER', defaultValue: 1);

  AppUpdateInfo? _cachedUpdateInfo;
  AppUpdateInfo? get cachedUpdateInfo => _cachedUpdateInfo;
  Future<AppUpdateInfo?>? _checkInFlight;

  AppUpdateService({
    required this.authStorage,
    required this.repository,
    this.httpClient,
    this.currentVersion = defaultVersion,
    this.currentVersionCode = defaultVersionCode,
  });

  /// Check for application updates at most once a day when online.
  /// Never throws or crashes: fails silently on offline or network error.
  Future<AppUpdateInfo?> checkForUpdate({bool force = false}) {
    final inFlight = _checkInFlight;
    if (inFlight != null) return inFlight;

    final check = _performCheck(force: force);
    _checkInFlight = check;
    return check.whenComplete(() => _checkInFlight = null);
  }

  Future<AppUpdateInfo?> _performCheck({required bool force}) async {
    try {
      // 1. If in-memory cache is empty, attempt restoring from repository
      if (_cachedUpdateInfo == null) {
        final savedJson = await repository.getLastUpdateInfoJson();
        if (savedJson != null && savedJson.isNotEmpty) {
          try {
            final decoded = jsonDecode(savedJson) as Map<String, dynamic>;
            _cachedUpdateInfo = AppUpdateInfo.fromJson(decoded, currentVersionCode, currentVersion);
          } catch (_) {}
        }
      }

      final isOnline = await authStorage.isOnlineEnabled();
      if (!isOnline && !force) {
        return _cachedUpdateInfo;
      }

      final serverUrl = await authStorage.getServerUrl();
      if (serverUrl.isEmpty && !force) {
        return _cachedUpdateInfo;
      }

      final lastCheckMs = await repository.getLastUpdateCheckMs();
      final nowMs = DateTime.now().millisecondsSinceEpoch;
      const oneDayMs = 24 * 60 * 60 * 1000;

      // Rate limit: check at most once a day unless forced
      if (!force && lastCheckMs != null && (nowMs - lastCheckMs) < oneDayMs) {
        return _cachedUpdateInfo;
      }

      if (serverUrl.isEmpty) {
        return _cachedUpdateInfo;
      }

      // Record the attempt before networking. Failures must not trigger a request on every sync.
      await repository.setLastUpdateCheckMs(nowMs);

      final uri = Uri.parse('$serverUrl/v1/app/version');
      final client = httpClient ?? http.Client();

      final response = await client.get(
        uri,
        headers: {'Accept': 'application/json'},
      ).timeout(const Duration(seconds: 4));

      if (response.statusCode == 200) {
        final json = jsonDecode(utf8.decode(response.bodyBytes)) as Map<String, dynamic>;
        final info = AppUpdateInfo.fromJson(json, currentVersionCode, currentVersion);
        _cachedUpdateInfo = info;
        await repository.setLastUpdateInfoJson(jsonEncode(info.toJson()));
        return info;
      }
    } catch (e) {
      debugPrint('[AppUpdateService] Update check failed non-fatally: $e');
    }
    return _cachedUpdateInfo;
  }

  /// Launch the direct download page in the system browser.
  Future<bool> openDownloadPage([String? customUrl]) async {
    final urlString = customUrl ?? _cachedUpdateInfo?.downloadUrl ?? 'https://stock.yenesuq.com/app/';
    final uri = Uri.tryParse(urlString);
    if (uri == null || uri.scheme != 'https' || uri.host.isEmpty) return false;
    try {
      if (await canLaunchUrl(uri)) {
        return await launchUrl(uri, mode: LaunchMode.externalApplication);
      }
    } catch (e) {
      debugPrint('[AppUpdateService] Failed to launch download URL: $e');
    }
    return false;
  }
}
