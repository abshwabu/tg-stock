import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'package:app/database/app_database.dart';
import 'package:app/repositories/ledger_repository.dart';
import 'package:app/services/export_service.dart';

void main() {
  late Directory tempDir;
  late AppDatabase db;
  late LedgerRepository repo;
  late ExportService exportService;

  setUp(() {
    tempDir = Directory.systemTemp.createTempSync('inventory_export_test_');
    db = AppDatabase.inMemory();
    repo = LedgerRepository(db);
    exportService = ExportService(repository: repo, outputDir: tempDir);
  });

  tearDown(() async {
    await db.close();
    if (tempDir.existsSync()) {
      tempDir.deleteSync(recursive: true);
    }
  });

  group('CSV Export (UTF-8 with BOM)', () {
    test('exportProductsCsv includes BOM, headers, and Amharic text', () async {
      final p1 = await repo.createProduct(
        name: 'Berbere (በርበሬ)',
        costPriceCents: 15000,
        sellPriceCents: 20000,
        openingStockMilli: 5000,
        unit: 'kg',
      );

      final file = await exportService.exportProductsCsv();
      expect(await file.exists(), isTrue);

      final bytes = await file.readAsBytes();
      // Verify UTF-8 BOM bytes [0xEF, 0xBB, 0xBF]
      expect(bytes.length, greaterThan(3));
      expect(bytes[0], 0xEF);
      expect(bytes[1], 0xBB);
      expect(bytes[2], 0xBF);

      final content = await file.readAsString();
      expect(content, contains('Product ID,Name,SKU,Unit,Cost Price (ETB),Sell Price (ETB),Low Stock Limit,Current Stock,Status'));
      expect(content, contains(p1.id));
      expect(content, contains('Berbere (በርበሬ)'));
      expect(content, contains('150.00'));
      expect(content, contains('200.00'));
      expect(content, contains('5'));
    });

    test('exportSalesCsv includes BOM and formatted transactions', () async {
      final p = await repo.createProduct(
        name: 'Coffee (ቡና)',
        costPriceCents: 20000,
        sellPriceCents: 35000,
        openingStockMilli: 10000,
        unit: 'kg',
      );

      await repo.recordSale(
        items: [
          SaleLineItem(product: p, qtyMilli: 2500, unitPriceCents: 35000),
        ],
        note: 'Customer table 4',
      );

      final file = await exportService.exportSalesCsv();
      expect(await file.exists(), isTrue);

      final bytes = await file.readAsBytes();
      expect(bytes[0], 0xEF);
      expect(bytes[1], 0xBB);
      expect(bytes[2], 0xBF);

      final content = await file.readAsString();
      expect(content, contains('Date,Time,Sale Movement ID,Product Name,SKU,Quantity,Unit,Unit Price (ETB),Total (ETB),Note'));
      expect(content, contains('Coffee (ቡና)'));
      expect(content, contains('2.5,kg,350.00,875.00'));
      expect(content, contains('Customer table 4'));
    });

    test('exportMovementsCsv includes BOM and signed quantities', () async {
      final p = await repo.createProduct(
        name: 'Sugar (ስኳር)',
        openingStockMilli: 10000,
      );

      await repo.recordStockIn(
        productId: p.id,
        qtyMilli: 5000,
        unitPriceCents: 8000,
        note: 'Delivery batch A',
      );

      final file = await exportService.exportMovementsCsv();
      expect(await file.exists(), isTrue);

      final bytes = await file.readAsBytes();
      expect(bytes[0], 0xEF);
      expect(bytes[1], 0xBB);
      expect(bytes[2], 0xBF);

      final content = await file.readAsString();
      expect(content, contains('Date,Time,Movement ID,Product Name,Type,Signed Quantity,Unit,Unit Price (ETB),Note'));
      expect(content, contains('Sugar (ስኳር)'));
      expect(content, contains('in'));
      expect(content, contains('5.000'));
      expect(content, contains('Delivery batch A'));
    });
  });
}
