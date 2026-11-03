import 'package:flutter_test/flutter_test.dart';
import 'package:drift/drift.dart';

import 'package:app/database/app_database.dart';
import 'package:app/repositories/ledger_repository.dart';

void main() {
  late AppDatabase db;
  late LedgerRepository repo;

  setUp(() {
    db = AppDatabase.inMemory();
    repo = LedgerRepository(db);
  });

  tearDown(() async {
    await db.close();
  });

  group('Stock Math & Ledger Invariants', () {
    test('stock is strictly the sum of append-only movements', () async {
      final product = await repo.createProduct(
        name: 'Berbere (በርበሬ)',
        costPriceCents: 15000,
        sellPriceCents: 20000,
        openingStockMilli: 5000, // 5.0 units
      );

      // Check opening stock
      var stock = await repo.stockOf(product.id);
      expect(stock, 5000);

      // Stock In: +10 units (10000 milli)
      await repo.recordStockIn(
        productId: product.id,
        qtyMilli: 10000,
        unitPriceCents: 15000,
      );
      stock = await repo.stockOf(product.id);
      expect(stock, 15000);

      // Sale: -3 units (3000 milli)
      await repo.recordSale(
        items: [
          SaleLineItem(
            product: product,
            qtyMilli: 3000,
            unitPriceCents: 20000,
          ),
        ],
      );
      stock = await repo.stockOf(product.id);
      expect(stock, 12000);

      // Count adjustment: counted 10 units (10000 milli)
      await repo.recordAdjustment(
        productId: product.id,
        countedQtyMilli: 10000,
        reason: 'count_correction',
      );
      stock = await repo.stockOf(product.id);
      expect(stock, 10000);

      // Verify all movements are preserved and immutable in the ledger
      final movements = await (db.select(db.movements)
            ..where((m) => m.productId.equals(product.id))
            ..orderBy([(m) => OrderingTerm(expression: m.createdAtMs, mode: OrderingMode.asc)]))
          .get();

      expect(movements.length, 4);
      expect(movements[0].type, 'in'); // opening stock
      expect(movements[0].qtyMilli, 5000);
      expect(movements[1].type, 'in');
      expect(movements[1].qtyMilli, 10000);
      expect(movements[2].type, 'sale');
      expect(movements[2].qtyMilli, -3000);
      expect(movements[3].type, 'adjust');
      expect(movements[3].qtyMilli, -2000); // 10000 - 12000 = -2000
    });

    test('every data write writes an outbox row in the same transaction', () async {
      // 1. Create product
      final product = await repo.createProduct(
        name: 'Coffee (ቡና)',
        sellPriceCents: 50000,
      );

      var outbox = await db.select(db.outboxEntries).get();
      expect(outbox.length, 1);
      expect(outbox.first.entityId, product.id);
      expect(outbox.first.entity, 'product');
      expect(outbox.first.op, 'upsert');

      // 2. Stock In
      final stockInMov = await repo.recordStockIn(
        productId: product.id,
        qtyMilli: 20000,
      );

      outbox = await db.select(db.outboxEntries).get();
      expect(outbox.length, 2);
      expect(outbox.last.entityId, stockInMov.id);
      expect(outbox.last.entity, 'movement');
      expect(outbox.last.op, 'upsert');

      // 3. Sale
      final sales = await repo.recordSale(
        items: [
          SaleLineItem(
            product: product,
            qtyMilli: 5000,
            unitPriceCents: 50000,
          ),
        ],
      );

      outbox = await db.select(db.outboxEntries).get();
      expect(outbox.length, 3);
      expect(outbox.last.entityId, sales.first.id);
      expect(outbox.last.entity, 'movement');
      expect(outbox.last.op, 'upsert');

      // 4. Update Product
      await repo.updateProduct(
        product.copyWith(name: 'Ethiopian Premium Coffee (የኢትዮጵያ ምርጥ ቡና)'),
      );
      outbox = await db.select(db.outboxEntries).get();
      expect(outbox.length, 4);
      expect(outbox.last.entityId, product.id);
      expect(outbox.last.entity, 'product');
      expect(outbox.last.op, 'upsert');

      // 5. Delete Product (Soft delete)
      await repo.deleteProduct(product.id);
      outbox = await db.select(db.outboxEntries).get();
      expect(outbox.length, 5);
      expect(outbox.last.entityId, product.id);
      expect(outbox.last.entity, 'product');
      expect(outbox.last.op, 'delete');
    });
  });

  group('Negative Stock Enforcement', () {
    test('disallowing negative stock blocks sales exceeding inventory', () async {
      await repo.setAllowNegativeStock(false);

      final product = await repo.createProduct(
        name: 'Teff (ጤፍ)',
        openingStockMilli: 5000, // 5.0
        sellPriceCents: 10000,
      );

      // Attempting to sell 8.0 units (8000 milli) must throw InsufficientStockException
      expect(
        () => repo.recordSale(
          items: [
            SaleLineItem(
              product: product,
              qtyMilli: 8000,
              unitPriceCents: 10000,
            ),
          ],
        ),
        throwsA(isA<InsufficientStockException>()),
      );

      // Stock remains exactly 5000
      final stock = await repo.stockOf(product.id);
      expect(stock, 5000);
    });

    test('allowing negative stock permits sales below zero', () async {
      await repo.setAllowNegativeStock(true);

      final product = await repo.createProduct(
        name: 'Sugar (ስኳር)',
        openingStockMilli: 2000, // 2.0
        sellPriceCents: 12000,
      );

      // Sell 5.0 units (5000 milli)
      final sales = await repo.recordSale(
        items: [
          SaleLineItem(
            product: product,
            qtyMilli: 5000,
            unitPriceCents: 12000,
          ),
        ],
      );

      expect(sales.length, 1);
      expect(sales.first.qtyMilli, -5000);

      // Stock drops into negative: 2000 - 5000 = -3000 (-3.0)
      final stock = await repo.stockOf(product.id);
      expect(stock, -3000);
    });
  });

  group('10-Minute Compensating Undo', () {
    test('undo within 10 minutes appends compensating movement and restores stock', () async {
      final product = await repo.createProduct(
        name: 'Oil (ዘይት)',
        openingStockMilli: 10000,
      );

      // Perform a sale of 4 units (-4000 milli)
      await repo.recordSale(
        items: [
          SaleLineItem(
            product: product,
            qtyMilli: 4000,
            unitPriceCents: 85000,
          ),
        ],
      );

      var stock = await repo.stockOf(product.id);
      expect(stock, 6000);

      // Execute undo
      final compensatingMov = await repo.undoLastAction();

      expect(compensatingMov.productId, product.id);
      expect(compensatingMov.type, 'adjust');
      expect(compensatingMov.qtyMilli, 4000); // Opposite of -4000
      expect(compensatingMov.note, contains('Undo: compensating movement'));

      // Stock is restored to 10000
      stock = await repo.stockOf(product.id);
      expect(stock, 10000);

      // An outbox entry was appended for the compensating movement
      final outbox = await db.select(db.outboxEntries).get();
      expect(outbox.any((o) => o.entityId == compensatingMov.id), isTrue);
    });

    test('undo fails when action was more than 10 minutes ago', () async {
      final product = await repo.createProduct(
        name: 'Flour (ዱቄት)',
      );

      // Artificially record an old movement (15 minutes ago)
      final oldTimestamp = DateTime.now().toUtc().millisecondsSinceEpoch - (15 * 60 * 1000);

      await (db.into(db.movements).insert(
        MovementsCompanion.insert(
          id: '018f0000-0000-7000-8000-000000000001',
          productId: product.id,
          type: 'sale',
          qtyMilli: -5000,
          unitPriceCents: const Value(5000),
          createdAtMs: oldTimestamp,
        ),
      ));

      expect(
        () => repo.undoLastAction(),
        throwsA(isA<UndoExpiredException>()),
      );
    });

    test('undo fails when no movements exist', () async {
      expect(
        () => repo.undoLastAction(),
        throwsA(isA<NoMovementToUndoException>()),
      );
    });
  });

  group('Adjustments & Reason Labels', () {
    test('physical count adjustment saves difference and reason', () async {
      final product = await repo.createProduct(
        name: 'Eggs (እንቁላል)',
        openingStockMilli: 30000, // 30 eggs
      );

      // Physical count reveals only 24 intact eggs (damaged 6)
      final mov = await repo.recordAdjustment(
        productId: product.id,
        countedQtyMilli: 24000,
        reason: 'damaged',
        note: 'Broken in transit',
      );

      expect(mov.qtyMilli, -6000);
      expect(mov.note, contains('Damaged / Spoilage'));
      expect(mov.note, contains('Broken in transit'));

      final stock = await repo.stockOf(product.id);
      expect(stock, 24000);
    });
  });

  group('Aggregates & Metrics', () {
    test('todaySalesTotalCents sums only today sales', () async {
      final p1 = await repo.createProduct(
        name: 'Soap (ሳሙና)',
        openingStockMilli: 50000,
        sellPriceCents: 5000,
      );

      // Sale today: 3 units * 5000 = 15,000 cents
      await repo.recordSale(
        items: [
          SaleLineItem(
            product: p1,
            qtyMilli: 3000,
            unitPriceCents: 5000,
          ),
        ],
      );

      // Sale today: 2 units * 5000 = 10,000 cents
      await repo.recordSale(
        items: [
          SaleLineItem(
            product: p1,
            qtyMilli: 2000,
            unitPriceCents: 5000,
          ),
        ],
      );

      // Stock In must not count towards sales
      await repo.recordStockIn(
        productId: p1.id,
        qtyMilli: 10000,
        unitPriceCents: 3000,
      );

      final totalCents = await repo.todaySalesTotalCents();
      expect(totalCents, 25000); // 150.00 + 100.00 ETB = 250.00 ETB
    });

    test('lowStockCount identifies active products at or below threshold', () async {
      // p1: low stock threshold 5000, current stock 3000 -> LOW
      await repo.createProduct(
        name: 'Tea (ሻይ)',
        lowStockAtMilli: 5000,
        openingStockMilli: 3000,
      );

      // p2: low stock threshold 5000, current stock 8000 -> OK
      await repo.createProduct(
        name: 'Salt (ጨው)',
        lowStockAtMilli: 5000,
        openingStockMilli: 8000,
      );

      // p3: low stock threshold 2000, current stock 1000, but deleted -> EXCLUDED
      final p3 = await repo.createProduct(
        name: 'Candle (ሻማ)',
        lowStockAtMilli: 2000,
        openingStockMilli: 1000,
      );
      await repo.deleteProduct(p3.id);

      final lowCount = await repo.lowStockCount();
      expect(lowCount, 1);
    });
  });
}
