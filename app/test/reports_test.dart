import 'package:flutter_test/flutter_test.dart';

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

  group('Reports & Analytics Math', () {
    test('sales report computes revenue, profit, and rankings accurately', () async {
      final now = DateTime.now().toUtc();
      final nowMs = now.millisecondsSinceEpoch;

      // Product 1: Cost 60.00 ETB, Sell 100.00 ETB
      final p1 = await repo.createProduct(
        name: 'Sugar (ስኳር)',
        costPriceCents: 6000,
        sellPriceCents: 10000,
        openingStockMilli: 20000,
      );

      // Product 2: Cost 200.00 ETB, Sell 300.00 ETB
      final p2 = await repo.createProduct(
        name: 'Coffee (ቡና)',
        costPriceCents: 20000,
        sellPriceCents: 30000,
        openingStockMilli: 20000,
      );

      // Product 3: Cost 0.00 (Unset cost), Sell 50.00 ETB
      final p3 = await repo.createProduct(
        name: 'Biscuits (ቢስኩት)',
        costPriceCents: 0,
        sellPriceCents: 5000,
        openingStockMilli: 20000,
      );

      // Record Sales:
      // Sale 1: P1 (3 units) -> Rev: 300.00 ETB (30,000 cents), Profit: 3 * 40 = 120.00 ETB (12,000 cents)
      await repo.recordSale(
        items: [
          SaleLineItem(product: p1, qtyMilli: 3000, unitPriceCents: 10000),
        ],
      );

      // Sale 2: P2 (2 units) -> Rev: 600.00 ETB (60,000 cents), Profit: 2 * 100 = 200.00 ETB (20,000 cents)
      await repo.recordSale(
        items: [
          SaleLineItem(product: p2, qtyMilli: 2000, unitPriceCents: 30000),
        ],
      );

      // Sale 3: P3 (4 units) -> Rev: 200.00 ETB (20,000 cents), Profit: 0 (cost is 0)
      await repo.recordSale(
        items: [
          SaleLineItem(product: p3, qtyMilli: 4000, unitPriceCents: 5000),
        ],
      );

      // Run Sales Report for the window covering all sales
      final report = await repo.getSalesReport(
        fromMs: nowMs - 60000,
        toMs: nowMs + 60000,
      );

      // Revenue: 30,000 + 60,000 + 20,000 = 110,000 cents (1,100.00 ETB)
      expect(report.totalRevenueCents, 110000);

      // Sales transactions count
      expect(report.salesCount, 3);

      // Estimated Profit: 12,000 + 20,000 = 32,000 cents (320.00 ETB)
      expect(report.estimatedProfitCents, 32000);
      expect(report.itemsWithCostCount, 2);

      // Top 5 by Revenue:
      // 1st: Coffee (60,000 cents)
      // 2nd: Sugar (30,000 cents)
      // 3rd: Biscuits (20,000 cents)
      expect(report.topByRevenue.length, 3);
      expect(report.topByRevenue[0].productName, 'Coffee (ቡና)');
      expect(report.topByRevenue[0].totalRevenueCents, 60000);
      expect(report.topByRevenue[1].productName, 'Sugar (ስኳር)');
      expect(report.topByRevenue[1].totalRevenueCents, 30000);
      expect(report.topByRevenue[2].productName, 'Biscuits (ቢስኩት)');
      expect(report.topByRevenue[2].totalRevenueCents, 20000);

      // Top 5 by Quantity:
      // 1st: Biscuits (4,000 milli = 4.0 units)
      // 2nd: Sugar (3,000 milli = 3.0 units)
      // 3rd: Coffee (2,000 milli = 2.0 units)
      expect(report.topByQuantity.length, 3);
      expect(report.topByQuantity[0].productName, 'Biscuits (ቢስኩት)');
      expect(report.topByQuantity[0].totalQtyMilli, 4000);
      expect(report.topByQuantity[1].productName, 'Sugar (ስኳር)');
      expect(report.topByQuantity[1].totalQtyMilli, 3000);
      expect(report.topByQuantity[2].productName, 'Coffee (ቡና)');
      expect(report.topByQuantity[2].totalQtyMilli, 2000);
    });

    test('estimated profit is null when no items sold have cost price', () async {
      final now = DateTime.now().toUtc();
      final nowMs = now.millisecondsSinceEpoch;

      final p = await repo.createProduct(
        name: 'Item without cost',
        costPriceCents: 0,
        sellPriceCents: 15000,
        openingStockMilli: 10000,
      );

      await repo.recordSale(
        items: [
          SaleLineItem(product: p, qtyMilli: 2000, unitPriceCents: 15000),
        ],
      );

      final report = await repo.getSalesReport(
        fromMs: nowMs - 60000,
        toMs: nowMs + 60000,
      );

      expect(report.totalRevenueCents, 30000);
      expect(report.salesCount, 1);
      expect(report.estimatedProfitCents, isNull);
      expect(report.itemsWithCostCount, 0);
    });

    test('stock valuation report calculates cost and sell values accurately', () async {
      // Product 1: 10 units in stock. Cost 50 ETB (5000 cents), Sell 80 ETB (8000 cents)
      await repo.createProduct(
        name: 'Teff (ጤፍ)',
        costPriceCents: 5000,
        sellPriceCents: 8000,
        openingStockMilli: 10000, // 10.0
        lowStockAtMilli: 3000,
      );

      // Product 2: 2 units in stock (Low stock!). Cost 100 ETB, Sell 150 ETB
      await repo.createProduct(
        name: 'Cooking Oil (ዘይት)',
        costPriceCents: 10000,
        sellPriceCents: 15000,
        openingStockMilli: 2000, // 2.0
        lowStockAtMilli: 5000, // 5.0 -> LOW
      );

      // Product 3: 0 units in stock (Out of stock!). Cost 20 ETB, Sell 30 ETB
      await repo.createProduct(
        name: 'Salt (ጨው)',
        costPriceCents: 2000,
        sellPriceCents: 3000,
        openingStockMilli: 0,
        lowStockAtMilli: 1000,
      );

      final stockReport = await repo.getStockReport();

      expect(stockReport.totalProductsCount, 3);
      expect(stockReport.lowStockCount, 2); // Cooking Oil (2 <= 5) and Salt (0 <= 1)
      expect(stockReport.outOfStockCount, 1); // Salt (0 <= 0)

      // Value at Cost: (10 * 50) + (2 * 100) + 0 = 500 + 200 = 700.00 ETB (70,000 cents)
      expect(stockReport.totalValueCostCents, 70000);

      // Value at Selling Price: (10 * 80) + (2 * 150) + 0 = 800 + 300 = 1,100.00 ETB (110,000 cents)
      expect(stockReport.totalValueSellCents, 110000);
    });
  });
}
