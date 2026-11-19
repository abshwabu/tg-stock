import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../database/cash_flow_entry.dart';
import '../../repositories/ledger_repository.dart';
import '../../utils/money_formatter.dart';
import '../theme.dart';
import '../widgets/empty_state_widget.dart';

enum CashFlowRange {
  today,
  yesterday,
  week,
  month,
  all,
  custom,
}

class IncomeExpenseScreen extends StatefulWidget {
  final LedgerRepository repository;
  final VoidCallback? onMutation;

  const IncomeExpenseScreen({
    super.key,
    required this.repository,
    this.onMutation,
  });

  @override
  State<IncomeExpenseScreen> createState() => _IncomeExpenseScreenState();
}

class _IncomeExpenseScreenState extends State<IncomeExpenseScreen> {
  CashFlowRange _selectedRange = CashFlowRange.today;
  DateTimeRange? _customDateRange;
  String _selectedTypeFilter = 'all'; // 'all', 'income', 'expense'

  bool _isLoading = true;
  List<CashFlowEntry> _entries = [];
  CashFlowSummary _summary = CashFlowSummary.empty;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  (int?, int?) _calculateTimeWindow() {
    final now = DateTime.now();
    switch (_selectedRange) {
      case CashFlowRange.today:
        final start = DateTime(now.year, now.month, now.day);
        final end = DateTime(now.year, now.month, now.day, 23, 59, 59, 999);
        return (start.toUtc().millisecondsSinceEpoch, end.toUtc().millisecondsSinceEpoch);

      case CashFlowRange.yesterday:
        final yest = now.subtract(const Duration(days: 1));
        final start = DateTime(yest.year, yest.month, yest.day);
        final end = DateTime(yest.year, yest.month, yest.day, 23, 59, 59, 999);
        return (start.toUtc().millisecondsSinceEpoch, end.toUtc().millisecondsSinceEpoch);

      case CashFlowRange.week:
        final start = DateTime(now.year, now.month, now.day).subtract(const Duration(days: 6));
        final end = DateTime(now.year, now.month, now.day, 23, 59, 59, 999);
        return (start.toUtc().millisecondsSinceEpoch, end.toUtc().millisecondsSinceEpoch);

      case CashFlowRange.month:
        final start = DateTime(now.year, now.month, 1);
        final end = DateTime(now.year, now.month, now.day, 23, 59, 59, 999);
        return (start.toUtc().millisecondsSinceEpoch, end.toUtc().millisecondsSinceEpoch);

      case CashFlowRange.all:
        return (null, null);

      case CashFlowRange.custom:
        if (_customDateRange != null) {
          final start = DateTime(_customDateRange!.start.year, _customDateRange!.start.month, _customDateRange!.start.day);
          final end = DateTime(_customDateRange!.end.year, _customDateRange!.end.month, _customDateRange!.end.day, 23, 59, 59, 999);
          return (start.toUtc().millisecondsSinceEpoch, end.toUtc().millisecondsSinceEpoch);
        }
        return (null, null);
    }
  }

  Future<void> _loadData() async {
    setState(() => _isLoading = true);
    final (startMs, endMs) = _calculateTimeWindow();

    final entries = await widget.repository.getCashFlowEntries(
      startMs: startMs,
      endMs: endMs,
      type: _selectedTypeFilter == 'all' ? null : _selectedTypeFilter,
    );

    final summary = await widget.repository.getCashFlowSummary(
      startMs: startMs,
      endMs: endMs,
    );

    if (mounted) {
      setState(() {
        _entries = entries;
        _summary = summary;
        _isLoading = false;
      });
    }
  }

  Future<void> _deleteEntry(CashFlowEntry entry) async {
    final isAm = Localizations.localeOf(context).languageCode == 'am';
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isAm ? 'መዝገብ ይሰረዝ?' : 'Delete Entry?'),
        content: Text(
          isAm
              ? 'ይህን የ${entry.isIncome ? "ገቢ" : "ወጪ"} መዝገብ (${MoneyFormatter.formatCents(entry.amountCents)}) መሰረዝ ይፈልጋሉ?'
              : 'Are you sure you want to delete this ${entry.type} entry (${MoneyFormatter.formatCents(entry.amountCents)})?',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: Text(isAm ? 'ተመለስ' : 'Cancel'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red.shade700),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: Text(isAm ? 'አዎ ሰርዝ' : 'Delete'),
          ),
        ],
      ),
    );

    if (confirm == true) {
      await widget.repository.deleteCashFlowEntry(entry.id);
      widget.onMutation?.call();
      await _loadData();
    }
  }

  IconData _getCategoryIcon(String category) {
    final cat = category.toLowerCase();
    if (cat.contains('ኪራይ') || cat.contains('rent')) return Icons.home_work_outlined;
    if (cat.contains('ደመወዝ') || cat.contains('salary') || cat.contains('wage')) return Icons.badge_outlined;
    if (cat.contains('መብራት') || cat.contains('ውሃ') || cat.contains('utilit') || cat.contains('electric')) return Icons.electric_bolt_outlined;
    if (cat.contains('ትራንስፖርት') || cat.contains('transport') || cat.contains('fuel')) return Icons.directions_car_outlined;
    if (cat.contains('ጥገና') || cat.contains('repair') || cat.contains('maint')) return Icons.build_outlined;
    if (cat.contains('ማሸጊያ') || cat.contains('pack')) return Icons.inventory_2_outlined;
    if (cat.contains('መስተንግዶ') || cat.contains('ሻይ') || cat.contains('food') || cat.contains('meal')) return Icons.coffee_outlined;
    if (cat.contains('አገልግሎት') || cat.contains('service')) return Icons.work_outline;
    if (cat.contains('ኮሚሽን') || cat.contains('commission')) return Icons.handshake_outlined;
    if (cat.contains('ቲፕ') || cat.contains('tip') || cat.contains('ስጦታ')) return Icons.redeem_outlined;
    if (cat.contains('ተመላሽ') || cat.contains('refund')) return Icons.currency_exchange_outlined;
    return Icons.attach_money_outlined;
  }

  Future<void> _showAddEntryDialog({required String initialType}) async {
    final isAm = Localizations.localeOf(context).languageCode == 'am';

    String currentType = initialType; // 'income' or 'expense'
    String selectedCategory = currentType == 'income' ? (isAm ? 'ተጨማሪ አገልግሎት' : 'Service Fee') : (isAm ? 'ኪራይ' : 'Rent');
    String paymentMethod = 'cash';
    DateTime selectedDate = DateTime.now();

    final amountCtrl = TextEditingController();
    final customCategoryCtrl = TextEditingController();
    final noteCtrl = TextEditingController();
    bool isCustomCategory = false;

    final expenseCategoriesAm = [
      '🏠 ኪራይ',
      '👷 ደመወዝ',
      '⚡ መብራት እና ውሃ',
      '🚗 ትራንስፖርት',
      '🛠 ጥገና',
      '📦 ማሸጊያ',
      '🍵 መስተንግዶ',
      '📌 ሌላ ወጪ',
    ];
    final expenseCategoriesEn = [
      '🏠 Rent',
      '👷 Salary',
      '⚡ Utilities',
      '🚗 Transport',
      '🛠 Maintenance',
      '📦 Packaging',
      '🍵 Refreshments',
      '📌 Other Expense',
    ];
    final expenseCategoriesOm = [
      '🏠 Kiraa',
      '👷 Mindaa',
      '⚡ Ibsaa fi Bishaan',
      '🚗 Geejjiba',
      '🛠 Suphaa',
      '📦 Qophii Meeshaa',
      '🍵 Keessummeessuu',
      '📌 Baasii Biraa',
    ];

    final incomeCategoriesAm = [
      '💼 ተጨማሪ አገልግሎት',
      '🤝 ኮሚሽን',
      '🎁 ስጦታ',
      '🔄 ተመላሽ ገንዘብ',
      '📌 ሌላ ገቢ',
    ];
    final incomeCategoriesEn = [
      '💼 Service Fee',
      '🤝 Commission',
      '🎁 Tip or Gift',
      '🔄 Refund',
      '📌 Other Income',
    ];
    final incomeCategoriesOm = [
      '💼 Tajaajila Dabalataa',
      '🤝 Koomishinii',
      '🎁 Kennaa',
      '🔄 Deebii Qarshii',
      '📌 Galii Biraa',
    ];

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setModalState) {
          final isDark = Theme.of(ctx).brightness == Brightness.dark;
          final isOm = Localizations.localeOf(ctx).languageCode == 'om';
          final categories = currentType == 'income'
              ? (isOm ? incomeCategoriesOm : (isAm ? incomeCategoriesAm : incomeCategoriesEn))
              : (isOm ? expenseCategoriesOm : (isAm ? expenseCategoriesAm : expenseCategoriesEn));

          return Padding(
            padding: EdgeInsets.only(
              left: 20,
              right: 20,
              top: 20,
              bottom: MediaQuery.of(ctx).viewInsets.bottom + 20,
            ),
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        currentType == 'income'
                            ? (isAm ? '➕ አዲስ ገቢ መዝግብ' : '➕ Record Extra Income')
                            : (isAm ? '➖ አዲስ ወጪ መዝግብ' : '➖ Record Extra Expense'),
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 17),
                      ),
                      IconButton(
                        icon: const Icon(Icons.close),
                        onPressed: () => Navigator.of(ctx).pop(),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),

                  // Income / Expense Toggle
                  SegmentedButton<String>(
                    segments: [
                      ButtonSegment(
                        value: 'income',
                        label: Text(isAm ? '🟢 ገቢ' : '🟢 Income'),
                      ),
                      ButtonSegment(
                        value: 'expense',
                        label: Text(isAm ? '🔴 ወጪ' : '🔴 Expense'),
                      ),
                    ],
                    selected: {currentType},
                    onSelectionChanged: (val) {
                      setModalState(() {
                        currentType = val.first;
                        isCustomCategory = false;
                        selectedCategory = currentType == 'income'
                            ? (isAm ? 'ተጨማሪ አገልግሎት' : 'Service Fee')
                            : (isAm ? 'ኪራይ' : 'Rent');
                      });
                    },
                  ),
                  const SizedBox(height: 16),

                  // Amount Input
                  TextField(
                    controller: amountCtrl,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    autofocus: true,
                    style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold),
                    decoration: InputDecoration(
                      labelText: isAm ? 'የገንዘብ መጠን (ብር) *' : 'Amount in ETB *',
                      prefixText: 'ETB ',
                      prefixStyle: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                  ),
                  const SizedBox(height: 16),

                  // Category Selection
                  Text(
                    isAm ? 'የምድብ ዓይነት ምረጥ:' : 'Select Category:',
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      color: Theme.of(ctx).colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: categories.map((cat) {
                      final isSelected = !isCustomCategory && selectedCategory == cat;
                      return ChoiceChip(
                        label: Text(cat),
                        selected: isSelected,
                        onSelected: (selected) {
                          setModalState(() {
                            if (cat.contains('ሌላ') || cat.contains('Other')) {
                              isCustomCategory = true;
                              selectedCategory = cat;
                            } else {
                              isCustomCategory = false;
                              selectedCategory = cat;
                            }
                          });
                        },
                      );
                    }).toList(),
                  ),

                  if (isCustomCategory) ...[
                    const SizedBox(height: 10),
                    TextField(
                      controller: customCategoryCtrl,
                      decoration: InputDecoration(
                        labelText: isAm ? 'የምድቡ ስም (ለምሳሌ፡ የኮሚቴ ክፍያ)' : 'Custom Category Name',
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                      ),
                    ),
                  ],

                  const SizedBox(height: 16),

                  // Payment Method
                  Text(
                    isAm ? 'የክፍያ ዘዴ:' : 'Payment Method:',
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      color: Theme.of(ctx).colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: 8),
                  SegmentedButton<String>(
                    segments: [
                      ButtonSegment(value: 'cash', label: Text(isAm ? '💵 ጥሬ ገንዘብ' : '💵 Cash')),
                      ButtonSegment(value: 'bank', label: Text(isAm ? '🏦 ባንክ' : '🏦 Bank')),
                      ButtonSegment(value: 'wallet', label: Text(isAm ? '📱 ዋሌት' : '📱 Wallet')),
                    ],
                    selected: {paymentMethod},
                    onSelectionChanged: (val) => setModalState(() => paymentMethod = val.first),
                  ),

                  const SizedBox(height: 14),

                  // Optional Note
                  TextField(
                    controller: noteCtrl,
                    decoration: InputDecoration(
                      labelText: Localizations.localeOf(context).languageCode == 'om'
                          ? 'Yaadannoo (Filannoo)'
                          : (isAm ? 'ማስታወሻ (አማራጭ)' : 'Note (Optional)'),
                      prefixIcon: const Icon(Icons.note_alt_outlined, size: 20),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                    ),
                  ),

                  const SizedBox(height: 14),

                  // Date Picker Row
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        isAm ? 'ቀን:' : 'Date:',
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                      TextButton.icon(
                        icon: const Icon(Icons.calendar_today, size: 16),
                        label: Text(DateFormat('yyyy-MM-dd').format(selectedDate)),
                        onPressed: () async {
                          final picked = await showDatePicker(
                            context: ctx,
                            initialDate: selectedDate,
                            firstDate: DateTime(2023),
                            lastDate: DateTime.now().add(const Duration(days: 1)),
                          );
                          if (picked != null) {
                            setModalState(() {
                              selectedDate = DateTime(
                                picked.year,
                                picked.month,
                                picked.day,
                                selectedDate.hour,
                                selectedDate.minute,
                              );
                            });
                          }
                        },
                      ),
                    ],
                  ),

                  const SizedBox(height: 16),

                  // Save Button
                  FilledButton(
                    style: FilledButton.styleFrom(
                      minimumSize: const Size.fromHeight(48),
                      backgroundColor: currentType == 'income'
                          ? (isDark ? const Color(0xFF10B981) : Colors.green.shade700)
                          : (isDark ? const Color(0xFFEF4444) : Colors.red.shade700),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                    onPressed: () async {
                      final amountCents = MoneyFormatter.parseCents(amountCtrl.text);
                      if (amountCents <= 0) {
                        ScaffoldMessenger.of(ctx).showSnackBar(
                          SnackBar(
                            content: Text(isAm ? 'እባክዎ ትክክለኛ የገንዘብ መጠን ያስገቡ!' : 'Please enter a valid amount!'),
                            backgroundColor: Colors.red,
                          ),
                        );
                        return;
                      }

                      String finalCategory = selectedCategory;
                      if (isCustomCategory && customCategoryCtrl.text.trim().isNotEmpty) {
                        finalCategory = customCategoryCtrl.text.trim();
                      }

                      // Strip leading emojis from predefined tags if needed
                      final cleanCategory = finalCategory.replaceAll(RegExp(r'^[^\w\s\u1200-\u137F]+'), '').trim();

                      await widget.repository.addCashFlowEntry(
                        type: currentType,
                        amountCents: amountCents,
                        category: cleanCategory.isNotEmpty ? cleanCategory : finalCategory,
                        paymentMethod: paymentMethod,
                        note: noteCtrl.text.trim().isNotEmpty ? noteCtrl.text.trim() : null,
                        createdAtMs: selectedDate.toUtc().millisecondsSinceEpoch,
                      );

                      widget.onMutation?.call();
                      if (ctx.mounted) Navigator.of(ctx).pop();
                      await _loadData();
                    },
                    child: Text(
                      isAm ? 'መዝግብ' : 'Save',
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _buildSummaryCards(bool isAm, bool isDark) {
    final incomeTokens = AppColors.successTokens(isDark);
    final expenseTokens = AppColors.dangerTokens(isDark);
    final netTokens = _summary.netCents >= 0
        ? AppColors.infoTokens(isDark)
        : AppColors.warningTokens(isDark);

    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: 0.45),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Theme.of(context).dividerColor.withValues(alpha: 0.15)),
      ),
      child: Column(
        children: [
          Row(
            children: [
              // Income
              Expanded(
                child: Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: incomeTokens.bg,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: incomeTokens.border),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Icon(Icons.arrow_downward, size: 14, color: incomeTokens.fg),
                          const SizedBox(width: 4),
                          Text(
                            isAm ? 'ጠቅላላ ገቢ' : 'Total Income',
                            style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: incomeTokens.fg),
                          ),
                        ],
                      ),
                      const SizedBox(height: 4),
                      FittedBox(
                        fit: BoxFit.scaleDown,
                        child: Text(
                          '+${MoneyFormatter.formatCents(_summary.totalIncomeCents)}',
                          style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: incomeTokens.fg),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(width: 8),

              // Expense
              Expanded(
                child: Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: expenseTokens.bg,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: expenseTokens.border),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Icon(Icons.arrow_upward, size: 14, color: expenseTokens.fg),
                          const SizedBox(width: 4),
                          Text(
                            isAm ? 'ጠቅላላ ወጪ' : 'Total Expense',
                            style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: expenseTokens.fg),
                          ),
                        ],
                      ),
                      const SizedBox(height: 4),
                      FittedBox(
                        fit: BoxFit.scaleDown,
                        child: Text(
                          '-${MoneyFormatter.formatCents(_summary.totalExpenseCents)}',
                          style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: expenseTokens.fg),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(width: 8),

              // Net Flow
              Expanded(
                child: Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: netTokens.bg,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: netTokens.border),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Icon(Icons.account_balance, size: 14, color: netTokens.fg),
                          const SizedBox(width: 4),
                          Text(
                            isAm ? 'የተጣራ ቀሪ' : 'Net Flow',
                            style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: netTokens.fg),
                          ),
                        ],
                      ),
                      const SizedBox(height: 4),
                      FittedBox(
                        fit: BoxFit.scaleDown,
                        child: Text(
                          MoneyFormatter.formatCents(_summary.netCents),
                          style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: netTokens.fg),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildDateFilterBar(bool isAm) {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
      child: Row(
        children: [
          _rangeChip(CashFlowRange.today, isAm ? 'ዛሬ' : 'Today'),
          const SizedBox(width: 6),
          _rangeChip(CashFlowRange.yesterday, isAm ? 'ትናንት' : 'Yesterday'),
          const SizedBox(width: 6),
          _rangeChip(CashFlowRange.week, isAm ? 'ይህ ሳምንት' : 'This Week'),
          const SizedBox(width: 6),
          _rangeChip(CashFlowRange.month, isAm ? 'ይህ ወር' : 'This Month'),
          const SizedBox(width: 6),
          _rangeChip(CashFlowRange.all, isAm ? 'ሁሉንም' : 'All Time'),
          const SizedBox(width: 6),
          ActionChip(
            avatar: const Icon(Icons.date_range, size: 16),
            label: Text(
              _customDateRange != null
                  ? '${DateFormat('MM/dd').format(_customDateRange!.start)} - ${DateFormat('MM/dd').format(_customDateRange!.end)}'
                  : (isAm ? 'ብጁ ቀን' : 'Custom'),
            ),
            onPressed: () async {
              final picked = await showDateRangePicker(
                context: context,
                firstDate: DateTime(2023),
                lastDate: DateTime.now().add(const Duration(days: 1)),
                initialDateRange: _customDateRange ??
                    DateTimeRange(
                      start: DateTime.now().subtract(const Duration(days: 7)),
                      end: DateTime.now(),
                    ),
              );
              if (picked != null) {
                setState(() {
                  _customDateRange = picked;
                  _selectedRange = CashFlowRange.custom;
                });
                _loadData();
              }
            },
          ),
        ],
      ),
    );
  }

  Widget _rangeChip(CashFlowRange range, String label) {
    final isSelected = _selectedRange == range;
    return ChoiceChip(
      label: Text(label, style: const TextStyle(fontSize: 12)),
      selected: isSelected,
      onSelected: (selected) {
        if (selected) {
          setState(() {
            _selectedRange = range;
            if (range != CashFlowRange.custom) _customDateRange = null;
          });
          _loadData();
        }
      },
    );
  }

  Widget _buildTypeFilterTabs(bool isAm) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
      child: SegmentedButton<String>(
        segments: [
          ButtonSegment(
            value: 'all',
            label: Text(isAm ? 'ሁሉም (${_entries.length})' : 'All (${_entries.length})'),
          ),
          ButtonSegment(
            value: 'income',
            label: Text(isAm ? '🟢 ገቢ' : '🟢 Income'),
          ),
          ButtonSegment(
            value: 'expense',
            label: Text(isAm ? '🔴 ወጪ' : '🔴 Expense'),
          ),
        ],
        selected: {_selectedTypeFilter},
        onSelectionChanged: (val) {
          setState(() => _selectedTypeFilter = val.first);
          _loadData();
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isAm = Localizations.localeOf(context).languageCode == 'am';
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      appBar: AppBar(
        title: Text(isAm ? '💸 ገቢ እና ወጪ (ተጨማሪ)' : '💸 Extra Income & Expense'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: isAm ? 'አድስ' : 'Refresh',
            onPressed: _loadData,
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                _buildDateFilterBar(isAm),
                _buildSummaryCards(isAm, isDark),
                _buildTypeFilterTabs(isAm),
                const SizedBox(height: 4),
                Expanded(
                  child: _entries.isEmpty
                      ? EmptyStateWidget(
                          icon: Icons.account_balance_wallet_outlined,
                          title: isAm ? 'ምንም ገቢ ወይም ወጪ አልተመዘገበም' : 'No Extra Income or Expenses',
                          description: isAm
                              ? 'ለዚህ ጊዜ የተመዘገበ የኪራይ፣ የደመወዝ ወይም ተጨማሪ ገቢ የለም።'
                              : 'No extra non-inventory income or expenses recorded for this period.',
                          primaryButtonLabel: isAm ? '➕ ገቢ መዝግብ' : '➕ Add Income',
                          onPrimaryPressed: () => _showAddEntryDialog(initialType: 'income'),
                          secondaryButtonLabel: isAm ? '➖ ወጪ መዝግብ' : '➖ Add Expense',
                          onSecondaryPressed: () => _showAddEntryDialog(initialType: 'expense'),
                        )
                      : ListView.separated(
                          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                          itemCount: _entries.length,
                          separatorBuilder: (_, __) => const SizedBox(height: 8),
                          itemBuilder: (ctx, idx) {
                            final entry = _entries[idx];
                            final isIncome = entry.isIncome;
                            final tokens = isIncome
                                ? AppColors.successTokens(isDark)
                                : AppColors.dangerTokens(isDark);
                            final date = DateTime.fromMillisecondsSinceEpoch(entry.createdAtMs).toLocal();
                            final dateStr = DateFormat('yyyy-MM-dd  HH:mm').format(date);

                            String methodLabel = isAm ? 'ጥሬ ገንዘብ' : 'Cash';
                            if (entry.paymentMethod == 'bank') {
                              methodLabel = isAm ? 'ባንክ' : 'Bank';
                            } else if (entry.paymentMethod == 'wallet') {
                              methodLabel = isAm ? 'ዋሌት' : 'Wallet';
                            }

                            return Container(
                              padding: const EdgeInsets.all(12),
                              decoration: BoxDecoration(
                                color: Theme.of(context).colorScheme.surface,
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(
                                  color: Theme.of(context).dividerColor.withValues(alpha: 0.15),
                                ),
                              ),
                              child: Row(
                                children: [
                                  Container(
                                    width: 42,
                                    height: 42,
                                    decoration: BoxDecoration(
                                      color: tokens.bg,
                                      shape: BoxShape.circle,
                                      border: Border.all(color: tokens.border),
                                    ),
                                    child: Icon(
                                      _getCategoryIcon(entry.category),
                                      color: tokens.fg,
                                      size: 22,
                                    ),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          entry.category,
                                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                        ),
                                        const SizedBox(height: 2),
                                        Row(
                                          children: [
                                            Container(
                                              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                                              decoration: BoxDecoration(
                                                color: Theme.of(context).colorScheme.surfaceContainerHighest,
                                                borderRadius: BorderRadius.circular(4),
                                              ),
                                              child: Text(
                                                methodLabel,
                                                style: TextStyle(
                                                  fontSize: 10,
                                                  fontWeight: FontWeight.w600,
                                                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                                                ),
                                              ),
                                            ),
                                            const SizedBox(width: 6),
                                            Text(
                                              dateStr,
                                              style: TextStyle(
                                                fontSize: 11,
                                                color: Theme.of(context).colorScheme.onSurfaceVariant,
                                              ),
                                            ),
                                          ],
                                        ),
                                        if (entry.note != null && entry.note!.isNotEmpty) ...[
                                          const SizedBox(height: 4),
                                          Text(
                                            entry.note!,
                                            style: TextStyle(
                                              fontSize: 11.5,
                                              fontStyle: FontStyle.italic,
                                              color: Theme.of(context).colorScheme.onSurfaceVariant,
                                            ),
                                          ),
                                        ],
                                      ],
                                    ),
                                  ),
                                  Column(
                                    crossAxisAlignment: CrossAxisAlignment.end,
                                    children: [
                                      Text(
                                        '${isIncome ? '+' : '-'}${MoneyFormatter.formatCents(entry.amountCents)}',
                                        style: TextStyle(
                                          fontSize: 15,
                                          fontWeight: FontWeight.bold,
                                          color: tokens.fg,
                                        ),
                                      ),
                                      IconButton(
                                        icon: const Icon(Icons.delete_outline, size: 18, color: Colors.grey),
                                        tooltip: isAm ? 'ሰርዝ' : 'Delete',
                                        onPressed: () => _deleteEntry(entry),
                                        visualDensity: VisualDensity.compact,
                                      ),
                                    ],
                                  ),
                                ],
                              ),
                            );
                          },
                        ),
                ),
              ],
            ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(
            children: [
              Expanded(
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(
                    backgroundColor: isDark ? const Color(0xFF10B981) : Colors.green.shade700,
                    minimumSize: const Size.fromHeight(48),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                  onPressed: () => _showAddEntryDialog(initialType: 'income'),
                  icon: const Icon(Icons.add, size: 20),
                  label: Text(
                    isAm ? '➕ ገቢ መዝግብ' : '➕ Add Income',
                    style: const TextStyle(fontWeight: FontWeight.bold),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(
                    backgroundColor: isDark ? const Color(0xFFEF4444) : Colors.red.shade700,
                    minimumSize: const Size.fromHeight(48),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                  onPressed: () => _showAddEntryDialog(initialType: 'expense'),
                  icon: const Icon(Icons.remove, size: 20),
                  label: Text(
                    isAm ? '➖ ወጪ መዝግብ' : '➖ Add Expense',
                    style: const TextStyle(fontWeight: FontWeight.bold),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
