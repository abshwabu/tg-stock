import 'dart:convert';

import '../database/app_database.dart';

/// Read-only consistency report for support and release QA.
class IntegrityReport {
  final DateTime checkedAt;
  final List<String> passed;
  final List<String> issues;

  const IntegrityReport({required this.checkedAt, required this.passed, required this.issues});

  bool get isClean => issues.isEmpty;

  String get text {
    final out = StringBuffer()
      ..writeln('Inventory data check — ${checkedAt.toIso8601String()}')
      ..writeln('Status: ${isClean ? 'CLEAN' : 'ISSUES FOUND'}');
    if (passed.isNotEmpty) {
      out.writeln('\nPassed:');
      for (final item in passed) {
        out.writeln('✓ $item');
      }
    }
    if (issues.isNotEmpty) {
      out.writeln('\nIssues:');
      for (final item in issues) {
        out.writeln('! $item');
      }
    }
    return out.toString();
  }
}

class IntegrityChecker {
  final AppDatabase db;

  const IntegrityChecker(this.db);

  Future<IntegrityReport> run() async {
    final passed = <String>[];
    final issues = <String>[];

    final pragma = await db.customSelect('PRAGMA integrity_check').get();
    final pragmaResult = pragma.isNotEmpty ? pragma.first.data.values.first.toString() : 'missing';
    if (pragmaResult == 'ok') {
      passed.add('SQLite integrity_check');
    } else {
      issues.add('SQLite integrity_check returned $pragmaResult');
    }

    final stockRows = await db.customSelect('''
      SELECT p.id, p.name,
        COALESCE((SELECT SUM(m.qty_milli) FROM movements m
          WHERE m.product_id = p.id AND m.tracks_stock = 1), 0) AS ledger_stock
      FROM products p WHERE p.deleted_at_ms IS NULL
    ''').get();
    var stockOk = true;
    for (final row in stockRows) {
      final id = row.data['id']?.toString();
      if (id == null) continue;
      final expected = (row.data['ledger_stock'] as num?)?.toInt() ?? 0;
      final actual = await db.getStock(id);
      if (actual != expected) {
        stockOk = false;
        issues.add('Stock mismatch for ${row.data['name']}: expected $expected, found $actual');
      }
    }
    if (stockOk) passed.add('Stock equals the sum of tracked movements (${stockRows.length} products)');

    final orphanQueries = <String, String>{
      'orphan movements': 'SELECT COUNT(*) AS c FROM movements m LEFT JOIN products p ON p.id = m.product_id WHERE p.id IS NULL',
      'orphan movement locations': 'SELECT COUNT(*) AS c FROM movements m LEFT JOIN locations l ON l.id = m.location_id WHERE m.location_id IS NOT NULL AND l.id IS NULL',
      'orphan sale movements': 'SELECT COUNT(*) AS c FROM movements m LEFT JOIN sales s ON s.id = m.sale_id WHERE m.sale_id IS NOT NULL AND s.id IS NULL',
      'orphan payments': 'SELECT COUNT(*) AS c FROM payments pay LEFT JOIN sales s ON s.id = pay.sale_id WHERE s.id IS NULL',
      'orphan materials': 'SELECT COUNT(*) AS c FROM product_materials pm LEFT JOIN products p ON p.id = pm.product_id LEFT JOIN products mat ON mat.id = pm.material_product_id WHERE p.id IS NULL OR mat.id IS NULL',
    };
    for (final entry in orphanQueries.entries) {
      final rows = await db.customSelect(entry.value).get();
      final count = (rows.first.data['c'] as num?)?.toInt() ?? 0;
      if (count == 0) {
        passed.add('${entry.key}: none');
      } else {
        issues.add('${entry.key}: $count');
      }
    }

    final overpaid = await db.customSelect('''
      SELECT COUNT(*) AS c FROM (
        SELECT s.id, s.total_cents, COALESCE(SUM(p.amount_cents), 0) AS paid
        FROM sales s LEFT JOIN payments p ON p.sale_id = s.id
        GROUP BY s.id HAVING paid > total_cents AND s.voided_at_ms IS NULL
      )
    ''').get();
    final overpaidCount = (overpaid.first.data['c'] as num?)?.toInt() ?? 0;
    if (overpaidCount == 0) {
      passed.add('Payments do not exceed sale totals');
    } else {
      issues.add('Payments exceed sale totals for $overpaidCount sale(s)');
    }

    final outbox = await db.customSelect('''
      SELECT COUNT(*) AS c FROM outbox_entries o
      LEFT JOIN products p ON o.entity = 'product' AND p.id = o.entity_id
      LEFT JOIN movements m ON o.entity = 'movement' AND m.id = o.entity_id
      LEFT JOIN sales s ON o.entity = 'sale' AND s.id = o.entity_id
      LEFT JOIN payments pay ON o.entity = 'payment' AND pay.id = o.entity_id
      LEFT JOIN locations l ON o.entity = 'location' AND l.id = o.entity_id
      WHERE (o.entity = 'product' AND p.id IS NULL)
         OR (o.entity = 'movement' AND m.id IS NULL)
         OR (o.entity = 'sale' AND s.id IS NULL)
         OR (o.entity = 'payment' AND pay.id IS NULL)
         OR (o.entity = 'location' AND l.id IS NULL)
    ''').get();
    final outboxIssues = (outbox.first.data['c'] as num?)?.toInt() ?? 0;
    if (outboxIssues == 0) {
      passed.add('Outbox entries reference local records');
    } else {
      issues.add('Outbox has $outboxIssues orphan reference(s)');
    }

    return IntegrityReport(checkedAt: DateTime.now().toUtc(), passed: passed, issues: issues);
  }
}

String integrityReportJson(IntegrityReport report) => jsonEncode({
      'checked_at': report.checkedAt.toIso8601String(),
      'clean': report.isClean,
      'passed': report.passed,
      'issues': report.issues,
    });
