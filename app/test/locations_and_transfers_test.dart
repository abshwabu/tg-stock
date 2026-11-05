// ignore_for_file: depend_on_referenced_packages
import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:drift/drift.dart' hide isNotNull, isNull;
import 'package:drift/native.dart';
import 'package:sqlite3/sqlite3.dart' as sqlite;

import 'package:app/database/app_database.dart';
import 'package:app/repositories/ledger_repository.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;

  group('1. Drift Migration on Populated Database', () {
    test('upgrades schema from version 1 to 2 and backfills existing movements to Main', () async {
      // 1. Set up a raw in-memory SQLite database simulating schema v1 (populated before migration 14)
      final rawDb = sqlite.sqlite3.openInMemory();
      rawDb.execute('PRAGMA user_version = 1;');
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
          created_at_ms INTEGER NOT NULL,
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

      // Seed pre-existing product and movements (no location_id or transfer_id)
      rawDb.execute('''
        INSERT INTO products (id, name, unit, cost_price_cents, sell_price_cents, created_at_ms, updated_at_ms)
        VALUES ('prod-001', 'Teff (ጤፍ)', 'kg', 10000, 15000, 1700000000000, 1700000000000);
      ''');
      rawDb.execute('''
        INSERT INTO movements (id, product_id, type, qty_milli, unit_price_cents, note, created_at_ms)
        VALUES 
          ('mov-001', 'prod-001', 'in', 50000, 10000, 'Opening stock', 1700000001000),
          ('mov-002', 'prod-001', 'sale', -15000, 15000, 'Morning customer', 1700000002000);
      ''');

      // 2. Open this raw database through AppDatabase (Drift) so onUpgrade runs from 1 to 2
      final appDb = AppDatabase(NativeDatabase.opened(rawDb));
      final repo = LedgerRepository(appDb);

      // Verify default location 'Main' was created (triggers Drift migration)
      final locations = await repo.listLocations();
      expect(locations.length, equals(1));
      final mainLoc = locations.first;
      expect(mainLoc.name, equals('Main'));
      expect(mainLoc.kind, equals('store'));
      expect(mainLoc.isDefault, isTrue);

      // Verify schema version updated to current schema (>= 2)
      final verRow = rawDb.select('PRAGMA user_version;');
      expect(verRow.first.values.first, greaterThanOrEqualTo(2));

      // Verify existing movements were backfilled to 'Main'
      final movements = await appDb.select(appDb.movements).get();
      expect(movements.length, equals(2));
      for (final m in movements) {
        expect(m.locationId, equals(mainLoc.id));
      }

      // Verify stock calculation works correctly on the backfilled location
      final mainStock = await repo.stockOf('prod-001', locationId: mainLoc.id);
      expect(mainStock, equals(35000)); // 50000 - 15000

      // Settings default flags
      expect(await repo.getLocationsEnabled(), isFalse);
      expect(await repo.getTransferRequiresReceipt(), isFalse);
      expect(await repo.getLocationsPromptDismissed(), isFalse);

      await appDb.close();
    });
  });

  group('2. First-Run Setup Answer Paths', () {
    late AppDatabase db;
    late LedgerRepository repo;

    setUp(() {
      db = AppDatabase.inMemory();
      repo = LedgerRepository(db);
    });

    tearDown(() => db.close());

    test('Path "No, one place": keeps locations disabled and dismisses prompt', () async {
      await repo.setShopName('Bole Shop');
      await repo.setLocationsEnabled(false);
      await repo.setLocationsPromptDismissed(true);
      await repo.setFirstRunCompleted(true);

      expect(await repo.getLocationsEnabled(), isFalse);
      expect(await repo.getLocationsPromptDismissed(), isTrue);
      expect(await repo.isFirstRunCompleted(), isTrue);

      // Default single location remains intact
      final defaultLoc = await repo.getDefaultLocation();
      expect(defaultLoc.name, equals('Main'));
    });

    test('Path "Yes, warehouse and store": creates Store and Warehouse, reassigns stock', () async {
      // Create initial product and stock before opting in
      final p = await repo.createProduct(name: 'Wheat', openingStockMilli: 20000);

      // Opt in with 'store' placement
      await repo.enableWarehouseAndStore(stockPlacement: 'store');
      await repo.setFirstRunCompleted(true);

      expect(await repo.getLocationsEnabled(), isTrue);
      expect(await repo.getLocationsPromptDismissed(), isTrue);

      final locs = await repo.listLocations();
      expect(locs.any((l) => l.name == 'Store'), isTrue);
      expect(locs.any((l) => l.name == 'Warehouse'), isTrue);

      final store = locs.firstWhere((l) => l.name == 'Store');
      final wh = locs.firstWhere((l) => l.name == 'Warehouse');

      // Stock was reassigned to Store
      expect(await repo.stockOf(p.id, locationId: store.id), equals(20000));
      expect(await repo.stockOf(p.id, locationId: wh.id), equals(0));
    });
  });

  group('3. Home Card Dismissal & Settings Persistence', () {
    late AppDatabase db;
    late LedgerRepository repo;

    setUp(() {
      db = AppDatabase.inMemory();
      repo = LedgerRepository(db);
    });

    tearDown(() => db.close());

    test('dismissing home card persists locations_prompt_dismissed = true', () async {
      expect(await repo.getLocationsPromptDismissed(), isFalse);

      await repo.setLocationsPromptDismissed(true);
      expect(await repo.getLocationsPromptDismissed(), isTrue);
    });

    test('can toggle transfer_requires_receipt setting', () async {
      expect(await repo.getTransferRequiresReceipt(), isFalse);

      await repo.setTransferRequiresReceipt(true);
      expect(await repo.getTransferRequiresReceipt(), isTrue);

      await repo.setTransferRequiresReceipt(false);
      expect(await repo.getTransferRequiresReceipt(), isFalse);
    });
  });

  group('4. Reassigning Existing Stock Placement', () {
    late AppDatabase db;
    late LedgerRepository repo;

    setUp(() {
      db = AppDatabase.inMemory();
      repo = LedgerRepository(db);
    });

    tearDown(() => db.close());

    test('stockPlacement: "warehouse" puts all existing movements into Warehouse', () async {
      final p = await repo.createProduct(name: 'Barley', openingStockMilli: 40000);

      await repo.enableWarehouseAndStore(stockPlacement: 'warehouse');

      final locs = await repo.listLocations();
      final store = locs.firstWhere((l) => l.name == 'Store');
      final wh = locs.firstWhere((l) => l.name == 'Warehouse');

      expect(await repo.stockOf(p.id, locationId: wh.id), equals(40000));
      expect(await repo.stockOf(p.id, locationId: store.id), equals(0));
    });

    test('stockPlacement: "split_later" puts everything in Store for now', () async {
      final p = await repo.createProduct(name: 'Corn', openingStockMilli: 15000);

      await repo.enableWarehouseAndStore(stockPlacement: 'split_later');

      final locs = await repo.listLocations();
      final store = locs.firstWhere((l) => l.name == 'Store');
      final wh = locs.firstWhere((l) => l.name == 'Warehouse');

      expect(await repo.stockOf(p.id, locationId: store.id), equals(15000));
      expect(await repo.stockOf(p.id, locationId: wh.id), equals(0));
    });
  });

  group('5. Instant Transfers', () {
    late AppDatabase db;
    late LedgerRepository repo;

    setUp(() async {
      db = AppDatabase.inMemory();
      repo = LedgerRepository(db);
      await repo.enableWarehouseAndStore(stockPlacement: 'store');
    });

    tearDown(() => db.close());

    test('instant transfer moves stock immediately in one transaction with identical transfer_id', () async {
      final p = await repo.createProduct(name: 'Cooking Oil', openingStockMilli: 50000); // 50 units in Store
      final locs = await repo.listLocations();
      final store = locs.firstWhere((l) => l.name == 'Store');
      final wh = locs.firstWhere((l) => l.name == 'Warehouse');

      // Move 20 units from Store to Warehouse instantly
      final transfer = await repo.transferInstant(
        productId: p.id,
        fromLocationId: store.id,
        toLocationId: wh.id,
        qtyMilli: 20000,
        note: 'Restock warehouse',
      );

      // Verify transfer_out and transfer_in were both recorded
      final movements = await (db.select(db.movements)
            ..where((m) => m.transferId.equals(transfer.outMovement.transferId!)))
          .get();
      expect(movements.length, equals(2));

      final outM = movements.firstWhere((m) => m.type == 'transfer_out');
      final inM = movements.firstWhere((m) => m.type == 'transfer_in');

      expect(outM.locationId, equals(store.id));
      expect(outM.qtyMilli, equals(-20000));

      expect(inM.locationId, equals(wh.id));
      expect(inM.qtyMilli, equals(20000));

      // Stock math
      expect(await repo.stockOf(p.id, locationId: store.id), equals(30000));
      expect(await repo.stockOf(p.id, locationId: wh.id), equals(20000));
      expect(await repo.stockOf(p.id), equals(50000)); // Total across all locations

      // In transit should be zero
      final inTransit = await repo.getInTransitTransfers();
      expect(inTransit, isEmpty);
    });
  });

  group('6. Two-Step Transfers & Discrepancies', () {
    late AppDatabase db;
    late LedgerRepository repo;
    late Location store;
    late Location warehouse;

    setUp(() async {
      db = AppDatabase.inMemory();
      repo = LedgerRepository(db);
      await repo.enableWarehouseAndStore(stockPlacement: 'store');
      final locs = await repo.listLocations();
      store = locs.firstWhere((l) => l.name == 'Store');
      warehouse = locs.firstWhere((l) => l.name == 'Warehouse');
    });

    tearDown(() => db.close());

    test('two-step transfer: dispatch leaves stock in transit until confirmed', () async {
      final p = await repo.createProduct(name: 'Rice', openingStockMilli: 30000);

      // Dispatch 10 units from Store to Warehouse
      final outM = await repo.transferOut(
        productId: p.id,
        fromLocationId: store.id,
        toLocationId: warehouse.id,
        qtyMilli: 10000,
        note: 'Van 1 delivery',
      );

      // Store dropped by 10 units
      expect(await repo.stockOf(p.id, locationId: store.id), equals(20000));
      // Warehouse has NOT received it yet
      expect(await repo.stockOf(p.id, locationId: warehouse.id), equals(0));

      // In-transit query
      final inTransit = await repo.getInTransitTransfers();
      expect(inTransit.length, equals(1));
      expect(inTransit.first.transferId, equals(outM.transferId));
      expect(inTransit.first.qtyMilli, equals(10000));
      expect(inTransit.first.fromLocationId, equals(store.id));
      expect(inTransit.first.toLocationId, equals(warehouse.id));

      expect(await repo.inTransitStockOf(p.id, toLocationId: warehouse.id), equals(10000));

      // Now receive the full quantity
      await repo.transferIn(
        transferId: outM.transferId!,
        productId: p.id,
        toLocationId: warehouse.id,
        qtyReceivedMilli: 10000,
      );

      // In-transit is cleared
      expect(await repo.getInTransitTransfers(), isEmpty);

      // Warehouse stock updated
      expect(await repo.stockOf(p.id, locationId: warehouse.id), equals(10000));
      expect(await repo.stockOf(p.id, locationId: store.id), equals(20000));

      // No discrepancies
      expect(await repo.getDiscrepancies(), isEmpty);
    });

    test('two-step transfer with different received quantity flags discrepancy and never auto-adjusts', () async {
      final p = await repo.createProduct(name: 'Eggs', openingStockMilli: 100000); // 100 crates

      // Dispatch 30 crates
      final outM = await repo.transferOut(
        productId: p.id,
        fromLocationId: store.id,
        toLocationId: warehouse.id,
        qtyMilli: 30000,
      );

      // Receiver receives only 28 crates (2 broken)
      await repo.transferIn(
        transferId: outM.transferId!,
        productId: p.id,
        toLocationId: warehouse.id,
        qtyReceivedMilli: 28000,
        note: '2 crates broken on cobblestone road',
      );

      // Store = 70, Warehouse = 28. (Total = 98, never auto-adjusted to 100)
      expect(await repo.stockOf(p.id, locationId: store.id), equals(70000));
      expect(await repo.stockOf(p.id, locationId: warehouse.id), equals(28000));
      expect(await repo.stockOf(p.id), equals(98000));

      // Discrepancy logged
      final disc = await repo.getDiscrepancies();
      expect(disc.length, equals(1));
      final d = disc.first;
      expect(d.transferId, equals(outM.transferId));
      expect(d.sentQtyMilli, equals(30000));
      expect(d.receivedQtyMilli, equals(28000));
      expect(d.diffMilli, equals(-2000));
      expect(d.reason, contains('2 crates broken'));
    });
  });

  group('7. Per-Location Stock Math & Negative Stock Enforcements', () {
    late AppDatabase db;
    late LedgerRepository repo;
    late Location store;
    late Location warehouse;

    setUp(() async {
      db = AppDatabase.inMemory();
      repo = LedgerRepository(db);
      await repo.enableWarehouseAndStore(stockPlacement: 'store');
      final locs = await repo.listLocations();
      store = locs.firstWhere((l) => l.name == 'Store');
      warehouse = locs.firstWhere((l) => l.name == 'Warehouse');
    });

    tearDown(() => db.close());

    test('recordSale enforces negative stock per location when disallowed', () async {
      final p = await repo.createProduct(name: 'Soap', sellPriceCents: 5000);
      // Put 5 units in Store, 0 in Warehouse
      await repo.recordStockIn(productId: p.id, qtyMilli: 5000, locationId: store.id);

      // Negative stock disabled by default
      expect(await repo.getAllowNegativeStock(), isFalse);

      // Selling from Warehouse (0 available) must fail
      expect(
        () => repo.recordSale(
          items: [SaleLineItem(product: p, qtyMilli: 1000, unitPriceCents: 5000)],
          locationId: warehouse.id,
        ),
        throwsA(isA<InsufficientStockException>()),
      );

      // Selling from Store (5 available) succeeds
      await repo.recordSale(
        items: [SaleLineItem(product: p, qtyMilli: 3000, unitPriceCents: 5000)],
        locationId: store.id,
      );
      expect(await repo.stockOf(p.id, locationId: store.id), equals(2000));

      // Allow negative stock -> sale at Warehouse now succeeds
      await repo.setAllowNegativeStock(true);
      await repo.recordSale(
        items: [SaleLineItem(product: p, qtyMilli: 1000, unitPriceCents: 5000)],
        locationId: warehouse.id,
      );
      expect(await repo.stockOf(p.id, locationId: warehouse.id), equals(-1000));
      expect(await repo.stockOf(p.id, locationId: store.id), equals(2000));
      expect(await repo.stockOf(p.id), equals(1000)); // Total
    });
  });

  group('8. Transfer Undo with Compensating Movements', () {
    late AppDatabase db;
    late LedgerRepository repo;
    late Location store;
    late Location warehouse;

    setUp(() async {
      db = AppDatabase.inMemory();
      repo = LedgerRepository(db);
      await repo.enableWarehouseAndStore(stockPlacement: 'store');
      final locs = await repo.listLocations();
      store = locs.firstWhere((l) => l.name == 'Store');
      warehouse = locs.firstWhere((l) => l.name == 'Warehouse');
    });

    tearDown(() => db.close());

    test('undoLastAction on an instant transfer reverses both sides atomically', () async {
      final p = await repo.createProduct(name: 'Sugar', openingStockMilli: 40000);

      // Instant transfer of 15 units
      await repo.transferInstant(
        productId: p.id,
        fromLocationId: store.id,
        toLocationId: warehouse.id,
        qtyMilli: 15000,
      );

      expect(await repo.stockOf(p.id, locationId: store.id), equals(25000));
      expect(await repo.stockOf(p.id, locationId: warehouse.id), equals(15000));

      // Undo the transfer
      await repo.undoLastAction();

      // Both sides are restored
      expect(await repo.stockOf(p.id, locationId: store.id), equals(40000));
      expect(await repo.stockOf(p.id, locationId: warehouse.id), equals(0));
    });
  });

  group('9. Outbox Wire Format Payloads', () {
    late AppDatabase db;
    late LedgerRepository repo;

    setUp(() async {
      db = AppDatabase.inMemory();
      repo = LedgerRepository(db);
      await repo.enableWarehouseAndStore(stockPlacement: 'store');
    });

    tearDown(() => db.close());

    test('movement outbox payload matches server allowed fields and types', () async {
      final p = await repo.createProduct(name: 'Coffee', sellPriceCents: 30000, openingStockMilli: 20000);
      final locs = await repo.listLocations();
      final store = locs.firstWhere((l) => l.name == 'Store');
      final wh = locs.firstWhere((l) => l.name == 'Warehouse');

      // Clear previous outbox entries for clean assertions
      await db.delete(db.outboxEntries).go();

      // Record a transfer
      final transfer = await repo.transferInstant(
        productId: p.id,
        fromLocationId: store.id,
        toLocationId: wh.id,
        qtyMilli: 7500,
        unitPriceCents: 25000,
        note: 'Special blend',
      );

      final outboxEntries = await db.select(db.outboxEntries).get();
      expect(outboxEntries.length, equals(2)); // transfer_out and transfer_in

      final allowedMovementFields = {
        'id',
        'product_id',
        'location_id',
        'transfer_id',
        'sale_id',
        'source_movement_id',
        'type',
        'tracks_stock',
        'qty',
        'unit_price_cents',
        'note',
        'created_at',
      };

      for (final entry in outboxEntries) {
        expect(entry.entity, equals('movement'));
        expect(entry.op, equals('upsert'));

        final payload = jsonDecode(entry.payloadJson) as Map<String, dynamic>;

        // Verify only allowed fields are present on wire
        for (final key in payload.keys) {
          expect(allowedMovementFields.contains(key), isTrue, reason: 'Disallowed key on wire: $key');
        }

        // Verify wire field formats
        expect(payload['location_id'], isNotNull);
        expect(payload['transfer_id'], equals(transfer.outMovement.transferId));
        expect(payload['qty'], isA<String>()); // Wire format qty is 3-decimal string
        expect(payload['unit_price_cents'], isA<int>());
        expect(payload['created_at'], isA<int>()); // Epoch ms
      }
    });

    test('location outbox payload matches server allowed fields', () async {
      await db.delete(db.outboxEntries).go();

      final newLoc = await db.createLocation(name: 'Depot', kind: 'other');
      final entry = await (db.select(db.outboxEntries)..where((o) => o.entityId.equals(newLoc.id))).getSingle();

      expect(entry.entity, equals('location'));
      expect(entry.op, equals('upsert'));

      final payload = jsonDecode(entry.payloadJson) as Map<String, dynamic>;
      final allowedLocationFields = {
        'id',
        'name',
        'kind',
        'is_default',
        'is_active',
        'updated_at',
      };

      for (final key in payload.keys) {
        expect(allowedLocationFields.contains(key), isTrue, reason: 'Disallowed key on wire: $key');
      }

      expect(payload['name'], equals('Depot'));
      expect(payload['kind'], equals('other'));
      expect(payload['is_active'], isTrue);
    });
  });

  group('10. Disable Warehouse & Store Guard', () {
    late AppDatabase db;
    late LedgerRepository repo;

    setUp(() async {
      db = AppDatabase.inMemory();
      repo = LedgerRepository(db);
      await repo.enableWarehouseAndStore(stockPlacement: 'store');
    });

    tearDown(() => db.close());

    test('cannot disable when stock sits in multiple locations or when transfers in transit', () async {
      final p = await repo.createProduct(name: 'Salt', openingStockMilli: 20000);
      final locs = await repo.listLocations();
      final store = locs.firstWhere((l) => l.name == 'Store');
      final wh = locs.firstWhere((l) => l.name == 'Warehouse');

      // Move 5 units to Warehouse so stock sits in both places
      await repo.transferInstant(
        productId: p.id,
        fromLocationId: store.id,
        toLocationId: wh.id,
        qtyMilli: 5000,
      );

      var check = await repo.canDisableLocations();
      expect(check.canDisable, isFalse);
      expect(check.reason, contains('Stock sits in 2 different locations'));

      // Transfer stock back to Store so all sits in Store
      await repo.transferInstant(
        productId: p.id,
        fromLocationId: wh.id,
        toLocationId: store.id,
        qtyMilli: 5000,
      );

      check = await repo.canDisableLocations();
      expect(check.canDisable, isTrue);

      // Now create an in-transit transfer
      final out = await repo.transferOut(
        productId: p.id,
        fromLocationId: store.id,
        toLocationId: wh.id,
        qtyMilli: 1000,
      );

      check = await repo.canDisableLocations();
      expect(check.canDisable, isFalse);
      expect(check.reason, contains('in transit'));

      // Receive the transfer
      await repo.transferIn(
        transferId: out.transferId!,
        productId: p.id,
        toLocationId: wh.id,
        qtyReceivedMilli: 1000,
      );

      // Now Warehouse has 1000, Store has 19000 -> cannot disable
      check = await repo.canDisableLocations();
      expect(check.canDisable, isFalse);

      // Move the 1000 back
      await repo.transferInstant(
        productId: p.id,
        fromLocationId: wh.id,
        toLocationId: store.id,
        qtyMilli: 1000,
      );

      check = await repo.canDisableLocations();
      expect(check.canDisable, isTrue);

      // Now disable
      await repo.disableWarehouseAndStore();
      expect(await repo.getLocationsEnabled(), isFalse);
    });
  });
}
