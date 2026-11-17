import 'package:flutter_test/flutter_test.dart';

import 'package:app/database/app_database.dart';
import 'package:app/repositories/ledger_repository.dart';

void main() {
  late AppDatabase db;
  late LedgerRepository repo;

  setUp(() async {
    db = AppDatabase.inMemory();
    repo = LedgerRepository(db);
    // Trigger any beforeOpen hooks
    await db.customSelect('SELECT 1').get();
  });

  tearDown(() async {
    await db.close();
  });

  group('Non-Inventory Cash Flow (Extra Income & Expense)', () {
    test('creates and retrieves income and expense entries correctly', () async {
      final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

      // 1. Add Rent Expense (5,000 ETB = 500,000 cents)
      final expense1 = await repo.addCashFlowEntry(
        type: 'expense',
        amountCents: 500000,
        category: 'Rent',
        paymentMethod: 'bank',
        note: 'October shop rent',
        createdAtMs: nowMs - 1000,
      );
      expect(expense1.id, isNotEmpty);
      expect(expense1.isExpense, isTrue);
      expect(expense1.isIncome, isFalse);
      expect(expense1.amountCents, 500000);

      // 2. Add Service Fee Income (1,200 ETB = 120,000 cents)
      final income1 = await repo.addCashFlowEntry(
        type: 'income',
        amountCents: 120000,
        category: 'Service Fee',
        paymentMethod: 'cash',
        note: 'Phone repair service',
        createdAtMs: nowMs,
      );
      expect(income1.isIncome, isTrue);
      expect(income1.amountCents, 120000);

      // 3. Add Utilities Expense (350 ETB = 35,000 cents)
      await repo.addCashFlowEntry(
        type: 'expense',
        amountCents: 35000,
        category: 'Electricity',
        paymentMethod: 'wallet',
        createdAtMs: nowMs + 1000,
      );

      // Query all entries
      final allEntries = await repo.getCashFlowEntries();
      expect(allEntries.length, 3);
      // Newest first
      expect(allEntries.first.category, 'Electricity');
      expect(allEntries.last.category, 'Rent');

      // Filter by type: income
      final incomes = await repo.getCashFlowEntries(type: 'income');
      expect(incomes.length, 1);
      expect(incomes.first.category, 'Service Fee');

      // Filter by type: expense
      final expenses = await repo.getCashFlowEntries(type: 'expense');
      expect(expenses.length, 2);

      // Verify Summary Math
      final summary = await repo.getCashFlowSummary();
      expect(summary.totalIncomeCents, 120000);
      expect(summary.totalExpenseCents, 535000); // 500,000 + 35,000
      expect(summary.netCents, 120000 - 535000); // -415,000
      expect(summary.count, 3);

      // Delete an entry and verify summary
      await repo.deleteCashFlowEntry(expense1.id);
      final remaining = await repo.getCashFlowEntries();
      expect(remaining.length, 2);

      final updatedSummary = await repo.getCashFlowSummary();
      expect(updatedSummary.totalExpenseCents, 35000);
      expect(updatedSummary.totalIncomeCents, 120000);
      expect(updatedSummary.netCents, 85000);
      expect(updatedSummary.count, 2);
    });

    test('filters cash flow entries by date window', () async {
      final t1 = DateTime(2026, 11, 1).toUtc().millisecondsSinceEpoch;
      final t2 = DateTime(2026, 11, 10).toUtc().millisecondsSinceEpoch;
      final t3 = DateTime(2026, 11, 20).toUtc().millisecondsSinceEpoch;

      await repo.addCashFlowEntry(
        type: 'expense',
        amountCents: 10000,
        category: 'Early Exp',
        createdAtMs: t1,
      );
      await repo.addCashFlowEntry(
        type: 'income',
        amountCents: 20000,
        category: 'Mid Inc',
        createdAtMs: t2,
      );
      await repo.addCashFlowEntry(
        type: 'expense',
        amountCents: 30000,
        category: 'Late Exp',
        createdAtMs: t3,
      );

      // Window covering only t2
      final midOnly = await repo.getCashFlowEntries(
        startMs: DateTime(2026, 11, 5).toUtc().millisecondsSinceEpoch,
        endMs: DateTime(2026, 11, 15).toUtc().millisecondsSinceEpoch,
      );
      expect(midOnly.length, 1);
      expect(midOnly.first.category, 'Mid Inc');

      final midSummary = await repo.getCashFlowSummary(
        startMs: DateTime(2026, 11, 5).toUtc().millisecondsSinceEpoch,
        endMs: DateTime(2026, 11, 15).toUtc().millisecondsSinceEpoch,
      );
      expect(midSummary.totalIncomeCents, 20000);
      expect(midSummary.totalExpenseCents, 0);
      expect(midSummary.netCents, 20000);
    });
  });
}
