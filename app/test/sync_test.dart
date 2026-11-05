import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:app/database/app_database.dart';
import 'package:app/services/api_client.dart';
import 'package:app/services/auth_storage.dart';
import 'package:app/services/sync_engine.dart';

/// In-memory fake HTTP client simulating the Laravel shared-hosting server.
class FakeServerClient extends http.BaseClient {
  int serverSeq = 0;
  final Map<String, Map<String, dynamic>> serverProducts = {};
  final Map<String, Map<String, dynamic>> serverMovements = {};
  final List<Map<String, dynamic>> serverChanges = [];

  bool dropNextConnection = false;
  bool isRevoked = false;
  int? return429WithSeconds;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final path = request.url.path;

    if (dropNextConnection) {
      dropNextConnection = false;
      throw http.ClientException('Connection drop mid-batch simulated.');
    }

    if (return429WithSeconds != null) {
      final sec = return429WithSeconds!;
      return429WithSeconds = null;
      return http.StreamedResponse(
        Stream.value(utf8.encode(jsonEncode({'error': 'Too Many Requests'}))),
        429,
        headers: {'retry-after': sec.toString(), 'content-type': 'application/json'},
      );
    }

    final authHeader = request.headers['authorization'];
    if (isRevoked || (authHeader != null && authHeader.contains('revoked_token'))) {
      return http.StreamedResponse(
        Stream.value(utf8.encode(jsonEncode({'error': 'Unauthorized'}))),
        401,
        headers: {'content-type': 'application/json'},
      );
    }

    if (path.endsWith('/v1/sync') && request.method == 'POST') {
      final bytes = await request.finalize().toBytes();
      final body = jsonDecode(utf8.decode(bytes)) as Map<String, dynamic>;
      final cursor = body['cursor'] as int? ?? 0;
      final incomingChanges = (body['changes'] as List<dynamic>?) ?? [];

      final accepted = <String>[];
      final rejected = <Map<String, String>>[];

      for (final item in incomingChanges) {
        final id = item['id'] as String;
        final entity = item['entity'] as String;
        final op = item['op'] as String? ?? 'upsert';
        final data = Map<String, dynamic>.from(item['data'] as Map);

        if (entity == 'movement') {
          // Insert or ignore
          if (!serverMovements.containsKey(id)) {
            serverMovements[id] = data;
            serverSeq++;
            serverChanges.add({
              'id': id,
              'entity': 'movement',
              'op': 'upsert',
              'data': data,
              'cursor': serverSeq,
            });
          }
          accepted.add(id);
        } else if (entity == 'product') {
          final existing = serverProducts[id];
          final incomingUpdated = data['updated_at'] as int? ?? 0;
          final existingUpdated = existing?['updated_at'] as int? ?? 0;

          if (existing == null || incomingUpdated >= existingUpdated) {
            serverProducts[id] = data;
            serverSeq++;
            serverChanges.add({
              'id': id,
              'entity': 'product',
              'op': op,
              'data': data,
              'cursor': serverSeq,
            });
          }
          accepted.add(id);
        } else {
          rejected.add({'id': id, 'reason': 'Unknown entity'});
        }
      }

      // Changes for client: changes with seq > cursor
      final outgoing = serverChanges.where((c) => (c['cursor'] as int) > cursor).toList();
      final nextCursor = serverSeq > cursor ? serverSeq : cursor;

      final respJson = {
        'accepted': accepted,
        'rejected': rejected,
        'changes': outgoing,
        'cursor': nextCursor,
      };

      return http.StreamedResponse(
        Stream.value(utf8.encode(jsonEncode(respJson))),
        200,
        headers: {'content-type': 'application/json'},
      );
    }

    if (path.endsWith('/v1/pair/start')) {
      return http.StreamedResponse(
        Stream.value(utf8.encode(jsonEncode({
          'code': '889900',
          'deep_link': 'https://t.me/InventoryEtBot?start=pair_889900',
        }))),
        200,
        headers: {'content-type': 'application/json'},
      );
    }

    if (path.endsWith('/v1/pair/status')) {
      return http.StreamedResponse(
        Stream.value(utf8.encode(jsonEncode({
          'status': 'confirmed',
          'device_token': 'test_bearer_token_xyz',
        }))),
        200,
        headers: {'content-type': 'application/json'},
      );
    }

    if (path.endsWith('/v1/shop') && request.method == 'DELETE') {
      serverProducts.clear();
      serverMovements.clear();
      serverChanges.clear();
      return http.StreamedResponse(
        Stream.value(utf8.encode(jsonEncode({'message': 'Shop deleted'}))),
        200,
        headers: {'content-type': 'application/json'},
      );
    }

    return http.StreamedResponse(
      Stream.value(utf8.encode(jsonEncode({'error': 'Not found'}))),
      404,
      headers: {'content-type': 'application/json'},
    );
  }
}

void main() {
  driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;

  late AppDatabase db;
  late InMemoryAuthStorage storage;
  late FakeServerClient fakeHttp;
  late ApiClient api;
  late SyncEngine engine;

  setUp(() async {
    db = AppDatabase.inMemory();
    storage = InMemoryAuthStorage();
    await storage.saveDeviceToken('valid_token_123');
    await storage.setOnlineEnabled(true);
    await storage.setServerUrl('https://inventory.test');

    fakeHttp = FakeServerClient();
    api = ApiClient(client: fakeHttp);
    engine = SyncEngine(db: db, api: api, storage: storage);
  });

  tearDown(() async {
    engine.dispose();
    await db.close();
  });

  test('Connection drop mid-batch preserves outbox and resumes cleanly without data loss', () async {
    // 1. Create a product and a movement locally
    final p = await db.createProduct(name: 'Sunflower Oil 1L', sellPriceCents: 45000);
    await db.recordMovement(productId: p.id, type: 'in', qtyMilli: 10000);

    expect(await db.countPendingOutbox(), 2);

    // 2. Simulate connection drop mid-batch
    fakeHttp.dropNextConnection = true;
    await engine.triggerSync(reason: 'test_drop');

    expect(engine.state.status, SyncStatus.error);
    // Data is safely retained in outbox
    expect(await db.countPendingOutbox(), 2);

    // 3. Next sync attempt succeeds
    await engine.syncNow();

    expect(engine.state.status, SyncStatus.idle);
    expect(await db.countPendingOutbox(), 0);
    expect(await db.getServerCursor(), greaterThan(0));
  });

  test('Duplicate pushes are idempotent on server and do not corrupt stock', () async {
    final p = await db.createProduct(name: 'Sugar 1kg', sellPriceCents: 12000);
    await db.recordMovement(productId: p.id, type: 'in', qtyMilli: 25000);

    // First push
    await engine.triggerSync();
    expect(await db.countPendingOutbox(), 0);
    expect(fakeHttp.serverMovements.length, 1);
    expect(fakeHttp.serverChanges.length, 2); // 1 product + 1 movement

    // Re-queue the same movement to simulate duplicate push
    await db.into(db.outboxEntries).insert(
      OutboxEntriesCompanion.insert(
        id: 'dup_outbox_id_1',
        entity: 'movement',
        entityId: fakeHttp.serverMovements.keys.first,
        op: 'upsert',
        payloadJson: jsonEncode(fakeHttp.serverMovements.values.first),
        clientTs: 1000,
        createdAt: 1000,
      ),
    );

    expect(await db.countPendingOutbox(), 1);
    await engine.triggerSync();

    expect(await db.countPendingOutbox(), 0);
    // Server movements did not duplicate
    expect(fakeHttp.serverMovements.length, 1);
  });

  test('Two devices sync and converge to identical stock', () async {
    // === DEVICE A ===
    final prod = await db.createProduct(name: 'Ethiopian Coffee', sellPriceCents: 60000);
    await db.recordMovement(productId: prod.id, type: 'in', qtyMilli: 100000); // 100 kg

    await engine.triggerSync();
    expect(await db.getStock(prod.id), 100000);

    // === DEVICE B (Second phone) ===
    final dbB = AppDatabase.inMemory();
    final storageB = InMemoryAuthStorage();
    await storageB.saveDeviceToken('device_b_token');
    await storageB.setOnlineEnabled(true);
    await storageB.setServerUrl('https://inventory.test');

    final apiB = ApiClient(client: fakeHttp);
    final engineB = SyncEngine(db: dbB, api: apiB, storage: storageB);

    // Device B pulls changes from server
    await engineB.triggerSync();

    // Device B now has product and exact stock
    final prodB = await (dbB.select(dbB.products)..where((p) => p.id.equals(prod.id))).getSingle();
    expect(prodB.name, 'Ethiopian Coffee');
    expect(await dbB.getStock(prod.id), 100000);

    // Device B records a sale of 25 kg
    await dbB.recordMovement(productId: prod.id, type: 'sale', qtyMilli: -25000);
    expect(await dbB.getStock(prod.id), 75000);

    // Device B syncs sale to server
    await engineB.triggerSync();

    // Device A syncs and downloads Device B's sale
    await engine.triggerSync();

    // BOTH DEVICES CONVERGE TO EXACTLY 75.000 KG
    expect(await db.getStock(prod.id), 75000);
    expect(await dbB.getStock(prod.id), 75000);

    engineB.dispose();
    await dbB.close();
  });

  test('Revoked token (401) stops periodic sync and prompts re-pairing', () async {
    fakeHttp.isRevoked = true;

    await engine.triggerSync();

    expect(engine.state.status, SyncStatus.unauthorized);
    expect(engine.state.errorMessage, contains('re-pair'));

    // Local data remains completely intact
    final p = await db.createProduct(name: 'Offline Bread', sellPriceCents: 2000);
    expect(p.name, 'Offline Bread');
  });

  test('429 with Retry-After delays subsequent sync triggers', () async {
    fakeHttp.return429WithSeconds = 8;

    await engine.triggerSync();

    expect(engine.state.status, SyncStatus.error);
    expect(engine.state.errorMessage, contains('Pausing for 8s'));

    // Subsequent trigger is skipped because backoff is active
    await engine.triggerSync();
    expect(engine.state.status, SyncStatus.error);
  });

  test('Products follow Last-Write-Wins (LWW) by updated_at', () async {
    final prod = await db.createProduct(name: 'Bar Soap', sellPriceCents: 3000);
    await engine.triggerSync();

    // Server has a newer update from another device (T = 5000000)
    final newerTime = DateTime.now().millisecondsSinceEpoch + 50000;
    fakeHttp.serverChanges.add({
      'id': prod.id,
      'entity': 'product',
      'op': 'upsert',
      'data': {
        'id': prod.id,
        'name': 'Bar Soap (Brand New)',
        'sell_price_cents': 3500,
        'updated_at': newerTime,
      },
      'cursor': fakeHttp.serverSeq + 1,
    });
    fakeHttp.serverSeq++;

    await engine.triggerSync();

    final updated = await (db.select(db.products)..where((p) => p.id.equals(prod.id))).getSingle();
    expect(updated.name, 'Bar Soap (Brand New)');
    expect(updated.sellPriceCents, 3500);
  });

  test('Merged negative stock keeps data and flags check stock badge', () async {
    final prod = await db.createProduct(name: 'Eggs (እንቁላል)');
    // Oversold by 5 units without intake
    await db.recordMovement(productId: prod.id, type: 'sale', qtyMilli: -5000);

    final stock = await db.getStock(prod.id);
    expect(stock, -5000);
    expect(await db.hasNegativeStock(), true);

    await engine.triggerSync();
    expect(engine.state.hasNegativeStock, true);
  });

  test('Months of offline history seeded and uploaded during initial sync', () async {
    // Simulate shop running offline for months with 10 products and 10 movements
    for (int i = 1; i <= 10; i++) {
      await db.into(db.products).insert(
        Product(
          id: 'prod_offline_$i',
          name: 'Offline Item $i',
          unit: 'pcs',
          kind: 'stockable',
          costPriceCents: 1000 * i,
          sellPriceCents: 1500 * i,
          askPrice: false,
          isActive: true,
          updatedAtMs: 1000 * i,
        ),
      );

      await db.into(db.movements).insert(
        Movement(
          id: 'mov_offline_$i',
          productId: 'prod_offline_$i',
          type: 'in',
          qtyMilli: 20000,
          unitPriceCents: 1000 * i,
          tracksStock: true,
          createdAtMs: 1000 * i,
        ),
      );
    }

    // Outbox was completely empty
    expect(await db.countPendingOutbox(), 0);

    int progressCalls = 0;
    await engine.runInitialHistorySync(
      onProgress: (cur, tot) {
        progressCalls++;
      },
    );

    expect(progressCalls, greaterThan(0));
    expect(await db.countPendingOutbox(), 0);
    // All 10 products and 10 movements uploaded to server
    expect(fakeHttp.serverProducts.length, 10);
    expect(fakeHttp.serverMovements.length, 10);
  });
}
