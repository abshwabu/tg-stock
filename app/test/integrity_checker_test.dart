import 'package:flutter_test/flutter_test.dart';

import 'package:app/database/app_database.dart';
import 'package:app/repositories/ledger_repository.dart';
import 'package:app/services/integrity_checker.dart';

void main() {
  late AppDatabase db;
  late LedgerRepository repository;

  setUp(() {
    db = AppDatabase.inMemory();
    repository = LedgerRepository(db);
  });

  tearDown(() => db.close());

  test('clean ledger, payments, references, and outbox produce a clean report', () async {
    final product = await repository.createProduct(name: 'Sugar', sellPriceCents: 1500);
    await repository.recordStockIn(productId: product.id, qtyMilli: 10000);

    final report = await IntegrityChecker(db).run();

    expect(report.isClean, isTrue, reason: report.text);
    expect(report.passed, contains('Stock equals the sum of tracked movements (1 products)'));
  });

  test('checker reports orphaned references without changing data', () async {
    await db.customStatement("INSERT INTO products (id, name, unit, kind, cost_price_cents, sell_price_cents, ask_price, is_active, updated_at_ms) VALUES ('p', 'P', 'pcs', 'stockable', 0, 0, 0, 1, 1)");
    await db.customStatement("INSERT INTO movements (id, product_id, type, qty_milli, unit_price_cents, tracks_stock, created_at_ms) VALUES ('m', 'missing', 'in', 1, 0, 1, 1)");

    final report = await IntegrityChecker(db).run();

    expect(report.isClean, isFalse);
    expect(report.issues.any((issue) => issue.contains('orphan movements')), isTrue);
    expect(await db.customSelect('SELECT COUNT(*) AS c FROM movements').getSingle().then((r) => r.data['c']), 1);
  });
}
