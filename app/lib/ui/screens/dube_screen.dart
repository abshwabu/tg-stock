import 'package:drift/drift.dart' show OrderingTerm, OrderingMode;
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:share_plus/share_plus.dart';

import '../../database/app_database.dart';
import '../../repositories/ledger_repository.dart';
import '../../utils/money_formatter.dart';
import '../../utils/phone_validator.dart';

class DubeScreen extends StatefulWidget {
  final LedgerRepository repository;
  final VoidCallback? onMutation;

  const DubeScreen({
    super.key,
    required this.repository,
    this.onMutation,
  });

  @override
  State<DubeScreen> createState() => _DubeScreenState();
}

class _DubeScreenState extends State<DubeScreen> {
  final _searchCtrl = TextEditingController();
  List<CustomerCreditProfile> _profiles = [];
  bool _isLoading = true;
  String _shopName = 'Shop';

  @override
  void initState() {
    super.initState();
    _loadDubeData();
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadDubeData() async {
    setState(() => _isLoading = true);
    final sName = await widget.repository.getShopName();
    final customers = await widget.repository.listCustomers(search: _searchCtrl.text);

    final profiles = <CustomerCreditProfile>[];
    for (final c in customers) {
      final prof = await widget.repository.customerCredit(c.id);
      profiles.add(prof);
    }

    // Sort: Overdue first (descending maxDaysOverdue), then highest totalDebtCents first
    profiles.sort((a, b) {
      if (a.maxDaysOverdue > 0 || b.maxDaysOverdue > 0) {
        if (a.maxDaysOverdue != b.maxDaysOverdue) {
          return b.maxDaysOverdue.compareTo(a.maxDaysOverdue);
        }
      }
      return b.totalDebtCents.compareTo(a.totalDebtCents);
    });

    if (mounted) {
      setState(() {
        _shopName = sName;
        _profiles = profiles;
        _isLoading = false;
      });
    }
  }

  Future<void> _addNewCustomer() async {
    final nameCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final noteCtrl = TextEditingController();

    final added = await showDialog<Customer>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) {
          String? warning;
          final norm = PhoneValidator.normalize(phoneCtrl.text);
          if (norm != null) {
            final exists = _profiles.any((p) => p.customerName.toLowerCase() == nameCtrl.text.trim().toLowerCase());
            if (exists) {
              warning = 'Warning: A customer with this name or number may already exist.';
            }
          }

          return AlertDialog(
            title: const Text('Add Customer / ደንበኛ መዝግብ'),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                    controller: nameCtrl,
                    autofocus: true,
                    decoration: const InputDecoration(
                      labelText: 'Full Name *',
                      hintText: 'Abebe Kebede',
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: phoneCtrl,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(
                      labelText: 'Phone (09... or 07...)',
                      hintText: '0911223344',
                    ),
                    onChanged: (_) => setDlgState(() {}),
                  ),
                  if (warning != null) ...[
                    const SizedBox(height: 6),
                    Text(warning, style: const TextStyle(color: Colors.orange, fontSize: 12)),
                  ],
                  const SizedBox(height: 12),
                  TextField(
                    controller: noteCtrl,
                    decoration: const InputDecoration(
                      labelText: 'Note (optional)',
                      hintText: 'Address or landmark',
                    ),
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Cancel')),
              FilledButton(
                onPressed: () async {
                  final name = nameCtrl.text.trim();
                  if (name.isEmpty) return;
                  final cust = await widget.repository.upsertCustomer(
                    name: name,
                    phone: phoneCtrl.text.trim(),
                    note: noteCtrl.text.trim(),
                  );
                  if (ctx.mounted) Navigator.of(ctx).pop(cust);
                },
                child: const Text('Save / መዝግብ'),
              ),
            ],
          );
        },
      ),
    );

    if (added != null) {
      widget.onMutation?.call();
      await _loadDubeData();
    }
  }

  void _openCustomerDetail(CustomerCreditProfile profile) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => _CustomerDetailSheet(
        profile: profile,
        shopName: _shopName,
        repository: widget.repository,
        onChanged: () {
          widget.onMutation?.call();
          _loadDubeData();
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final totalOutstanding = _profiles.fold<int>(0, (sum, p) => sum + (p.totalDebtCents > 0 ? p.totalDebtCents : 0));
    final overdueCount = _profiles.where((p) => p.overdueCents > 0).length;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Dube & Customers / ዱቤ እና ደንበኞች'),
        actions: [
          IconButton(
            icon: const Icon(Icons.person_add),
            tooltip: 'Add Customer',
            onPressed: _addNewCustomer,
          ),
        ],
      ),
      body: Column(
        children: [
          // KPI Summary Header
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            color: Colors.grey.shade100,
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Total Outstanding Dube',
                        style: TextStyle(fontSize: 12, color: Colors.grey, fontWeight: FontWeight.bold),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        MoneyFormatter.formatCents(totalOutstanding),
                        style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Colors.deepOrange),
                      ),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                    color: overdueCount > 0 ? Colors.red.shade100 : Colors.green.shade100,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Row(
                    children: [
                      Icon(
                        overdueCount > 0 ? Icons.warning_amber_rounded : Icons.check_circle_outline,
                        size: 16,
                        color: overdueCount > 0 ? Colors.red.shade900 : Colors.green.shade900,
                      ),
                      const SizedBox(width: 4),
                      Text(
                        overdueCount > 0 ? '$overdueCount Overdue' : 'All on track',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                          color: overdueCount > 0 ? Colors.red.shade900 : Colors.green.shade900,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),

          // Search Bar
          Padding(
            padding: const EdgeInsets.all(12),
            child: TextField(
              controller: _searchCtrl,
              decoration: InputDecoration(
                hintText: 'Search customer by name or phone...',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: _searchCtrl.text.isNotEmpty
                    ? IconButton(
                        icon: const Icon(Icons.clear),
                        onPressed: () {
                          _searchCtrl.clear();
                          _loadDubeData();
                        },
                      )
                    : null,
                isDense: true,
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
              ),
              onChanged: (_) => _loadDubeData(),
            ),
          ),

          // List of Customers
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : _profiles.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const Icon(Icons.people_outline, size: 64, color: Colors.grey),
                            const SizedBox(height: 12),
                            const Text(
                              'No customers found',
                              style: TextStyle(fontSize: 16, color: Colors.grey),
                            ),
                            const SizedBox(height: 8),
                            FilledButton.icon(
                              onPressed: _addNewCustomer,
                              icon: const Icon(Icons.person_add),
                              label: const Text('Add Customer / ደንበኛ መዝግብ'),
                            ),
                          ],
                        ),
                      )
                    : ListView.separated(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                        itemCount: _profiles.length,
                        separatorBuilder: (ctx, i) => const Divider(height: 1),
                        itemBuilder: (ctx, i) {
                          final prof = _profiles[i];
                          final isOverdue = prof.overdueCents > 0;
                          final hasCredit = prof.hasOverpayment;

                          return ListTile(
                            onTap: () => _openCustomerDetail(prof),
                            leading: CircleAvatar(
                              backgroundColor: isOverdue
                                  ? Colors.red.shade100
                                  : (hasCredit ? Colors.blue.shade100 : Colors.teal.shade50),
                              child: Icon(
                                Icons.person,
                                color: isOverdue
                                    ? Colors.red.shade900
                                    : (hasCredit ? Colors.blue.shade900 : const Color(0xFF0F766E)),
                              ),
                            ),
                            title: Row(
                              children: [
                                Expanded(
                                  child: Text(
                                    prof.customerName,
                                    style: const TextStyle(fontWeight: FontWeight.bold),
                                  ),
                                ),
                                if (isOverdue)
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                    decoration: BoxDecoration(
                                      color: Colors.red.shade100,
                                      borderRadius: BorderRadius.circular(4),
                                    ),
                                    child: Text(
                                      '${prof.maxDaysOverdue}d overdue',
                                      style: TextStyle(
                                        fontSize: 10.5,
                                        fontWeight: FontWeight.bold,
                                        color: Colors.red.shade900,
                                      ),
                                    ),
                                  ),
                              ],
                            ),
                            subtitle: Text(
                              isOverdue
                                  ? 'Overdue: ${MoneyFormatter.formatCents(prof.overdueCents)} • Open: ${prof.openSalesCount}'
                                  : (hasCredit
                                      ? 'Credit: ${MoneyFormatter.formatCents(prof.overpaymentCents)}'
                                      : 'Open sales: ${prof.openSalesCount}'),
                              style: TextStyle(
                                fontSize: 12,
                                color: isOverdue ? Colors.red.shade800 : Colors.grey.shade700,
                              ),
                            ),
                            trailing: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              crossAxisAlignment: CrossAxisAlignment.end,
                              children: [
                                Text(
                                  MoneyFormatter.formatCents(prof.netBalanceCents),
                                  style: TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 15,
                                    color: prof.netBalanceCents > 0
                                        ? (isOverdue ? Colors.red.shade900 : Colors.black87)
                                        : Colors.green.shade800,
                                  ),
                                ),
                                Text(
                                  prof.netBalanceCents > 0 ? 'Owed' : (hasCredit ? 'Credit' : 'Settled'),
                                  style: const TextStyle(fontSize: 10, color: Colors.grey),
                                ),
                              ],
                            ),
                          );
                        },
                      ),
          ),
        ],
      ),
    );
  }
}

class _CustomerDetailSheet extends StatefulWidget {
  final CustomerCreditProfile profile;
  final String shopName;
  final LedgerRepository repository;
  final VoidCallback onChanged;

  const _CustomerDetailSheet({
    required this.profile,
    required this.shopName,
    required this.repository,
    required this.onChanged,
  });

  @override
  State<_CustomerDetailSheet> createState() => _CustomerDetailSheetState();
}

class _CustomerDetailSheetState extends State<_CustomerDetailSheet> {
  Customer? _customer;
  List<OpenSaleBreakdown> _openSales = [];
  List<Payment> _customerPayments = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadStatement();
  }

  Future<void> _loadStatement() async {
    setState(() => _isLoading = true);
    final cust = await widget.repository.getCustomer(widget.profile.customerId);
    final sales = await widget.repository.openSales(widget.profile.customerId);
    final payments = await (widget.repository.db.select(widget.repository.db.payments)
          ..where((p) => p.customerId.equals(widget.profile.customerId))
          ..orderBy([(p) => OrderingTerm(expression: p.receivedAtMs, mode: OrderingMode.desc)]))
        .get();

    if (mounted) {
      setState(() {
        _customer = cust;
        _openSales = sales;
        _customerPayments = payments;
        _isLoading = false;
      });
    }
  }

  Future<void> _receivePayment() async {
    final amountCtrl = TextEditingController(
      text: (widget.profile.totalDebtCents / 100).toStringAsFixed(2),
    );
    String method = 'cash';
    PaymentProvider? selectedBank;
    PaymentProvider? selectedWallet;
    final refCtrl = TextEditingController();

    final banks = await widget.repository.listPaymentProviders(kind: 'bank', enabledOnly: true);
    final wallets = await widget.repository.listPaymentProviders(kind: 'wallet', enabledOnly: true);
    if (banks.isNotEmpty) selectedBank = banks.first;
    if (wallets.isNotEmpty) selectedWallet = wallets.first;

    if (!mounted) return;

    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheetState) {
          return Padding(
            padding: EdgeInsets.only(
              left: 20,
              right: 20,
              top: 20,
              bottom: MediaQuery.of(ctx).viewInsets.bottom + 20,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text(
                  'Receive Payment / ክፍያ ተቀበል',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 6),
                const Text(
                  '💡 Payment applies to the oldest dube first (FIFO).',
                  style: TextStyle(fontSize: 12, color: Colors.teal),
                ),
                const SizedBox(height: 14),
                TextField(
                  controller: amountCtrl,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(
                    labelText: 'Amount (ETB) *',
                    border: OutlineInputBorder(),
                    prefixText: 'ETB ',
                  ),
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    ActionChip(
                      label: const Text('Full Balance / ሙሉ ሂሳብ'),
                      onPressed: () {
                        setSheetState(() {
                          amountCtrl.text = (widget.profile.totalDebtCents / 100).toStringAsFixed(2);
                        });
                      },
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    ChoiceChip(
                      label: const Text('Cash'),
                      selected: method == 'cash',
                      onSelected: (v) => setSheetState(() => method = 'cash'),
                    ),
                    const SizedBox(width: 8),
                    ChoiceChip(
                      label: const Text('Bank'),
                      selected: method == 'bank',
                      onSelected: (v) => setSheetState(() => method = 'bank'),
                    ),
                    const SizedBox(width: 8),
                    ChoiceChip(
                      label: const Text('Wallet'),
                      selected: method == 'wallet',
                      onSelected: (v) => setSheetState(() => method = 'wallet'),
                    ),
                  ],
                ),
                if (method == 'bank' && banks.isNotEmpty) ...[
                  const SizedBox(height: 10),
                  DropdownButtonFormField<PaymentProvider>(
                    initialValue: selectedBank,
                    decoration: const InputDecoration(labelText: 'Bank', border: OutlineInputBorder()),
                    items: banks.map((b) => DropdownMenuItem(value: b, child: Text(b.name ?? b.providerKey))).toList(),
                    onChanged: (v) => setSheetState(() => selectedBank = v),
                  ),
                ],
                if (method == 'wallet' && wallets.isNotEmpty) ...[
                  const SizedBox(height: 10),
                  DropdownButtonFormField<PaymentProvider>(
                    initialValue: selectedWallet,
                    decoration: const InputDecoration(labelText: 'Wallet', border: OutlineInputBorder()),
                    items: wallets.map((w) => DropdownMenuItem(value: w, child: Text(w.name ?? w.providerKey))).toList(),
                    onChanged: (v) => setSheetState(() => selectedWallet = v),
                  ),
                ],
                if (method != 'cash') ...[
                  const SizedBox(height: 10),
                  TextField(
                    controller: refCtrl,
                    decoration: const InputDecoration(
                      labelText: 'Reference / Tx Number',
                      border: OutlineInputBorder(),
                    ),
                  ),
                ],
                const SizedBox(height: 16),
                FilledButton(
                  style: FilledButton.styleFrom(backgroundColor: Colors.deepOrange, padding: const EdgeInsets.symmetric(vertical: 14)),
                  onPressed: () async {
                    final val = double.tryParse(amountCtrl.text) ?? 0;
                    if (val <= 0) return;
                    final amountCents = (val * 100).round();

                    String? pKey;
                    if (method == 'bank') pKey = selectedBank?.providerKey;
                    if (method == 'wallet') pKey = selectedWallet?.providerKey;

                    await widget.repository.receiveCustomerPayment(
                      customerId: widget.profile.customerId,
                      amountCents: amountCents,
                      method: method,
                      providerKey: pKey,
                      reference: refCtrl.text.trim().isNotEmpty ? refCtrl.text.trim() : null,
                    );
                    if (ctx.mounted) Navigator.of(ctx).pop(true);
                  },
                  child: const Text('Record Payment / መዝግብ'),
                ),
              ],
            ),
          );
        },
      ),
    );

    if (confirmed == true) {
      widget.onChanged();
      _loadStatement();
    }
  }

  Future<void> _extendDueDate(OpenSaleBreakdown sale) async {
    final current = sale.dueDate != null ? DateTime.tryParse(sale.dueDate!) : DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: current ?? DateTime.now(),
      firstDate: DateTime.now(),
      lastDate: DateTime.now().add(const Duration(days: 365)),
    );

    if (picked != null) {
      final newDueStr = DateFormat('yyyy-MM-dd').format(picked);
      await widget.repository.extendSaleDueDate(sale.sale.id, newDueStr);
      widget.onChanged();
      _loadStatement();
    }
  }

  void _shareReminder() {
    final cust = _customer;
    if (cust == null) return;

    final dueDateStr = widget.profile.oldestDueDate ?? 'soon';
    final amountStr = MoneyFormatter.formatCents(widget.profile.totalDebtCents);

    final text = 'Dear ${cust.name}, you owe $amountStr due $dueDateStr. ${widget.shopName}';
    SharePlus.instance.share(ShareParams(text: text));
  }

  Future<void> _editCustomer() async {
    final cust = _customer;
    if (cust == null) return;

    final nameCtrl = TextEditingController(text: cust.name);
    final phoneCtrl = TextEditingController(text: cust.phone ?? '');
    final noteCtrl = TextEditingController(text: cust.note ?? '');

    final updated = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Edit Customer / ደንበኛ አርም'),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: nameCtrl,
                decoration: const InputDecoration(labelText: 'Name'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: phoneCtrl,
                decoration: const InputDecoration(labelText: 'Phone'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: noteCtrl,
                decoration: const InputDecoration(labelText: 'Note'),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancel')),
          FilledButton(
            onPressed: () async {
              await widget.repository.upsertCustomer(
                id: cust.id,
                name: nameCtrl.text.trim(),
                phone: phoneCtrl.text.trim(),
                note: noteCtrl.text.trim(),
              );
              if (ctx.mounted) Navigator.of(ctx).pop(true);
            },
            child: const Text('Save'),
          ),
        ],
      ),
    );

    if (updated == true) {
      widget.onChanged();
      _loadStatement();
    }
  }

  Future<void> _deleteCustomer() async {
    try {
      await widget.repository.deleteCustomer(widget.profile.customerId);
      if (mounted) {
        Navigator.of(context).pop();
        widget.onChanged();
      }
    } catch (e) {
      if (mounted) {
        showDialog(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('Cannot Delete / ማጥፋት አይቻልም'),
            content: Text(e.toString()),
            actions: [
              FilledButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('OK')),
            ],
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return const SizedBox(height: 300, child: Center(child: CircularProgressIndicator()));
    }

    final cust = _customer;
    if (cust == null) return const SizedBox.shrink();

    final debtFormatted = MoneyFormatter.formatCents(widget.profile.totalDebtCents);

    return Container(
      constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.85),
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Header
          Row(
            children: [
              CircleAvatar(
                radius: 22,
                backgroundColor: const Color(0xFF0F766E).withValues(alpha: 0.15),
                child: const Icon(Icons.person, color: Color(0xFF0F766E), size: 26),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      cust.name,
                      style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                    ),
                    if (cust.phone != null)
                      Text(
                        cust.phone!,
                        style: const TextStyle(color: Colors.grey, fontSize: 13),
                      ),
                  ],
                ),
              ),
              IconButton(
                icon: const Icon(Icons.edit_outlined),
                onPressed: _editCustomer,
              ),
              IconButton(
                icon: const Icon(Icons.delete_outline, color: Colors.red),
                onPressed: _deleteCustomer,
              ),
            ],
          ),
          const SizedBox(height: 14),

          // Balance Card
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: widget.profile.overdueCents > 0 ? Colors.red.shade50 : Colors.teal.shade50,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(
                color: widget.profile.overdueCents > 0 ? Colors.red.shade300 : Colors.teal.shade300,
              ),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Total Debt / ጠቅላላ ዕዳ', style: TextStyle(fontSize: 12, color: Colors.grey)),
                    const SizedBox(height: 2),
                    Text(
                      debtFormatted,
                      style: TextStyle(
                        fontSize: 20,
                        fontWeight: FontWeight.bold,
                        color: widget.profile.overdueCents > 0 ? Colors.red.shade900 : const Color(0xFF0F766E),
                      ),
                    ),
                  ],
                ),
                if (widget.profile.hasOverpayment)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(color: Colors.blue.shade100, borderRadius: BorderRadius.circular(6)),
                    child: Text(
                      'Credit: ${MoneyFormatter.formatCents(widget.profile.overpaymentCents)}',
                      style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.blue.shade900),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 12),

          // Action Buttons: Receive Payment & Share Reminder
          Row(
            children: [
              Expanded(
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(
                    backgroundColor: Colors.deepOrange,
                    padding: const EdgeInsets.symmetric(vertical: 12),
                  ),
                  onPressed: _receivePayment,
                  icon: const Icon(Icons.payments_outlined),
                  label: const Text('Receive Payment'),
                ),
              ),
              const SizedBox(width: 8),
              OutlinedButton.icon(
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 12),
                ),
                onPressed: _shareReminder,
                icon: const Icon(Icons.share, size: 18),
                label: const Text('Share Reminder'),
              ),
            ],
          ),
          const Divider(height: 24),

          // Statement (Open Sales & Payments)
          Expanded(
            child: DefaultTabController(
              length: 2,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  TabBar(
                    labelColor: const Color(0xFF0F766E),
                    unselectedLabelColor: Colors.grey,
                    indicatorColor: const Color(0xFF0F766E),
                    tabs: [
                      Tab(text: 'Open Sales (${_openSales.length})'),
                      Tab(text: 'Payments (${_customerPayments.length})'),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Expanded(
                    child: TabBarView(
                      children: [
                        // Tab 1: Open Sales
                        _openSales.isEmpty
                            ? const Center(
                                child: Text('No open sales for this customer.', style: TextStyle(color: Colors.grey)),
                              )
                            : ListView.builder(
                                itemCount: _openSales.length,
                                itemBuilder: (ctx, idx) {
                                  final item = _openSales[idx];
                                  final isOverdue = item.isOverdue;
                                  return Card(
                                    elevation: 1,
                                    margin: const EdgeInsets.symmetric(vertical: 4),
                                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                                    child: Padding(
                                      padding: const EdgeInsets.all(10),
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          Row(
                                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                            children: [
                                              Text(
                                                'Sale: ${MoneyFormatter.formatCents(item.totalCents)}',
                                                style: const TextStyle(fontWeight: FontWeight.bold),
                                              ),
                                              Text(
                                                'Remaining: ${MoneyFormatter.formatCents(item.remainingBalanceCents)}',
                                                style: TextStyle(
                                                  fontWeight: FontWeight.bold,
                                                  color: isOverdue ? Colors.red.shade900 : Colors.deepOrange,
                                                ),
                                              ),
                                            ],
                                          ),
                                          const SizedBox(height: 4),
                                          Row(
                                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                            children: [
                                              Text(
                                                'Due: ${item.dueDate ?? "No due date"}',
                                                style: TextStyle(fontSize: 12, color: isOverdue ? Colors.red : Colors.grey),
                                              ),
                                              TextButton(
                                                style: TextButton.styleFrom(padding: EdgeInsets.zero, visualDensity: VisualDensity.compact),
                                                onPressed: () => _extendDueDate(item),
                                                child: const Text('Extend due date', style: TextStyle(fontSize: 12)),
                                              ),
                                            ],
                                          ),
                                        ],
                                      ),
                                    ),
                                  );
                                },
                              ),
                        // Tab 2: Payments
                        _customerPayments.isEmpty
                            ? const Center(
                                child: Text('No payments recorded yet.', style: TextStyle(color: Colors.grey)),
                              )
                            : ListView.builder(
                                itemCount: _customerPayments.length,
                                itemBuilder: (ctx, idx) {
                                  final p = _customerPayments[idx];
                                  final dateStr = DateFormat('dd MMM yyyy, HH:mm').format(
                                    DateTime.fromMillisecondsSinceEpoch(p.receivedAtMs).toLocal(),
                                  );
                                  final isReversal = p.amountCents < 0;
                                  return Card(
                                    elevation: 1,
                                    margin: const EdgeInsets.symmetric(vertical: 4),
                                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                                    child: ListTile(
                                      dense: true,
                                      leading: Icon(
                                        isReversal ? Icons.replay : Icons.check_circle_outline,
                                        color: isReversal ? Colors.red : Colors.teal,
                                      ),
                                      title: Text(
                                        '${MoneyFormatter.formatCents(p.amountCents)} • ${p.method.toUpperCase()}',
                                        style: TextStyle(
                                          fontWeight: FontWeight.bold,
                                          color: isReversal ? Colors.red : Colors.black87,
                                        ),
                                      ),
                                      subtitle: Text(
                                        [
                                          dateStr,
                                          if (p.providerKey != null) p.providerKey,
                                          if (p.reference != null && p.reference!.isNotEmpty) 'Ref: ${p.reference}',
                                        ].join(' • '),
                                        style: const TextStyle(fontSize: 11),
                                      ),
                                    ),
                                  );
                                },
                              ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
