import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../database/app_database.dart';
import '../../repositories/ledger_repository.dart';
import '../../utils/money_formatter.dart';
import '../../utils/phone_validator.dart';
import '../../utils/uuid_generator.dart';
import '../theme.dart';

class PaymentStepSheet extends StatefulWidget {
  final LedgerRepository repository;
  final int totalCents;
  final List<SaleLineItem> items;
  final String? locationId;
  final VoidCallback onComplete;

  const PaymentStepSheet({
    super.key,
    required this.repository,
    required this.totalCents,
    required this.items,
    this.locationId,
    required this.onComplete,
  });

  @override
  State<PaymentStepSheet> createState() => _PaymentStepSheetState();
}

class _PaymentStepSheetState extends State<PaymentStepSheet> {
  List<String> _enabledMethods = ['cash', 'bank', 'wallet', 'dube'];
  String _selectedMethod = 'cash';
  bool _requireReference = false;
  bool _isLoading = true;

  // Cash state
  final _amountReceivedCtrl = TextEditingController();
  int _cashReceivedCents = 0;

  // Bank & Wallet state
  List<PaymentProvider> _bankProviders = [];
  List<PaymentProvider> _walletProviders = [];
  PaymentProvider? _selectedBank;
  PaymentProvider? _selectedWallet;
  final _referenceCtrl = TextEditingController();
  final _bankSearchCtrl = TextEditingController();
  final _walletSearchCtrl = TextEditingController();

  // Dube state
  Customer? _selectedCustomer;
  final _customerSearchCtrl = TextEditingController();
  List<Customer> _customerList = [];
  int _dubePaidNowCents = 0;
  String _dubePaidMethod = 'cash';
  PaymentProvider? _dubePaidBank;
  PaymentProvider? _dubePaidWallet;
  final _dubePaidRefCtrl = TextEditingController();
  late DateTime _dubeDueDate;

  @override
  void initState() {
    super.initState();
    _dubeDueDate = DateTime.now().add(const Duration(days: 14));
    _cashReceivedCents = widget.totalCents;
    _amountReceivedCtrl.text = (widget.totalCents / 100).toStringAsFixed(2);
    _loadSettingsAndProviders();
  }

  @override
  void dispose() {
    _amountReceivedCtrl.dispose();
    _referenceCtrl.dispose();
    _bankSearchCtrl.dispose();
    _walletSearchCtrl.dispose();
    _customerSearchCtrl.dispose();
    _dubePaidRefCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadSettingsAndProviders() async {
    final methods = await widget.repository.getPaymentMethodsEnabled();
    final reqRef = await widget.repository.getRequirePaymentReference();
    final defaultDays = await widget.repository.getDubeDefaultDays();
    final banks = await widget.repository.listPaymentProviders(kind: 'bank', enabledOnly: true);
    final wallets = await widget.repository.listPaymentProviders(kind: 'wallet', enabledOnly: true);
    final customers = await widget.repository.listCustomers();

    if (mounted) {
      setState(() {
        _enabledMethods = methods;
        if (!_enabledMethods.contains(_selectedMethod)) {
          _selectedMethod = _enabledMethods.isNotEmpty ? _enabledMethods.first : 'cash';
        }
        _requireReference = reqRef;
        _dubeDueDate = DateTime.now().add(Duration(days: defaultDays));
        _bankProviders = banks;
        _walletProviders = wallets;
        if (_bankProviders.isNotEmpty) {
          _selectedBank = _bankProviders.firstWhere(
            (p) => p.favorite,
            orElse: () => _bankProviders.first,
          );
        }
        if (_walletProviders.isNotEmpty) {
          _selectedWallet = _walletProviders.firstWhere(
            (p) => p.favorite,
            orElse: () => _walletProviders.first,
          );
        }
        _customerList = customers;
        _isLoading = false;
      });
    }
  }

  int get _changeDueCents {
    final diff = _cashReceivedCents - widget.totalCents;
    return diff > 0 ? diff : 0;
  }

  Future<void> _addCustomProvider(String kind) async {
    final nameCtrl = TextEditingController();
    final customName = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(kind == 'bank' ? 'Other Bank / ሌላ ባንክ' : 'Other Wallet / ሌላ ዋሌት'),
        content: TextField(
          controller: nameCtrl,
          autofocus: true,
          decoration: InputDecoration(
            labelText: kind == 'bank' ? 'Bank Name' : 'Wallet Name',
            hintText: 'e.g. Global Birr',
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(nameCtrl.text.trim()),
            child: const Text('Add / መዝግብ'),
          ),
        ],
      ),
    );

    if (customName == null || customName.isEmpty) return;

    final key = 'custom.${UuidGenerator.v7()}';
    final newProv = await widget.repository.upsertPaymentProvider(
      providerKey: key,
      kind: kind,
      name: customName,
      enabled: true,
      favorite: true,
    );

    await _loadSettingsAndProviders();
    if (mounted) {
      setState(() {
        if (kind == 'bank') {
          _selectedBank = newProv;
        } else {
          _selectedWallet = newProv;
        }
      });
    }
  }

  Future<void> _showNewCustomerDialog() async {
    final nameCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final noteCtrl = TextEditingController();

    final created = await showDialog<Customer>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) {
          String? warning;
          final norm = PhoneValidator.normalize(phoneCtrl.text);
          if (norm != null) {
            final exists = _customerList.any((c) => c.phone == norm);
            if (exists) {
              warning = 'Warning: A customer with this phone number already exists.';
            }
          }

          return AlertDialog(
            title: const Text('New Customer / አዲስ ደንበኛ'),
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
                    Text(
                      warning,
                      style: const TextStyle(color: Colors.orange, fontSize: 12),
                    ),
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

    if (created != null) {
      await _loadSettingsAndProviders();
      if (mounted) {
        setState(() {
          _selectedCustomer = created;
        });
      }
    }
  }

  Future<void> _confirmFinish() async {
    // 1. Validation
    if (_selectedMethod == 'bank') {
      if (_selectedBank == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Please select a bank.')),
        );
        return;
      }
      if (_requireReference && _referenceCtrl.text.trim().isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Transaction reference is required by shop settings.')),
        );
        return;
      }
    } else if (_selectedMethod == 'wallet') {
      if (_selectedWallet == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Please select a mobile wallet.')),
        );
        return;
      }
      if (_requireReference && _referenceCtrl.text.trim().isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Transaction reference is required by shop settings.')),
        );
        return;
      }
    } else if (_selectedMethod == 'dube') {
      if (_selectedCustomer == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Customer is required for Dube sale.')),
        );
        return;
      }
    }

    setState(() => _isLoading = true);

    try {
      final dueDateStr = DateFormat('yyyy-MM-dd').format(_dubeDueDate);

      String? providerKey;
      if (_selectedMethod == 'bank') providerKey = _selectedBank?.providerKey;
      if (_selectedMethod == 'wallet') providerKey = _selectedWallet?.providerKey;

      String? paidProviderKey;
      if (_dubePaidMethod == 'bank') paidProviderKey = _dubePaidBank?.providerKey;
      if (_dubePaidMethod == 'wallet') paidProviderKey = _dubePaidWallet?.providerKey;

      await widget.repository.recordSaleWithPayment(
        items: widget.items,
        locationId: widget.locationId,
        customerId: _selectedMethod == 'dube' ? _selectedCustomer?.id : null,
        dueDate: _selectedMethod == 'dube' ? dueDateStr : null,
        paymentMethod: _selectedMethod,
        providerKey: providerKey,
        paidAmountCents: _selectedMethod == 'dube' ? _dubePaidNowCents : widget.totalCents,
        paidMethod: _selectedMethod == 'dube' ? _dubePaidMethod : null,
        paidProviderKey: paidProviderKey,
        reference: _selectedMethod == 'dube' ? _dubePaidRefCtrl.text.trim() : _referenceCtrl.text.trim(),
      );

      if (mounted) {
        Navigator.of(context).pop();
        widget.onComplete();
      }
    } catch (e) {
      if (mounted) {
        setState(() => _isLoading = false);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(backgroundColor: Colors.red, content: Text('Error recording sale: $e')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return const Padding(
        padding: EdgeInsets.all(40),
        child: Center(child: CircularProgressIndicator()),
      );
    }

    final totalDisplay = MoneyFormatter.formatCents(widget.totalCents);

    return Container(
      constraints: BoxConstraints(
        maxHeight: MediaQuery.of(context).size.height * 0.9,
      ),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
      ),
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        top: 20,
        bottom: MediaQuery.of(context).viewInsets.bottom + 20,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Total Header
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Expanded(
                child: Text(
                  'Complete Sale / ሽያጭ ጨርስ',
                  style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
                ),
              ),
              const SizedBox(width: 8),
              Text(
                totalDisplay,
                style: const TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.bold,
                  color: Colors.deepOrange,
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),

          // Payment Method Selector Buttons
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                if (_enabledMethods.contains('cash'))
                  _buildMethodChip('cash', '💵 Cash (ጥሬ ገንዘብ)', Icons.money),
                if (_enabledMethods.contains('bank'))
                  _buildMethodChip('bank', '🏦 Bank (ባንክ)', Icons.account_balance),
                if (_enabledMethods.contains('wallet'))
                  _buildMethodChip('wallet', '📱 Wallet (ዋሌት)', Icons.phone_android),
                if (_enabledMethods.contains('dube'))
                  _buildMethodChip('dube', '📝 Dube (ዱቤ)', Icons.edit_note),
              ],
            ),
          ),
          const Divider(height: 24),

          // Method Details View
          Flexible(
            child: SingleChildScrollView(
              child: _buildSelectedMethodBody(),
            ),
          ),

          const SizedBox(height: 16),

          // Action Button: One-tap Finish for Cash
          FilledButton.icon(
            style: FilledButton.styleFrom(
              backgroundColor: Colors.deepOrange,
              padding: const EdgeInsets.symmetric(vertical: 16),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            ),
            onPressed: _confirmFinish,
            icon: const Icon(Icons.check_circle_outline, size: 22),
            label: Text(
              _selectedMethod == 'cash'
                  ? 'Finish Sale ($totalDisplay)'
                  : 'Confirm & Finish / ጨርስ',
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildMethodChip(String key, String label, IconData icon) {
    final isSelected = _selectedMethod == key;
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: ChoiceChip(
        avatar: Icon(icon, size: 18, color: isSelected ? Colors.white : Theme.of(context).colorScheme.onSurface),
        label: Text(label),
        selected: isSelected,
        selectedColor: Colors.deepOrange,
        labelStyle: TextStyle(
          color: isSelected ? Colors.white : Theme.of(context).colorScheme.onSurface,
          fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
        ),
        onSelected: (val) {
          if (val) setState(() => _selectedMethod = key);
        },
      ),
    );
  }

  Widget _buildSelectedMethodBody() {
    switch (_selectedMethod) {
      case 'cash':
        return _buildCashBody();
      case 'bank':
        return _buildBankBody();
      case 'wallet':
        return _buildWalletBody();
      case 'dube':
        return _buildDubeBody();
      default:
        return const SizedBox.shrink();
    }
  }

  Widget _buildCashBody() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Cash Payment / የጥሬ ገንዘብ ክፍያ',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
        ),
        const SizedBox(height: 10),
        TextField(
          controller: _amountReceivedCtrl,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(
            labelText: 'Amount Received (ETB) / የተቀበሉት ገንዘብ',
            border: OutlineInputBorder(),
            prefixText: 'ETB ',
          ),
          onChanged: (val) {
            final parsed = double.tryParse(val) ?? 0;
            setState(() {
              _cashReceivedCents = (parsed * 100).round();
            });
          },
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            ActionChip(
              label: const Text('Exact / ልክ'),
              onPressed: () {
                setState(() {
                  _cashReceivedCents = widget.totalCents;
                  _amountReceivedCtrl.text = (widget.totalCents / 100).toStringAsFixed(2);
                });
              },
            ),
            const SizedBox(width: 8),
            ActionChip(
              label: const Text('+100'),
              onPressed: () {
                setState(() {
                  _cashReceivedCents += 10000;
                  _amountReceivedCtrl.text = (_cashReceivedCents / 100).toStringAsFixed(2);
                });
              },
            ),
            const SizedBox(width: 8),
            ActionChip(
              label: const Text('+500'),
              onPressed: () {
                setState(() {
                  _cashReceivedCents += 50000;
                  _amountReceivedCtrl.text = (_cashReceivedCents / 100).toStringAsFixed(2);
                });
              },
            ),
          ],
        ),
        if (_changeDueCents > 0) ...[
          const SizedBox(height: 14),
          Builder(builder: (ctx) {
            final isDark = Theme.of(ctx).brightness == Brightness.dark;
            final tokens = AppColors.successTokens(isDark);
            return Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: tokens.bg,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: tokens.border),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    'Change Due / መልስ:',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: tokens.fg),
                  ),
                  Text(
                    MoneyFormatter.formatCents(_changeDueCents),
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18, color: tokens.fg),
                  ),
                ],
              ),
            );
          }),
        ],
      ],
    );
  }

  Widget _buildBankBody() {
    final query = _bankSearchCtrl.text.toLowerCase();
    final filtered = _bankProviders.where((b) {
      final n = (b.name ?? b.providerKey).toLowerCase();
      return n.contains(query);
    }).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            const Text(
              'Select Bank / ባንክ ይምረጡ',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
            ),
            TextButton.icon(
              onPressed: () => _addCustomProvider('bank'),
              icon: const Icon(Icons.add, size: 16),
              label: const Text('Other bank…'),
            ),
          ],
        ),
        TextField(
          controller: _bankSearchCtrl,
          decoration: const InputDecoration(
            hintText: 'Search bank / ፈልግ...',
            prefixIcon: Icon(Icons.search, size: 20),
            isDense: true,
            border: OutlineInputBorder(),
          ),
          onChanged: (_) => setState(() {}),
        ),
        const SizedBox(height: 8),
        Container(
          constraints: const BoxConstraints(maxHeight: 180),
          decoration: BoxDecoration(
            border: Border.all(color: Theme.of(context).colorScheme.outline),
            borderRadius: BorderRadius.circular(8),
          ),
          child: ListView.builder(
            shrinkWrap: true,
            itemCount: filtered.length,
            itemBuilder: (ctx, idx) {
              final bank = filtered[idx];
              final isSel = _selectedBank?.providerKey == bank.providerKey;
              final isDark = Theme.of(ctx).brightness == Brightness.dark;
              return ListTile(
                dense: true,
                selected: isSel,
                selectedTileColor: isDark ? Colors.deepOrange.withValues(alpha: 0.25) : Colors.deepOrange.shade50,
                leading: Icon(
                  bank.favorite ? Icons.star : Icons.account_balance,
                  color: bank.favorite ? Colors.amber : Theme.of(ctx).colorScheme.onSurfaceVariant,
                  size: 20,
                ),
                title: Text(bank.name ?? bank.providerKey),
                trailing: isSel ? const Icon(Icons.check, color: Colors.deepOrange) : null,
                onTap: () => setState(() => _selectedBank = bank),
              );
            },
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _referenceCtrl,
          decoration: InputDecoration(
            labelText: _requireReference
                ? 'Reference / Tx Number * (Required)'
                : 'Reference / Tx Number (Optional)',
            border: const OutlineInputBorder(),
          ),
        ),
      ],
    );
  }

  Widget _buildWalletBody() {
    final query = _walletSearchCtrl.text.toLowerCase();
    final filtered = _walletProviders.where((w) {
      final n = (w.name ?? w.providerKey).toLowerCase();
      return n.contains(query);
    }).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            const Text(
              'Select Wallet / ዋሌት ይምረጡ',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
            ),
            TextButton.icon(
              onPressed: () => _addCustomProvider('wallet'),
              icon: const Icon(Icons.add, size: 16),
              label: const Text('Other wallet…'),
            ),
          ],
        ),
        TextField(
          controller: _walletSearchCtrl,
          decoration: const InputDecoration(
            hintText: 'Search wallet / ፈልግ...',
            prefixIcon: Icon(Icons.search, size: 20),
            isDense: true,
            border: OutlineInputBorder(),
          ),
          onChanged: (_) => setState(() {}),
        ),
        const SizedBox(height: 8),
        Container(
          constraints: const BoxConstraints(maxHeight: 180),
          decoration: BoxDecoration(
            border: Border.all(color: Theme.of(context).colorScheme.outline),
            borderRadius: BorderRadius.circular(8),
          ),
          child: ListView.builder(
            shrinkWrap: true,
            itemCount: filtered.length,
            itemBuilder: (ctx, idx) {
              final wallet = filtered[idx];
              final isSel = _selectedWallet?.providerKey == wallet.providerKey;
              final isDark = Theme.of(ctx).brightness == Brightness.dark;
              return ListTile(
                dense: true,
                selected: isSel,
                selectedTileColor: isDark ? Colors.deepOrange.withValues(alpha: 0.25) : Colors.deepOrange.shade50,
                leading: Icon(
                  wallet.favorite ? Icons.star : Icons.phone_android,
                  color: wallet.favorite ? Colors.amber : Theme.of(ctx).colorScheme.onSurfaceVariant,
                  size: 20,
                ),
                title: Text(wallet.name ?? wallet.providerKey),
                trailing: isSel ? const Icon(Icons.check, color: Colors.deepOrange) : null,
                onTap: () => setState(() => _selectedWallet = wallet),
              );
            },
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _referenceCtrl,
          decoration: InputDecoration(
            labelText: _requireReference
                ? 'Reference / Tx Number * (Required)'
                : 'Reference / Tx Number (Optional)',
            border: const OutlineInputBorder(),
          ),
        ),
      ],
    );
  }

  Widget _buildDubeBody() {
    final remainingDubeCents = widget.totalCents - _dubePaidNowCents;
    final dueDateFormatted = DateFormat('dd MMM yyyy').format(_dubeDueDate);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // 1. Choose Customer
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            const Text(
              'Customer / ደንበኛ *',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
            ),
            TextButton.icon(
              onPressed: _showNewCustomerDialog,
              icon: const Icon(Icons.person_add, size: 16),
              label: const Text('+ New customer'),
            ),
          ],
        ),
        if (_selectedCustomer == null) ...[
          Container(
            constraints: const BoxConstraints(maxHeight: 140),
            decoration: BoxDecoration(
              border: Border.all(color: Theme.of(context).colorScheme.outline),
              borderRadius: BorderRadius.circular(8),
            ),
            child: ListView.builder(
              shrinkWrap: true,
              itemCount: _customerList.length,
              itemBuilder: (ctx, idx) {
                final cust = _customerList[idx];
                return ListTile(
                  dense: true,
                  leading: const Icon(Icons.person_outline),
                  title: Text(cust.name),
                  subtitle: cust.phone != null ? Text(cust.phone!) : null,
                  onTap: () => setState(() => _selectedCustomer = cust),
                );
              },
            ),
          ),
        ] else ...[
          Builder(builder: (ctx) {
            final isDark = Theme.of(ctx).brightness == Brightness.dark;
            return Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: isDark
                    ? Theme.of(ctx).colorScheme.surfaceContainerHighest
                    : Colors.teal.shade50,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(
                  color: isDark
                      ? Theme.of(ctx).colorScheme.outline
                      : Colors.teal.shade200,
                ),
              ),
              child: Row(
                children: [
                  Icon(Icons.person, color: Theme.of(ctx).colorScheme.primary),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _selectedCustomer!.name,
                          style: const TextStyle(fontWeight: FontWeight.bold),
                        ),
                        if (_selectedCustomer!.phone != null)
                          Text(
                            _selectedCustomer!.phone!,
                            style: TextStyle(
                              fontSize: 12,
                              color: Theme.of(ctx).colorScheme.onSurfaceVariant,
                            ),
                          ),
                      ],
                    ),
                  ),
                  TextButton(
                    onPressed: () => setState(() => _selectedCustomer = null),
                    child: const Text('Change'),
                  ),
                ],
              ),
            );
          }),
        ],

        const SizedBox(height: 16),

        // 2. Paid Now Selection
        const Text(
          'Paid Now / አሁን የተከፈለ',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            _buildPaidNowChip('0 (Unpaid)', 0),
            const SizedBox(width: 6),
            _buildPaidNowChip('25%', (widget.totalCents * 0.25).round()),
            const SizedBox(width: 6),
            _buildPaidNowChip('50%', (widget.totalCents * 0.50).round()),
            const SizedBox(width: 6),
            _buildPaidNowCustomChip(),
          ],
        ),

        // If paid now > 0, choose method for the paid part
        if (_dubePaidNowCents > 0) ...[
          const SizedBox(height: 10),
          Row(
            children: [
              ChoiceChip(
                label: const Text('Cash'),
                selected: _dubePaidMethod == 'cash',
                onSelected: (v) => setState(() => _dubePaidMethod = 'cash'),
              ),
              const SizedBox(width: 6),
              ChoiceChip(
                label: const Text('Bank'),
                selected: _dubePaidMethod == 'bank',
                onSelected: (v) => setState(() => _dubePaidMethod = 'bank'),
              ),
              const SizedBox(width: 6),
              ChoiceChip(
                label: const Text('Wallet'),
                selected: _dubePaidMethod == 'wallet',
                onSelected: (v) => setState(() => _dubePaidMethod = 'wallet'),
              ),
            ],
          ),
        ],

        const SizedBox(height: 16),

        // 3. Due Date Selection
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            const Text(
              'Due Date / የሚከፈልበት ቀን *',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
            ),
            TextButton.icon(
              onPressed: () async {
                final picked = await showDatePicker(
                  context: context,
                  initialDate: _dubeDueDate,
                  firstDate: DateTime.now(),
                  lastDate: DateTime.now().add(const Duration(days: 365)),
                );
                if (picked != null) {
                  setState(() => _dubeDueDate = picked);
                }
              },
              icon: const Icon(Icons.calendar_month, size: 16),
              label: Text(dueDateFormatted),
            ),
          ],
        ),
        Row(
          children: [
            ActionChip(
              label: const Text('+7 Days'),
              onPressed: () => setState(() => _dubeDueDate = DateTime.now().add(const Duration(days: 7))),
            ),
            const SizedBox(width: 6),
            ActionChip(
              label: const Text('+14 Days'),
              onPressed: () => setState(() => _dubeDueDate = DateTime.now().add(const Duration(days: 14))),
            ),
            const SizedBox(width: 6),
            ActionChip(
              label: const Text('+30 Days'),
              onPressed: () => setState(() => _dubeDueDate = DateTime.now().add(const Duration(days: 30))),
            ),
          ],
        ),

        const SizedBox(height: 16),

        // Summary Banner
        Builder(builder: (ctx) {
          final isDark = Theme.of(ctx).brightness == Brightness.dark;
          final tokens = AppColors.warningTokens(isDark);
          return Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: tokens.bg,
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: tokens.border),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Pays now: ${MoneyFormatter.formatCents(_dubePaidNowCents)}, Dube: ${MoneyFormatter.formatCents(remainingDubeCents)}',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: tokens.fg),
                ),
                const SizedBox(height: 2),
                Text(
                  'Due: $dueDateFormatted',
                  style: TextStyle(
                    fontSize: 13,
                    color: isDark ? tokens.fg.withValues(alpha: 0.85) : Colors.brown.shade800,
                  ),
                ),
              ],
            ),
          );
        }),
      ],
    );
  }

  Widget _buildPaidNowChip(String label, int cents) {
    final isSelected = _dubePaidNowCents == cents;
    return ChoiceChip(
      label: Text(label),
      selected: isSelected,
      selectedColor: Colors.deepOrange,
      labelStyle: TextStyle(
        color: isSelected ? Colors.white : Theme.of(context).colorScheme.onSurface,
        fontSize: 12,
      ),
      onSelected: (v) {
        if (v) setState(() => _dubePaidNowCents = cents);
      },
    );
  }

  Widget _buildPaidNowCustomChip() {
    final isCustom = _dubePaidNowCents > 0 &&
        _dubePaidNowCents != (widget.totalCents * 0.25).round() &&
        _dubePaidNowCents != (widget.totalCents * 0.50).round();

    return ChoiceChip(
      label: const Text('Custom'),
      selected: isCustom,
      selectedColor: Colors.deepOrange,
      labelStyle: TextStyle(
        color: isCustom ? Colors.white : Theme.of(context).colorScheme.onSurface,
        fontSize: 12,
      ),
      onSelected: (v) async {
        final ctrl = TextEditingController(
          text: (_dubePaidNowCents / 100).toStringAsFixed(2),
        );
        final res = await showDialog<double>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('Custom Paid Now (ETB)'),
            content: TextField(
              controller: ctrl,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              autofocus: true,
              decoration: const InputDecoration(prefixText: 'ETB '),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Cancel')),
              FilledButton(
                onPressed: () {
                  final val = double.tryParse(ctrl.text);
                  Navigator.of(ctx).pop(val);
                },
                child: const Text('Apply'),
              ),
            ],
          ),
        );

        if (res != null) {
          final cents = (res * 100).round();
          setState(() {
            _dubePaidNowCents = cents > widget.totalCents ? widget.totalCents : cents;
          });
        }
      },
    );
  }
}
