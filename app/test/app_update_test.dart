import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:app/database/app_database.dart';
import 'package:app/repositories/ledger_repository.dart';
import 'package:app/services/api_client.dart';
import 'package:app/services/app_update_service.dart';
import 'package:app/services/auth_storage.dart';
import 'package:app/services/sync_engine.dart';
import 'package:app/ui/screens/home_screen.dart';

class MockAuthStorage implements AuthStorage {
  bool online;
  String serverUrl;
  String? deviceToken;
  String? shopName;
  String deviceName;

  MockAuthStorage({
    this.online = true,
    this.serverUrl = 'https://stock.yenesuq.com',
    this.deviceToken = 'token_abc123',
    this.shopName = 'Test Shop',
    this.deviceName = 'Test Device',
  });

  @override
  Future<bool> isOnlineEnabled() async => online;

  @override
  Future<void> setOnlineEnabled(bool enabled) async => online = enabled;

  @override
  Future<String> getServerUrl() async => serverUrl;

  @override
  Future<void> setServerUrl(String url) async => serverUrl = url;

  @override
  Future<String?> getDeviceToken() async => deviceToken;

  @override
  Future<void> saveDeviceToken(String token) async => deviceToken = token;

  @override
  Future<void> clearDeviceToken() async => deviceToken = null;

  @override
  Future<String?> getShopName() async => shopName;

  @override
  Future<void> setShopName(String? name) async => shopName = name;

  @override
  Future<String> getDeviceName() async => deviceName;

  @override
  Future<void> setDeviceName(String name) async => deviceName = name;

  @override
  Future<void> clearAll() async {
    online = false;
    serverUrl = '';
    deviceToken = null;
    shopName = null;
  }
}

void main() {
  late AppDatabase db;
  late LedgerRepository repository;
  late MockAuthStorage authStorage;

  setUp(() async {
    db = AppDatabase.inMemory();
    repository = LedgerRepository(db);
    authStorage = MockAuthStorage();
  });

  tearDown(() async {
    await db.close();
  });

  group('AppUpdateService Unit Tests', () {
    test('AppUpdateInfo correctly detects updates and sync block', () {
      final jsonHigher = {
        'latest': '1.1.0',
        'latest_code': 2,
        'min_supported': '1.0.0',
        'min_supported_code': 1,
        'download_url': 'https://stock.yenesuq.com/app/',
        'notes_en': 'Major performance improvements',
        'notes_am': 'የአፈጻጸም ማሻሻያዎች',
      };

      final info1 = AppUpdateInfo.fromJson(jsonHigher, 1, '1.0.0');
      expect(info1.isUpdateAvailable, isTrue);
      expect(info1.isSyncBlocked, isFalse);
      expect(info1.downloadUrl, 'https://stock.yenesuq.com/app/');

      final jsonSyncBlocked = {
        'latest': '2.0.0',
        'latest_code': 10,
        'min_supported': '2.0.0',
        'min_supported_code': 10,
        'download_url': 'https://stock.yenesuq.com/app/',
      };

      final info2 = AppUpdateInfo.fromJson(jsonSyncBlocked, 1, '1.0.0');
      expect(info2.isUpdateAvailable, isTrue);
      expect(info2.isSyncBlocked, isTrue);

      final jsonSameVersion = {
        'latest': '1.0.0',
        'latest_code': 1,
        'min_supported': '1.0.0',
        'min_supported_code': 1,
      };

      final info3 = AppUpdateInfo.fromJson(jsonSameVersion, 1, '1.0.0');
      expect(info3.isUpdateAvailable, isFalse);
      expect(info3.isSyncBlocked, isFalse);
    });

    test('checkForUpdate fetches from server and enforces 24-hour rate limit', () async {
      int requestCount = 0;
      final mockClient = MockClient((request) async {
        requestCount++;
        expect(request.url.path, '/v1/app/version');
        return http.Response(
          jsonEncode({
            'latest': '1.2.0',
            'latest_code': 5,
            'min_supported': '1.0.0',
            'min_supported_code': 1,
            'download_url': 'https://stock.yenesuq.com/app/',
            'notes_en': 'Bug fixes',
            'notes_am': 'ማስተካከያዎች',
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      });

      final service = AppUpdateService(
        authStorage: authStorage,
        repository: repository,
        httpClient: mockClient,
        currentVersion: '1.0.0',
        currentVersionCode: 1,
      );

      // 1. Initial call should make an HTTP request
      final info1 = await service.checkForUpdate();
      expect(info1, isNotNull);
      expect(info1!.isUpdateAvailable, isTrue);
      expect(info1.latest, '1.2.0');
      expect(requestCount, 1);

      // 2. Immediate second call should hit the 24-hour throttle and NOT make a network request
      final info2 = await service.checkForUpdate();
      expect(info2, isNotNull);
      expect(info2!.latest, '1.2.0');
      expect(requestCount, 1); // Still 1

      // 3. Forced call should bypass the throttle
      final info3 = await service.checkForUpdate(force: true);
      expect(info3, isNotNull);
      expect(requestCount, 2); // Incremented to 2
    });

    test('checkForUpdate fails gracefully offline and on network error without throwing', () async {
      final errorClient = MockClient((request) async {
        throw http.ClientException('Network down');
      });

      final service = AppUpdateService(
        authStorage: authStorage,
        repository: repository,
        httpClient: errorClient,
      );

      // Network exception is caught internally and returns null or cached
      final info = await service.checkForUpdate(force: true);
      expect(info, isNull);

      // When offline, returns cached without network attempt
      authStorage.online = false;
      final offlineInfo = await service.checkForUpdate();
      expect(offlineInfo, isNull);
    });

    test('SyncEngine blocks only online sync when min_supported is higher, keeping offline functional', () async {
      final mockClient = MockClient((request) async {
        return http.Response(
          jsonEncode({
            'latest': '2.0.0',
            'latest_code': 20,
            'min_supported': '2.0.0',
            'min_supported_code': 20,
            'download_url': 'https://stock.yenesuq.com/app/',
          }),
          200,
        );
      });

      final updateService = AppUpdateService(
        authStorage: authStorage,
        repository: repository,
        httpClient: mockClient,
        currentVersion: '1.0.0',
        currentVersionCode: 1,
      );

      final syncEngine = SyncEngine(
        db: db,
        api: ApiClient(),
        storage: authStorage,
        updateService: updateService,
      );

      // Trigger sync
      await syncEngine.triggerSync(reason: 'test_block');

      // Online sync status should be error indicating update is required
      expect(syncEngine.state.status, SyncStatus.error);
      expect(syncEngine.state.errorMessage, contains('Update required'));

      // Crucial: offline database operations remain 100% functional
      await repository.createProduct(
        id: 'test-product-001',
        name: 'Offline Bread',
        sku: 'BRD-01',
        costPriceCents: 1000,
        sellPriceCents: 1500,
      );
      final products = await repository.listProducts();
      expect(products.length, 1);
      expect(products.first.product.name, 'Offline Bread');

      syncEngine.dispose();
    });
  });

  group('HomeScreen Update Banner Widget Tests', () {
    testWidgets('Non-blocking banner appears when update is available and can be dismissed', (tester) async {
      final mockClient = MockClient((request) async {
        return http.Response(
          jsonEncode({
            'latest': '1.5.0',
            'latest_code': 15,
            'min_supported': '1.0.0',
            'min_supported_code': 1,
            'download_url': 'https://stock.yenesuq.com/app/',
            'notes_en': 'Speed improvements and bug fixes',
            'notes_am': 'ፈጣን አሰራር እና ማስተካከያዎች',
          }),
          200,
          headers: {'content-type': 'application/json; charset=utf-8'},
        );
      });

      final updateService = AppUpdateService(
        authStorage: authStorage,
        repository: repository,
        httpClient: mockClient,
        currentVersion: '1.0.0',
        currentVersionCode: 1,
      );

      final syncEngine = SyncEngine(
        db: db,
        api: ApiClient(),
        storage: authStorage,
        updateService: updateService,
      );

      // Pre-seed shop name
      await repository.setShopName('Bole Retail');
      await repository.setLanguage('en');

      await tester.pumpWidget(MaterialApp(
        home: HomeScreen(
          db: db,
          repository: repository,
          apiClient: ApiClient(),
          authStorage: authStorage,
          syncEngine: syncEngine,
          updateService: updateService,
        ),
      ));

      await tester.pumpAndSettle();

      // Banner should be visible on the Home Screen
      expect(find.text('Update Available (v1.5.0)'), findsOneWidget);
      expect(find.text('Speed improvements and bug fixes'), findsOneWidget);
      expect(find.text('Download Update Now'), findsOneWidget);

      // Verify dismissing the banner removes it non-blockingly
      final dismissBtn = find.byTooltip('Dismiss');
      expect(dismissBtn, findsOneWidget);
      await tester.tap(dismissBtn);
      await tester.pumpAndSettle();

      expect(find.text('Update Available (v1.5.0)'), findsNothing);

      await tester.pumpWidget(const SizedBox());
      await tester.pump(const Duration(seconds: 5));
      syncEngine.dispose();
    });
  });
}
