// ignore_for_file: depend_on_referenced_packages
import 'dart:convert';

import 'package:drift/drift.dart' hide isNotNull, isNull;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:sqlite3/sqlite3.dart' as sqlite;

import 'package:app/database/app_database.dart';
import 'package:app/repositories/ledger_repository.dart';
import 'package:app/services/api_client.dart';
import 'package:app/services/auth_storage.dart';
import 'package:app/services/sync_engine.dart';

class MockSyncHttpClient extends http.BaseClient {
  final List<Map<String, dynamic>> changes;
  MockSyncHttpClient(this.changes);

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final resp = {
      'accepted': <String>[],
      'rejected': <Map<String, String>>[],
      'changes': changes,
      'cursor': changes.length,
    };
    return http.StreamedResponse(
      Stream.value(utf8.encode(jsonEncode(resp))),
      200,
      headers: {'content-type': 'application/json'},
    );
  }
}

void main() {
  driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;
  TestWidgetsFlutterBinding.ensureInitialized();

  late AppDatabase db;
  late LedgerRepository repo;

  setUp(() async {
    db = AppDatabase.inMemory();
    repo = LedgerRepository(db);
  });

  tearDown(() async {
    await db.close();
  });

  group('1. Service Sale: Leaves Stock Untouched & Counts as Revenue', () {
    test('service sale writes tracks_stock=false and leaves stock at 0', () async {
      final service = await repo.createProduct(
        name: 'Haircut (የፀጉር ቁረጥ)',
        unit: 'service',
        kind: 'service',
        sellPriceCents: 15000, // 150 ETB
        costPriceCents: 2000,  // 20 ETB optional cost
      );

      expect(service.kind, equals('service'));
      expect(service.askPrice, isFalse);

      // Stock of service starts at 0
      final initialStock = await repo.stockOf(service.id);
      expect(initialStock, equals(0));

      // Record a sale of the service
      final sale = await repo.recordSaleWithPayment(
        items: [
          SaleLineItem(product: service, qtyMilli: 1000, unitPriceCents: 15000),
        ],
        paymentMethod: 'cash',
        paidAmountCents: 15000,
      );

      expect(sale.totalCents, equals(15000));
      final movements = await (db.select(db.movements)..where((m) => m.saleId.equals(sale.id))).get();
      expect(movements.length, equals(1));
      final mov = movements.first;
      expect(mov.type, equals('sale'));
      expect(mov.tracksStock, isFalse);
      expect(mov.qtyMilli, equals(-1000));

      // Stock remains exactly 0!
      final stockAfter = await repo.stockOf(service.id);
      expect(stockAfter, equals(0));

      // Revenue in sales report includes the service
      final nowMs = DateTime.now().millisecondsSinceEpoch;
      final salesReport = await repo.getSalesReport(fromMs: 0, toMs: nowMs + 100000);
      expect(salesReport.totalRevenueCents, equals(15000));
      expect(salesReport.salesCount, equals(1));
      expect(salesReport.estimatedProfitCents, equals(13000)); // 150 - 20 = 130 ETB

      // Stock valuation excludes the service
      final stockReport = await repo.getStockReport();
      expect(stockReport.totalProductsCount, equals(0));
      expect(stockReport.totalValueCostCents, equals(0));
    });
  });

  group('2. Consumable Deduction Math & Half-up Rounding', () {
    test('consumable sale creates sale movement (tracks_stock=false) and consume movements per material', () async {
      // 1. Create raw materials
      final coffee = await repo.createProduct(
        name: 'Coffee Beans',
        unit: 'kg',
        costPriceCents: 50000, // 500 ETB / kg
        sellPriceCents: 60000,
        openingStockMilli: 1000000, // 1000 kg (1000000 milli)
      );

      final milk = await repo.createProduct(
        name: 'Milk',
        unit: 'lt',
        costPriceCents: 8000, // 80 ETB / lt
        sellPriceCents: 10000,
        openingStockMilli: 500000, // 500 lt
      );

      // 2. Create consumable Macchiato
      // Recipe: 0.018 kg Coffee (18000 milli) + 0.125 lt Milk (125000 milli) per cup
      final macchiato = await repo.createProduct(
        name: 'Macchiato',
        unit: 'cup',
        kind: 'consumable',
        sellPriceCents: 4500, // 45 ETB
        materials: [
          {'product_id': coffee.id, 'qty_milli': 18000},
          {'product_id': milk.id, 'qty_milli': 125000},
        ],
      );

      expect(macchiato.kind, equals('consumable'));

      final mats = await repo.getProductMaterials(macchiato.id);
      expect(mats.length, equals(2));

      // 3. Sell 3 cups of Macchiato
      final sale = await repo.recordSaleWithPayment(
        items: [
          SaleLineItem(product: macchiato, qtyMilli: 3000, unitPriceCents: 4500),
        ],
        paymentMethod: 'cash',
        paidAmountCents: 13500,
      );

      final movements = await (db.select(db.movements)..where((m) => m.saleId.equals(sale.id))).get();

      // Sale line movement (tracks_stock = false)
      final saleMov = movements.firstWhere((m) => m.productId == macchiato.id && m.type == 'sale');
      expect(saleMov.tracksStock, isFalse);
      expect(saleMov.qtyMilli, equals(-3000));

      // Consume movements for materials
      final consumeMovs = movements.where((m) => m.type == 'consume').toList();
      expect(consumeMovs.length, equals(2));

      final coffeeConsume = consumeMovs.firstWhere((m) => m.productId == coffee.id);
      expect(coffeeConsume.tracksStock, isTrue);
      expect(coffeeConsume.qtyMilli, equals(-54000)); // -(18000 * 3000 / 1000) = -54000
      expect(coffeeConsume.sourceMovementId, equals(saleMov.id));

      final milkConsume = consumeMovs.firstWhere((m) => m.productId == milk.id);
      expect(milkConsume.tracksStock, isTrue);
      expect(milkConsume.qtyMilli, equals(-375000)); // -(125000 * 3000 / 1000) = -375000
      expect(milkConsume.sourceMovementId, equals(saleMov.id));

      // Verify material stocks deducted
      expect(await repo.stockOf(coffee.id), equals(1000000 - 54000));
      expect(await repo.stockOf(milk.id), equals(500000 - 375000));
      // Consumable itself has 0 stock
      expect(await repo.stockOf(macchiato.id), equals(0));
    });

    test('half-up rounding to thousandths on fractional material consumption', () async {
      final raw = await repo.createProduct(
        name: 'Flour',
        unit: 'kg',
        openingStockMilli: 100000,
      );

      // Consumable uses 1235 milli per unit
      final cake = await repo.createProduct(
        name: 'Cake Slice',
        unit: 'pcs',
        kind: 'consumable',
        sellPriceCents: 3000,
        materials: [
          {'product_id': raw.id, 'qty_milli': 1235},
        ],
      );

      // Sell 1.5 units (1500 milli)
      // Math: (1235 * 1500 + 500) ~/ 1000 = (1852500 + 500) ~/ 1000 = 1853 milli
      final sale = await repo.recordSaleWithPayment(
        items: [
          SaleLineItem(product: cake, qtyMilli: 1500, unitPriceCents: 3000),
        ],
        paymentMethod: 'cash',
        paidAmountCents: 4500,
      );

      final movements = await (db.select(db.movements)..where((m) => m.saleId.equals(sale.id))).get();
      final consumeMov = movements.firstWhere((m) => m.type == 'consume');
      expect(consumeMov.qtyMilli, equals(-1853));
      expect(await repo.stockOf(raw.id), equals(100000 - 1853));
    });
  });

  group('3. Consumable Shortage vs Block Setting', () {
    test('shortage warning allows sale when block_sale_when_material_short is false', () async {
      await repo.setBlockSaleWhenMaterialShort(false);

      final sugar = await repo.createProduct(
        name: 'Sugar',
        unit: 'kg',
        openingStockMilli: 10000, // 10 kg
      );

      final tea = await repo.createProduct(
        name: 'Sweet Tea',
        unit: 'cup',
        kind: 'consumable',
        sellPriceCents: 1500,
        materials: [
          {'product_id': sugar.id, 'qty_milli': 5000}, // 5 kg per cup
        ],
      );

      // Sell 3 cups -> needs 15 kg, but only 10 kg in stock
      final sale = await repo.recordSaleWithPayment(
        items: [
          SaleLineItem(product: tea, qtyMilli: 3000, unitPriceCents: 1500),
        ],
        paymentMethod: 'cash',
        paidAmountCents: 4500,
      );

      expect(sale.totalCents, equals(4500));
      // Sugar stock goes negative into -5000 milli (-5 kg)
      expect(await repo.stockOf(sugar.id), equals(-5000));
    });

    test('blocks sale when block_sale_when_material_short is true and raw material is short', () async {
      await repo.setBlockSaleWhenMaterialShort(true);

      final sugar = await repo.createProduct(
        name: 'Sugar',
        unit: 'kg',
        openingStockMilli: 10000, // 10 kg
      );

      final tea = await repo.createProduct(
        name: 'Sweet Tea',
        unit: 'cup',
        kind: 'consumable',
        sellPriceCents: 1500,
        materials: [
          {'product_id': sugar.id, 'qty_milli': 5000}, // 5 kg per cup
        ],
      );

      // Sell 3 cups -> needs 15 kg, but only 10 kg in stock
      expect(
        () => repo.recordSaleWithPayment(
          items: [
            SaleLineItem(product: tea, qtyMilli: 3000, unitPriceCents: 1500),
          ],
          paymentMethod: 'cash',
          paidAmountCents: 4500,
        ),
        throwsA(isA<InsufficientStockException>()),
      );
    });
  });

  group('4. Kind-Change Rules', () {
    test('stockable product with stock > 0 cannot change kind', () async {
      final prod = await repo.createProduct(
        name: 'Soap',
        openingStockMilli: 50000, // 50 pcs in stock
      );

      expect(
        () => repo.validateProductKindChange(prod, 'service'),
        throwsA(isA<StateError>().having((e) => e.message, 'message', contains('stock is not zero'))),
      );
      expect(
        () => repo.validateProductKindChange(prod, 'consumable'),
        throwsA(isA<StateError>().having((e) => e.message, 'message', contains('stock is not zero'))),
      );
    });

    test('stockable product with 0 stock can change kind and sets kind_changed_at_ms', () async {
      final prod = await repo.createProduct(
        name: 'Consultation',
        openingStockMilli: 0,
      );

      await repo.validateProductKindChange(prod, 'service');

      final updated = prod.copyWith(kind: 'service');
      await repo.updateProduct(updated);

      final fetched = await repo.getProduct(prod.id);
      expect(fetched?.kind, equals('service'));
      expect(fetched?.kindChangedAtMs, isNotNull);
    });

    test('other kind to product allowed and starts at zero stock', () async {
      final s = await repo.createProduct(
        name: 'Massage',
        kind: 'service',
        sellPriceCents: 30000,
      );

      await repo.validateProductKindChange(s, 'stockable');
      final updated = s.copyWith(kind: 'stockable');
      await repo.updateProduct(updated);

      final fetched = await repo.getProduct(s.id);
      expect(fetched?.kind, equals('stockable'));
      expect(await repo.stockOf(s.id), equals(0));
    });
  });

  group('5. Material Protection (isUsedAsMaterial)', () {
    test('product used as raw material cannot be deleted or re-typed', () async {
      final flour = await repo.createProduct(name: 'Flour');
      final bread = await repo.createProduct(
        name: 'Bread',
        kind: 'consumable',
        materials: [
          {'product_id': flour.id, 'qty_milli': 500},
        ],
      );

      expect(await repo.isUsedAsMaterial(flour.id), isTrue);

      // Cannot delete flour
      expect(
        () => repo.deleteProduct(flour.id),
        throwsA(isA<StateError>().having((e) => e.message, 'message', contains('used as a raw material'))),
      );

      // Cannot change kind of flour
      expect(
        () => repo.validateProductKindChange(flour, 'service'),
        throwsA(isA<StateError>().having((e) => e.message, 'message', contains('used as a raw material'))),
      );

      // Remove flour from bread recipe
      await repo.updateProduct(bread, materials: []);
      expect(await repo.isUsedAsMaterial(flour.id), isFalse);

      // Now deleting is permitted (soft delete sets deletedAtMs)
      await repo.deleteProduct(flour.id);
      final deleted = await repo.getProduct(flour.id);
      expect(deleted?.deletedAtMs, isNotNull);
    });
  });

  group('6. Undo Sale: Reverses Consume Movements', () {
    test('undoing a consumable sale restores raw material stock', () async {
      final beans = await repo.createProduct(
        name: 'Coffee Beans',
        openingStockMilli: 100000, // 100g
      );

      final espresso = await repo.createProduct(
        name: 'Espresso',
        kind: 'consumable',
        sellPriceCents: 3000,
        materials: [
          {'product_id': beans.id, 'qty_milli': 18000}, // 18g
        ],
      );

      // Make sale
      final sale = await repo.recordSaleWithPayment(
        items: [
          SaleLineItem(product: espresso, qtyMilli: 2000, unitPriceCents: 3000), // 2 cups
        ],
        paymentMethod: 'cash',
        paidAmountCents: 6000,
      );

      expect(await repo.stockOf(beans.id), equals(100000 - 36000));

      // Undo within window
      await repo.undoSale(sale.id);
      final voidedSale = await (db.select(db.sales)..where((s) => s.id.equals(sale.id))).getSingleOrNull();
      expect(voidedSale?.voidedAtMs, isNotNull);

      // Raw material stock is restored!
      expect(await repo.stockOf(beans.id), equals(100000));

      // Check movements: compensating positive entries created with tracks_stock=true
      final movs = await repo.listMovements(productId: beans.id);
      final lastMov = movs.first;
      expect(lastMov.movement.qtyMilli, equals(36000));
      expect(lastMov.movement.tracksStock, isTrue);
    });
  });

  group('7. Drift Migration from v3 to v4', () {
    test('migrates existing database to schema 4 with default values and tables', () async {
      final rawDb = sqlite.sqlite3.openInMemory();

      // Create v3 schema
      rawDb.execute('''
        CREATE TABLE products (
          id TEXT NOT NULL PRIMARY KEY,
          name TEXT NOT NULL,
          sku TEXT,
          unit TEXT NOT NULL DEFAULT 'pcs',
          cost_price_cents INTEGER NOT NULL DEFAULT 0,
          sell_price_cents INTEGER NOT NULL DEFAULT 0,
          low_stock_at_milli INTEGER,
          is_active INTEGER NOT NULL DEFAULT 1,
          updated_at_ms INTEGER NOT NULL,
          deleted_at_ms INTEGER
        );
      ''');
      rawDb.execute('''
        CREATE TABLE movements (
          id TEXT NOT NULL PRIMARY KEY,
          product_id TEXT NOT NULL,
          type TEXT NOT NULL,
          qty_milli INTEGER NOT NULL,
          unit_price_cents INTEGER NOT NULL DEFAULT 0,
          location_id TEXT NOT NULL DEFAULT 'main-loc',
          transfer_id TEXT,
          sale_id TEXT,
          note TEXT,
          created_at_ms INTEGER NOT NULL
        );
      ''');
      rawDb.execute('''
        CREATE TABLE outbox_entries (
          id TEXT NOT NULL PRIMARY KEY,
          entity TEXT NOT NULL,
          entity_id TEXT NOT NULL,
          op TEXT NOT NULL,
          payload_json TEXT NOT NULL,
          client_ts INTEGER NOT NULL,
          status TEXT NOT NULL DEFAULT 'pending',
          retry_count INTEGER NOT NULL DEFAULT 0,
          error_reason TEXT,
          created_at INTEGER NOT NULL
        );
      ''');
      rawDb.execute('''
        CREATE TABLE app_settings (
          key TEXT NOT NULL PRIMARY KEY,
          value TEXT NOT NULL
        );
      ''');
      rawDb.execute('''
        CREATE TABLE locations (
          id TEXT NOT NULL PRIMARY KEY,
          name TEXT NOT NULL,
          kind TEXT NOT NULL DEFAULT 'store',
          is_default INTEGER NOT NULL DEFAULT 0,
          is_active INTEGER NOT NULL DEFAULT 1,
          updated_at_ms INTEGER NOT NULL,
          deleted_at_ms INTEGER
        );
      ''');
      rawDb.execute('''
        CREATE TABLE sales (
          id TEXT NOT NULL PRIMARY KEY,
          location_id TEXT NOT NULL,
          customer_id TEXT,
          total_cents INTEGER NOT NULL,
          due_date TEXT,
          note TEXT,
          is_legacy INTEGER NOT NULL DEFAULT 0,
          voided_at_ms INTEGER,
          created_at_ms INTEGER NOT NULL,
          updated_at_ms INTEGER NOT NULL
        );
      ''');
      rawDb.execute('''
        CREATE TABLE payments (
          id TEXT NOT NULL PRIMARY KEY,
          sale_id TEXT,
          customer_id TEXT,
          location_id TEXT NOT NULL,
          method TEXT NOT NULL,
          provider_key TEXT,
          amount_cents INTEGER NOT NULL,
          reference TEXT,
          received_at_ms INTEGER NOT NULL,
          created_at_ms INTEGER NOT NULL
        );
      ''');
      rawDb.execute('''
        CREATE TABLE customers (
          id TEXT NOT NULL PRIMARY KEY,
          name TEXT NOT NULL,
          phone TEXT,
          note TEXT,
          created_at_ms INTEGER NOT NULL,
          updated_at_ms INTEGER NOT NULL,
          deleted_at_ms INTEGER
        );
      ''');
      rawDb.execute('''
        CREATE TABLE payment_providers (
          provider_key TEXT NOT NULL PRIMARY KEY,
          kind TEXT NOT NULL,
          name TEXT,
          enabled INTEGER NOT NULL DEFAULT 1,
          favorite INTEGER NOT NULL DEFAULT 0,
          updated_at_ms INTEGER NOT NULL
        );
      ''');

      // Populate sample product and movements
      rawDb.execute('''
        INSERT INTO products (id, name, unit, cost_price_cents, sell_price_cents, updated_at_ms)
        VALUES ('p1', 'Sugar', 'kg', 5000, 7000, 1700000000000);
      ''');
      rawDb.execute('''
        INSERT INTO movements (id, product_id, type, qty_milli, unit_price_cents, created_at_ms)
        VALUES ('m1', 'p1', 'in', 50000, 5000, 1700000001000);
      ''');

      // Set user_version to 3
      rawDb.execute('PRAGMA user_version = 3;');

      // Open with AppDatabase
      final migratedDb = AppDatabase(NativeDatabase.opened(rawDb));
      final migratedRepo = LedgerRepository(migratedDb);

      // Verify product defaults
      final p = await migratedRepo.getProduct('p1');
      expect(p, isNotNull);
      expect(p!.kind, equals('stockable'));
      expect(p.askPrice, isFalse);

      // Verify movement defaults
      final m = await (migratedDb.select(migratedDb.movements)..where((tbl) => tbl.id.equals('m1'))).getSingle();
      expect(m.tracksStock, isTrue);
      expect(m.sourceMovementId, isNull);

      // Verify product_materials table exists and query works
      final mats = await migratedRepo.getProductMaterials('p1');
      expect(mats, isEmpty);

      // Verify default block_sale_when_material_short is false
      expect(await migratedRepo.getBlockSaleWhenMaterialShort(), isFalse);

      await migratedDb.close();
    });
  });

  group('8. Outbox Payload Shapes for Product & Movement', () {
    test('embeds kind, ask_price, and materials in product outbox payload', () async {
      final sugar = await repo.createProduct(name: 'Sugar');
      final tea = await repo.createProduct(
        name: 'Tea',
        kind: 'consumable',
        askPrice: false,
        materials: [
          {'product_id': sugar.id, 'qty_milli': 5000}, // 5.000 kg
        ],
      );

      final outboxRows = await (db.select(db.outboxEntries)..where((o) => o.entityId.equals(tea.id))).get();
      expect(outboxRows.isNotEmpty, isTrue);

      final payload = jsonDecode(outboxRows.first.payloadJson) as Map<String, dynamic>;
      expect(payload['kind'], equals('consumable'));
      expect(payload['ask_price'], isFalse);
      expect(payload['materials'], isNotNull);
      final wireMats = payload['materials'] as List;
      expect(wireMats.length, equals(1));
      expect(wireMats.first['product_id'], equals(sugar.id));
      expect(wireMats.first['qty'], equals('5.000'));
    });

    test('embeds tracks_stock and source_movement_id in movement outbox payload', () async {
      final service = await repo.createProduct(
        name: 'Repair',
        kind: 'service',
        sellPriceCents: 5000,
      );

      final sale = await repo.recordSaleWithPayment(
        items: [
          SaleLineItem(product: service, qtyMilli: 1000, unitPriceCents: 5000),
        ],
        paymentMethod: 'cash',
        paidAmountCents: 5000,
      );

      final movements = await (db.select(db.movements)..where((m) => m.saleId.equals(sale.id))).get();
      final movId = movements.first.id;
      final outboxRows = await (db.select(db.outboxEntries)..where((o) => o.entityId.equals(movId))).get();
      expect(outboxRows.isNotEmpty, isTrue);

      final payload = jsonDecode(outboxRows.first.payloadJson) as Map<String, dynamic>;
      expect(payload['tracks_stock'], isFalse);
      expect(payload.containsKey('source_movement_id'), isTrue);
    });
  });

  group('9. Reports by Kind & Materials Used', () {
    test('getSalesByKind and getMaterialsUsed report accurately', () async {
      // 1 product, 1 service, 1 consumable
      final stockable = await repo.createProduct(name: 'Biscuits', sellPriceCents: 2000, openingStockMilli: 50000);
      final service = await repo.createProduct(name: 'Delivery', kind: 'service', sellPriceCents: 3000);
      final raw = await repo.createProduct(name: 'Juice Concentrate', costPriceCents: 1000, openingStockMilli: 50000);
      final consumable = await repo.createProduct(
        name: 'Juice',
        kind: 'consumable',
        sellPriceCents: 4000,
        materials: [
          {'product_id': raw.id, 'qty_milli': 2000},
        ],
      );

      // Sell all 3
      await repo.recordSaleWithPayment(
        items: [
          SaleLineItem(product: stockable, qtyMilli: 2000, unitPriceCents: 2000),
          SaleLineItem(product: service, qtyMilli: 1000, unitPriceCents: 3000),
          SaleLineItem(product: consumable, qtyMilli: 3000, unitPriceCents: 4000),
        ],
        paymentMethod: 'cash',
        paidAmountCents: 19000, // 4000 + 3000 + 12000 = 19000 cents
      );

      final nowMs = DateTime.now().millisecondsSinceEpoch;
      final salesByKind = await repo.getSalesByKind(fromMs: 0, toMs: nowMs + 100000);
      expect(salesByKind['stockable']?.totalRevenueCents, equals(4000));
      expect(salesByKind['stockable']?.salesCount, equals(1));

      expect(salesByKind['service']?.totalRevenueCents, equals(3000));
      expect(salesByKind['service']?.salesCount, equals(1));

      expect(salesByKind['consumable']?.totalRevenueCents, equals(12000));
      expect(salesByKind['consumable']?.salesCount, equals(1));

      final matsUsed = await repo.getMaterialsUsed(fromMs: 0, toMs: nowMs + 100000);
      expect(matsUsed.length, equals(1));
      expect(matsUsed.first.productName, equals('Juice Concentrate'));
      expect(matsUsed.first.totalConsumedMilli, equals(6000)); // 2000 * 3 = 6000
      expect(matsUsed.first.totalCostCents, equals(6000)); // 6 units * 1000 cents = 6000 cents

      // Stock valuation report excludes service and consumable
      final stockReport = await repo.getStockReport();
      expect(stockReport.totalProductsCount, equals(2)); // Biscuits and Juice Concentrate
    });
  });

  group('10. Sync Engine Ingestion with Wire Defaults', () {
    test('applies incoming product and movement changes with wire defaults', () async {
      final storage = InMemoryAuthStorage();
      await storage.saveDeviceToken('device_token_xyz');
      await storage.setOnlineEnabled(true);
      await storage.setServerUrl('https://inventory.test');

      final fakeChanges = [
        {
          'cursor': 1,
          'seq': 1,
          'entity': 'product',
          'entity_id': 'remote-prod-1',
          'op': 'upsert',
          'data': {
            'id': 'remote-prod-1',
            'name': 'Imported Product',
            'unit': 'pcs',
            'cost_price_cents': 1000,
            'sell_price_cents': 2000,
            'updated_at': 1700000000000,
          },
        },
        {
          'cursor': 2,
          'seq': 2,
          'entity': 'product',
          'entity_id': 'remote-cons-1',
          'op': 'upsert',
          'data': {
            'id': 'remote-cons-1',
            'name': 'Imported Consumable',
            'unit': 'pcs',
            'kind': 'consumable',
            'sell_price_cents': 5000,
            'materials': [
              {'product_id': 'remote-prod-1', 'qty': '0.250'},
            ],
            'updated_at': 1700000001000,
          },
        },
        {
          'cursor': 3,
          'seq': 3,
          'entity': 'movement',
          'entity_id': 'remote-mov-1',
          'op': 'upsert',
          'data': {
            'id': 'remote-mov-1',
            'product_id': 'remote-prod-1',
            'type': 'consume',
            'qty': '-0.250',
            'tracks_stock': true,
            'source_movement_id': 'some-sale-mov',
            'created_at': 1700000002000,
          },
        },
      ];

      final syncEngine = SyncEngine(
        api: ApiClient(client: MockSyncHttpClient(fakeChanges)),
        storage: storage,
        db: db,
      );

      await syncEngine.triggerSync();

      final p = await repo.getProduct('remote-prod-1');
      expect(p, isNotNull);
      expect(p!.kind, equals('stockable'));
      expect(p.askPrice, isFalse);

      final cons = await repo.getProduct('remote-cons-1');
      expect(cons?.kind, equals('consumable'));
      final mats = await repo.getProductMaterials('remote-cons-1');
      expect(mats.length, equals(1));
      expect(mats.first.materialProductId, equals('remote-prod-1'));
      expect(mats.first.qtyMilli, equals(250));

      final mov = await (db.select(db.movements)..where((m) => m.id.equals('remote-mov-1'))).getSingle();
      expect(mov.type, equals('consume'));
      expect(mov.tracksStock, isTrue);
      expect(mov.sourceMovementId, equals('some-sale-mov'));
      expect(mov.qtyMilli, equals(-250));
    });
  });
}
