import 'dart:convert';
import 'dart:io';

import 'package:intl/intl.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../repositories/ledger_repository.dart';
import '../utils/quantity_formatter.dart';

class ExportService {
  final LedgerRepository repository;
  final Directory? outputDir;

  ExportService({required this.repository, this.outputDir});

  /// Escapes CSV values to handle commas, newlines, and quotes safely.
  String _escape(dynamic value) {
    if (value == null) return '';
    final str = value.toString();
    if (str.contains(',') || str.contains('"') || str.contains('\n') || str.contains('\r')) {
      return '"${str.replaceAll('"', '""')}"';
    }
    return str;
  }

  Future<Directory> _getTargetDirectory() async {
    return outputDir ?? await getTemporaryDirectory();
  }

  /// Exports product catalog with current stock and prices (UTF-8 with BOM).
  Future<File> exportProductsCsv() async {
    final products = await repository.listProducts(includeDeleted: false);
    final buffer = StringBuffer();

    // Write UTF-8 BOM so Excel and spreadsheet viewers recognize Amharic script
    buffer.write('\uFEFF');
    buffer.writeln('Product ID,Name,SKU,Unit,Cost Price (ETB),Sell Price (ETB),Low Stock Limit,Current Stock,Status');

    for (final p in products) {
      final stockDisplay = QuantityFormatter.formatDisplay(p.stockMilli);
      final costStr = (p.product.costPriceCents / 100).toStringAsFixed(2);
      final sellStr = (p.product.sellPriceCents / 100).toStringAsFixed(2);
      final lowStr = p.product.lowStockAtMilli != null
          ? QuantityFormatter.formatDisplay(p.product.lowStockAtMilli!)
          : '';
      final status = p.isLow ? 'Low Stock' : (p.isNegative ? 'Negative' : 'In Stock');

      buffer.writeln([
        _escape(p.product.id),
        _escape(p.product.name),
        _escape(p.product.sku ?? ''),
        _escape(p.product.unit),
        _escape(costStr),
        _escape(sellStr),
        _escape(lowStr),
        _escape(stockDisplay),
        _escape(status),
      ].join(','));
    }

    final targetDir = await _getTargetDirectory();
    final timestamp = DateFormat('yyyyMMdd_HHmmss').format(DateTime.now());
    final file = File(p.join(targetDir.path, 'products_export_$timestamp.csv'));
    await file.writeAsString(buffer.toString(), encoding: utf8);
    return file;
  }

  /// Exports sales transactions in the given time frame (UTF-8 with BOM).
  Future<File> exportSalesCsv({int? fromMs, int? toMs}) async {
    final sales = await repository.getSalesMovementsWithProduct(fromMs: fromMs, toMs: toMs);
    final buffer = StringBuffer();

    // UTF-8 BOM
    buffer.write('\uFEFF');
    buffer.writeln('Date,Time,Sale Movement ID,Product Name,SKU,Quantity,Unit,Unit Price (ETB),Total (ETB),Note');

    for (final s in sales) {
      final dt = DateTime.fromMillisecondsSinceEpoch(s.movement.createdAtMs).toLocal();
      final dateStr = DateFormat('yyyy-MM-dd').format(dt);
      final timeStr = DateFormat('HH:mm:ss').format(dt);
      final absQty = s.movement.qtyMilli.abs();
      final qtyDisplay = QuantityFormatter.formatDisplay(absQty);
      final unitPriceStr = (s.movement.unitPriceCents / 100).toStringAsFixed(2);
      final totalStr = ((absQty * s.movement.unitPriceCents) / 100000).toStringAsFixed(2);

      buffer.writeln([
        _escape(dateStr),
        _escape(timeStr),
        _escape(s.movement.id),
        _escape(s.product?.name ?? 'Unknown Product'),
        _escape(s.product?.sku ?? ''),
        _escape(qtyDisplay),
        _escape(s.product?.unit ?? 'pcs'),
        _escape(unitPriceStr),
        _escape(totalStr),
        _escape(s.movement.note ?? ''),
      ].join(','));
    }

    final targetDir = await _getTargetDirectory();
    final timestamp = DateFormat('yyyyMMdd_HHmmss').format(DateTime.now());
    final file = File(p.join(targetDir.path, 'sales_export_$timestamp.csv'));
    await file.writeAsString(buffer.toString(), encoding: utf8);
    return file;
  }

  /// Exports all inventory movements (in, sale, adjust) with signed quantities (UTF-8 with BOM).
  Future<File> exportMovementsCsv({int? fromMs, int? toMs}) async {
    final movements = await repository.getMovementsWithProduct(fromMs: fromMs, toMs: toMs);
    final buffer = StringBuffer();

    // UTF-8 BOM
    buffer.write('\uFEFF');
    buffer.writeln('Date,Time,Movement ID,Product Name,Type,Signed Quantity,Unit,Unit Price (ETB),Note');

    for (final m in movements) {
      final dt = DateTime.fromMillisecondsSinceEpoch(m.movement.createdAtMs).toLocal();
      final dateStr = DateFormat('yyyy-MM-dd').format(dt);
      final timeStr = DateFormat('HH:mm:ss').format(dt);
      final qtyDisplay = QuantityFormatter.toWireQty(m.movement.qtyMilli);
      final unitPriceStr = (m.movement.unitPriceCents / 100).toStringAsFixed(2);

      buffer.writeln([
        _escape(dateStr),
        _escape(timeStr),
        _escape(m.movement.id),
        _escape(m.product?.name ?? 'Unknown Product'),
        _escape(m.movement.type),
        _escape(qtyDisplay),
        _escape(m.product?.unit ?? 'pcs'),
        _escape(unitPriceStr),
        _escape(m.movement.note ?? ''),
      ].join(','));
    }

    final targetDir = await _getTargetDirectory();
    final timestamp = DateFormat('yyyyMMdd_HHmmss').format(DateTime.now());
    final file = File(p.join(targetDir.path, 'movements_export_$timestamp.csv'));
    await file.writeAsString(buffer.toString(), encoding: utf8);
    return file;
  }

  /// Opens the system share sheet with the exported file.
  Future<void> shareFile(File file, {String subject = 'Inventory CSV Export'}) async {
    await SharePlus.instance.share(
      ShareParams(
        files: [XFile(file.path)],
        subject: subject,
      ),
    );
  }
}
