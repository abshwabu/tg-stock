class CashFlowEntry {
  final String id;
  final String type; // 'income' or 'expense'
  final int amountCents;
  final String category;
  final String paymentMethod; // 'cash', 'bank', 'wallet'
  final String? note;
  final int createdAtMs;

  const CashFlowEntry({
    required this.id,
    required this.type,
    required this.amountCents,
    required this.category,
    required this.paymentMethod,
    this.note,
    required this.createdAtMs,
  });

  bool get isIncome => type == 'income';
  bool get isExpense => type == 'expense';

  factory CashFlowEntry.fromRow(Map<String, dynamic> row) {
    return CashFlowEntry(
      id: row['id'] as String,
      type: row['type'] as String,
      amountCents: (row['amount_cents'] as num).toInt(),
      category: row['category'] as String,
      paymentMethod: (row['payment_method'] as String?) ?? 'cash',
      note: row['note'] as String?,
      createdAtMs: (row['created_at_ms'] as num).toInt(),
    );
  }

  Map<String, dynamic> toMap() {
    return {
      'id': id,
      'type': type,
      'amount_cents': amountCents,
      'category': category,
      'payment_method': paymentMethod,
      'note': note,
      'created_at_ms': createdAtMs,
    };
  }
}

class CashFlowSummary {
  final int totalIncomeCents;
  final int totalExpenseCents;
  final int netCents;
  final int count;

  const CashFlowSummary({
    required this.totalIncomeCents,
    required this.totalExpenseCents,
    required this.netCents,
    required this.count,
  });

  static const empty = CashFlowSummary(
    totalIncomeCents: 0,
    totalExpenseCents: 0,
    netCents: 0,
    count: 0,
  );
}
