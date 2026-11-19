import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';

import '../../database/app_database.dart';
import '../../repositories/ledger_repository.dart';
import '../../services/notification_service.dart';
import '../../utils/money_formatter.dart';
import '../../utils/phone_validator.dart';
import '../../utils/quantity_formatter.dart';
import '../theme.dart';

/// Opens the Register Dube bottom sheet directly from the Dube screen or customer detail sheet.
Future<Sale?> showRegisterDubeSheet(
  BuildContext context, {
  required LedgerRepository repository,
  String? initialCustomerId,
  VoidCallback? onSuccess,
}) {
  return showModalBottomSheet<Sale>(
    context: context,
    isScrollControlled: true,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
    ),
    builder: (ctx) => RegisterDubeSheet(
      repository: repository,
      initialCustomerId: initialCustomerId,
      onSuccess: onSuccess,
    ),
  );
}

class RegisterDubeSheet extends StatefulWidget {
  final LedgerRepository repository;
  final String? initialCustomerId;
  final VoidCallback? onSuccess;

  const RegisterDubeSheet({
    super.key,
    required this.repository,
    this.initialCustomerId,
    this.onSuccess,
  });

  @override
  State<RegisterDubeSheet> createState() => _RegisterDubeSheetState();
}

class _RegisterDubeSheetState extends State<RegisterDubeSheet> {
  List<Customer> _customers = [];
  Customer? _selectedCustomer;
  final _amountCtrl = TextEditingController();
  final _noteCtrl = TextEditingController();
  late DateTime _dueDate;
  int _dubeDefaultDays = 14;
  bool _isLoading = true;
  bool _isSaving = false;

  // Optional itemized products from inventory
  final List<SaleLineItem> _selectedItems = [];
  List<ProductWithStock> _availableProducts = [];
  bool _showItemsSection = false;

  // Optional upfront partial payment
  bool _hasUpfrontPayment = false;
  final _upfrontAmountCtrl = TextEditingController();
  String _upfrontMethod = 'cash';
  List<PaymentProvider> _bankProviders = [];
  List<PaymentProvider> _walletProviders = [];
  PaymentProvider? _selectedBank;
  PaymentProvider? _selectedWallet;
  final _upfrontRefCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _dueDate = DateTime.now().add(const Duration(days: 14));
    _loadInitialData();
  }

  @override
  void dispose() {
    _amountCtrl.dispose();
    _noteCtrl.dispose();
    _upfrontAmountCtrl.dispose();
    _upfrontRefCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadInitialData() async {
    final custs = await widget.repository.listCustomers();
    final defaultDays = await widget.repository.getDubeDefaultDays();
    final products = await widget.repository.listProducts();
    final banks = await widget.repository.listPaymentProviders(kind: 'bank', enabledOnly: true);
    final wallets = await widget.repository.listPaymentProviders(kind: 'wallet', enabledOnly: true);

    Customer? preselected;
    if (widget.initialCustomerId != null) {
      preselected = custs.where((c) => c.id == widget.initialCustomerId).firstOrNull;
    }

    if (mounted) {
      setState(() {
        _customers = custs;
        _dubeDefaultDays = defaultDays;
        _dueDate = DateTime.now().add(Duration(days: defaultDays));
        _selectedCustomer = preselected;
        _availableProducts = products.where((p) => p.product.isActive).toList();
        _bankProviders = banks;
        _walletProviders = wallets;
        if (_bankProviders.isNotEmpty) {
          _selectedBank = _bankProviders.firstWhere((p) => p.favorite, orElse: () => _bankProviders.first);
        }
        if (_walletProviders.isNotEmpty) {
          _selectedWallet = _walletProviders.firstWhere((p) => p.favorite, orElse: () => _walletProviders.first);
        }
        _isLoading = false;
      });
    }
  }

  Future<void> _addNewCustomerInline() async {
    final isAm = Localizations.localeOf(context).languageCode == 'am';
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
            final exists = _customers.any((c) => c.name.toLowerCase() == nameCtrl.text.trim().toLowerCase());
            if (exists) {
              warning = isAm
                  ? 'ማስጠንቀቂያ፡ በዚህ ስም ወይም ስልክ ደንበኛ አስቀድሞ ተመዝግቧል።'
                  : 'Warning: A customer with this name or number may already exist.';
            }
          }

          return AlertDialog(
            title: Text(isAm ? 'አዲስ ደንበኛ መዝግብ' : 'Add New Customer'),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                    controller: nameCtrl,
                    autofocus: true,
                    decoration: InputDecoration(
                      labelText: isAm ? 'ሙሉ ስም *' : 'Full Name *',
                      hintText: isAm ? 'አበበ ከበደ' : 'Abebe Kebede',
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: phoneCtrl,
                    keyboardType: TextInputType.phone,
                    decoration: InputDecoration(
                      labelText: isAm ? 'ስልክ ቁጥር (09... ወይም 07...)' : 'Phone (09... or 07...)',
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
                    decoration: InputDecoration(
                      labelText: isAm ? 'ማስታወሻ (አማራጭ)' : 'Note (optional)',
                      hintText: isAm ? 'አድራሻ ወይም መለያ ቦታ' : 'Address or landmark',
                    ),
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.of(ctx).pop(), child: Text(isAm ? 'ይቅር' : 'Cancel')),
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
                child: Text(isAm ? 'መዝግብ' : 'Save'),
              ),
            ],
          );
        },
      ),
    );

    if (created != null) {
      final updatedList = await widget.repository.listCustomers();
      if (mounted) {
        setState(() {
          _customers = updatedList;
          _selectedCustomer = created;
        });
      }
    }
  }

  void _recalculateFromItems() {
    int total = 0;
    for (final item in _selectedItems) {
      total += item.totalCents;
    }
    if (_selectedItems.isNotEmpty) {
      _amountCtrl.text = (total / 100).toStringAsFixed(2);
    }
    setState(() {});
  }

  Future<void> _addItemFromStore() async {
    final isAm = Localizations.localeOf(context).languageCode == 'am';
    final searchCtrl = TextEditingController();
    List<ProductWithStock> filtered = List.from(_availableProducts);

    final selectedItem = await showDialog<ProductWithStock>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) {
          return AlertDialog(
            title: Text(isAm ? 'ዕቃ ምረጥ' : 'Select Product'),
            content: SizedBox(
              width: double.maxFinite,
              height: 380,
              child: Column(
                children: [
                  TextField(
                    controller: searchCtrl,
                    decoration: InputDecoration(
                      hintText: isAm ? 'ዕቃ በስም ፈልግ...' : 'Search product...',
                      prefixIcon: const Icon(Icons.search),
                      isDense: true,
                    ),
                    onChanged: (q) {
                      setDlgState(() {
                        filtered = _availableProducts
                            .where((pws) => pws.product.name.toLowerCase().contains(q.trim().toLowerCase()))
                            .toList();
                      });
                    },
                  ),
                  const SizedBox(height: 10),
                  Expanded(
                    child: filtered.isEmpty
                        ? Center(child: Text(isAm ? 'ምንም ዕቃ አልተገኘም' : 'No products found'))
                        : ListView.separated(
                            itemCount: filtered.length,
                            separatorBuilder: (_, __) => const Divider(height: 1),
                            itemBuilder: (ctx, i) {
                              final item = filtered[i];
                              final p = item.product;
                              final priceStr = MoneyFormatter.formatCents(p.sellPriceCents);
                              final stockStr = '${QuantityFormatter.formatDisplay(item.stockMilli)} ${p.unit}';
                              return ListTile(
                                dense: true,
                                title: Text(p.name, style: const TextStyle(fontWeight: FontWeight.bold)),
                                subtitle: Text('$priceStr / ${p.unit} • ${isAm ? "ቀሪ ክምችት" : "Stock"}: $stockStr'),
                                onTap: () => Navigator.of(ctx).pop(item),
                              );
                            },
                          ),
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.of(ctx).pop(), child: Text(isAm ? 'ይቅር' : 'Cancel')),
            ],
          );
        },
      ),
    );

    if (selectedItem != null && mounted) {
      final selectedProduct = selectedItem.product;
      final qtyCtrl = TextEditingController(text: '1');
      final priceCtrl = TextEditingController(text: (selectedProduct.sellPriceCents / 100).toStringAsFixed(2));

      final confirmed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(isAm ? 'ብዛትና ዋጋ ያስገቡ' : 'Quantity & Price'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                selectedProduct.name,
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: qtyCtrl,
                autofocus: true,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(
                  labelText: isAm ? 'ብዛት (${selectedProduct.unit}) *' : 'Quantity (${selectedProduct.unit}) *',
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: priceCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(
                  labelText: isAm ? 'የአንዱ መሸጫ ዋጋ (ብር) *' : 'Unit Price (ETB) *',
                  prefixText: 'ETB ',
                ),
              ),
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: Text(isAm ? 'ይቅር' : 'Cancel')),
            FilledButton(
              onPressed: () => Navigator.of(ctx).pop(true),
              child: Text(isAm ? 'አክል' : 'Add'),
            ),
          ],
        ),
      );

      if (confirmed == true) {
        final qtyMilli = QuantityFormatter.parseQty(qtyCtrl.text);
        final priceVal = double.tryParse(priceCtrl.text) ?? (selectedProduct.sellPriceCents / 100);
        final priceCents = (priceVal * 100).round();

        if (qtyMilli > 0 && priceCents > 0) {
          setState(() {
            _selectedItems.add(SaleLineItem(
              product: selectedProduct,
              qtyMilli: qtyMilli,
              unitPriceCents: priceCents,
            ));
            _showItemsSection = true;
          });
          _recalculateFromItems();
        }
      }
    }
  }

  Future<void> _submitDube() async {
    final isAm = Localizations.localeOf(context).languageCode == 'am';

    if (_selectedCustomer == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          backgroundColor: Colors.red.shade700,
          content: Text(isAm ? 'እባክዎ ደንበኛ ይምረጡ ወይም አዲስ ይመዝግቡ።' : 'Please select a customer or add a new one.'),
        ),
      );
      return;
    }

    final totalVal = double.tryParse(_amountCtrl.text) ?? 0.0;
    if (totalVal <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          backgroundColor: Colors.red.shade700,
          content: Text(isAm ? 'እባክዎ ትክክለኛ የዱቤ መጠን ያስገቡ።' : 'Please enter a valid Dube amount.'),
        ),
      );
      return;
    }

    final upfrontVal = _hasUpfrontPayment ? (double.tryParse(_upfrontAmountCtrl.text) ?? 0.0) : 0.0;
    if (_hasUpfrontPayment && upfrontVal < 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          backgroundColor: Colors.red.shade700,
          content: Text(isAm ? 'ቅድመ ክፍያው ከዜሮ በታች ሊሆን አይችልም።' : 'Upfront payment cannot be negative.'),
        ),
      );
      return;
    }

    if (_hasUpfrontPayment && upfrontVal >= totalVal) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          backgroundColor: Colors.red.shade700,
          content: Text(isAm ? 'ቅድመ ክፍያው ከጠቅላላው የዱቤ መጠን ያነሰ መሆን አለበት።' : 'Upfront payment must be less than total Dube amount.'),
        ),
      );
      return;
    }

    final today = DateTime(DateTime.now().year, DateTime.now().month, DateTime.now().day);
    final dueCheck = DateTime(_dueDate.year, _dueDate.month, _dueDate.day);
    if (dueCheck.isBefore(today)) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          backgroundColor: Colors.red.shade700,
          content: Text(isAm ? 'የመክፈያ ቀነ-ገደብ ከዛሬ በፊት ሊሆን አይችልም።' : 'Due date cannot be before today.'),
        ),
      );
      return;
    }

    setState(() => _isSaving = true);

    try {
      final totalCents = (totalVal * 100).round();
      final upfrontCents = _hasUpfrontPayment ? (upfrontVal * 100).round() : 0;
      final dueDateStr = DateFormat('yyyy-MM-dd').format(_dueDate);

      String? paidProviderKey;
      if (_hasUpfrontPayment) {
        if (_upfrontMethod == 'bank') paidProviderKey = _selectedBank?.providerKey;
        if (_upfrontMethod == 'wallet') paidProviderKey = _selectedWallet?.providerKey;
      }

      final sale = await widget.repository.recordDirectDube(
        customerId: _selectedCustomer!.id,
        totalCents: totalCents,
        dueDate: dueDateStr,
        note: _noteCtrl.text.trim().isNotEmpty ? _noteCtrl.text.trim() : null,
        items: _selectedItems,
        paidAmountCents: upfrontCents,
        paidMethod: _hasUpfrontPayment ? _upfrontMethod : null,
        paidProviderKey: paidProviderKey,
        reference: _hasUpfrontPayment && _upfrontRefCtrl.text.trim().isNotEmpty ? _upfrontRefCtrl.text.trim() : null,
      );

      // Low stock check if items were provided
      if (_selectedItems.isNotEmpty) {
        final notif = NotificationService(repository: widget.repository);
        for (final item in _selectedItems) {
          if (item.product.kind == 'stockable') {
            final curStock = await widget.repository.stockOf(item.product.id);
            await notif.notifyLowStock(product: item.product, currentStockMilli: curStock);
          }
        }
      }

      HapticFeedback.mediumImpact();

      if (mounted) {
        Navigator.of(context).pop(sale);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            backgroundColor: Colors.green.shade700,
            content: Text(
              isAm
                  ? '🎉 የ ${MoneyFormatter.formatCents(totalCents - upfrontCents)} ዱቤ ለ ${_selectedCustomer!.name} ተመዝግቧል!'
                  : '🎉 ${MoneyFormatter.formatCents(totalCents - upfrontCents)} Dube registered for ${_selectedCustomer!.name}!',
            ),
          ),
        );
        widget.onSuccess?.call();
      }
    } catch (e) {
      if (mounted) {
        setState(() => _isSaving = false);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            backgroundColor: Colors.red.shade700,
            content: Text(e.toString()),
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final isAm = Localizations.localeOf(context).languageCode == 'am';
    final isDark = Theme.of(context).brightness == Brightness.dark;

    if (_isLoading) {
      return const SizedBox(
        height: 250,
        child: Center(child: CircularProgressIndicator()),
      );
    }

    final totalVal = double.tryParse(_amountCtrl.text) ?? 0.0;
    final upfrontVal = _hasUpfrontPayment ? (double.tryParse(_upfrontAmountCtrl.text) ?? 0.0) : 0.0;
    final remainingVal = (totalVal - upfrontVal) > 0 ? (totalVal - upfrontVal) : 0.0;

    final dueDateFormatted = DateFormat('yyyy-MM-dd').format(_dueDate);
    final daysRemaining = _dueDate.difference(DateTime.now()).inDays + 1;

    return Padding(
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        top: 16,
        bottom: MediaQuery.of(context).viewInsets.bottom + 20,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Drag handle
            Center(
              child: Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: Theme.of(context).colorScheme.outlineVariant,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
            const SizedBox(height: 12),

            // Header
            Row(
              children: [
                CircleAvatar(
                  backgroundColor: Colors.deepOrange.withValues(alpha: 0.15),
                  child: const Icon(Icons.note_add_rounded, color: Colors.deepOrange),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        isAm ? 'አዲስ ዱቤ መዝግብ' : 'Register Dube',
                        style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                      ),
                      Text(
                        isAm ? 'የደንበኛ የዱቤ ሽያጭ ወይም ዕዳ በቀጥታ ይመዝግቡ' : 'Record customer credit sale or debt directly',
                        style: TextStyle(
                          fontSize: 12,
                          color: Theme.of(context).colorScheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close),
                  onPressed: () => Navigator.of(context).pop(),
                ),
              ],
            ),
            const SizedBox(height: 16),

            // 1. Customer Selection
            Text(
              isAm ? 'ደንበኛ *' : 'Customer *',
              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 6),
            if (widget.initialCustomerId != null && _selectedCustomer != null)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                decoration: BoxDecoration(
                  color: isDark ? Theme.of(context).colorScheme.surfaceContainerHighest : Colors.grey.shade100,
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.person, color: Colors.deepOrange, size: 20),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        _selectedCustomer!.name + (_selectedCustomer!.phone != null ? ' (${_selectedCustomer!.phone})' : ''),
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                      ),
                    ),
                    const Icon(Icons.lock_outline, size: 16, color: Colors.grey),
                  ],
                ),
              )
            else
              Row(
                children: [
                  Expanded(
                    child: DropdownButtonFormField<Customer>(
                      value: _selectedCustomer,
                      isExpanded: true,
                      decoration: InputDecoration(
                        isDense: true,
                        contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                        hintText: isAm ? 'ደንበኛ ይምረጡ...' : 'Select customer...',
                      ),
                      items: _customers.map((c) {
                        return DropdownMenuItem<Customer>(
                          value: c,
                          child: Text(
                            c.name + (c.phone != null ? ' (${c.phone})' : ''),
                            overflow: TextOverflow.ellipsis,
                          ),
                        );
                      }).toList(),
                      onChanged: (val) => setState(() => _selectedCustomer = val),
                    ),
                  ),
                  const SizedBox(width: 8),
                  OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 12),
                    ),
                    icon: const Icon(Icons.person_add_alt_1_rounded, size: 18),
                    label: Text(isAm ? 'አዲስ' : 'New', style: const TextStyle(fontSize: 12)),
                    onPressed: _addNewCustomerInline,
                  ),
                ],
              ),
            const SizedBox(height: 16),

            // 2. Dube Total Amount
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  isAm ? 'የዱቤ ጠቅላላ መጠን (ብር) *' : 'Total Dube Amount (ETB) *',
                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
                ),
                if (_selectedItems.isNotEmpty)
                  TextButton.icon(
                    style: TextButton.styleFrom(padding: EdgeInsets.zero, visualDensity: VisualDensity.compact),
                    icon: const Icon(Icons.clear_all, size: 16, color: Colors.red),
                    label: Text(isAm ? 'ዕቃዎችን አጽዳ' : 'Clear Items', style: const TextStyle(fontSize: 11, color: Colors.red)),
                    onPressed: () {
                      setState(() {
                        _selectedItems.clear();
                        _amountCtrl.clear();
                      });
                    },
                  ),
              ],
            ),
            const SizedBox(height: 6),
            TextField(
              controller: _amountCtrl,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
              decoration: InputDecoration(
                prefixText: 'ETB ',
                hintText: '0.00',
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              ),
              onChanged: (_) => setState(() {}),
            ),
            const SizedBox(height: 12),

            // 3. Optional itemized products from store
            Container(
              decoration: BoxDecoration(
                border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Column(
                children: [
                  ListTile(
                    dense: true,
                    leading: const Icon(Icons.inventory_2_outlined, color: Colors.teal, size: 20),
                    title: Text(
                      isAm
                          ? (_selectedItems.isEmpty ? 'ዕቃ ከሱቅ ጨምር (አማራጭ)' : 'የተመረጡ ዕቃዎች (${_selectedItems.length})')
                          : (_selectedItems.isEmpty ? 'Pick store items (Optional)' : 'Selected Items (${_selectedItems.length})'),
                      style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                    ),
                    trailing: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        TextButton.icon(
                          icon: const Icon(Icons.add, size: 16),
                          label: Text(isAm ? 'ዕቃ ጨምር' : 'Add Item', style: const TextStyle(fontSize: 12)),
                          onPressed: _addItemFromStore,
                        ),
                      ],
                    ),
                  ),
                  if (_selectedItems.isNotEmpty)
                    ListView.builder(
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      itemCount: _selectedItems.length,
                      itemBuilder: (ctx, i) {
                        final item = _selectedItems[i];
                        final qtyStr = QuantityFormatter.formatDisplay(item.qtyMilli);
                        final itemTotalStr = MoneyFormatter.formatCents(item.totalCents);
                        return ListTile(
                          dense: true,
                          title: Text(item.product.name, style: const TextStyle(fontSize: 13)),
                          subtitle: Text(
                            '$qtyStr ${item.product.unit} @ ${MoneyFormatter.formatCents(item.unitPriceCents)} = $itemTotalStr',
                            style: const TextStyle(fontSize: 11),
                          ),
                          trailing: IconButton(
                            icon: const Icon(Icons.delete_outline, size: 18, color: Colors.red),
                            onPressed: () {
                              setState(() => _selectedItems.removeAt(i));
                              _recalculateFromItems();
                            },
                          ),
                        );
                      },
                    ),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // 4. Due Date
            Text(
              isAm ? 'የመክፈያ ቀነ-ገደብ *' : 'Payment Due Date *',
              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 6),
            InkWell(
              onTap: () async {
                final picked = await showDatePicker(
                  context: context,
                  initialDate: _dueDate,
                  firstDate: DateTime.now(),
                  lastDate: DateTime.now().add(const Duration(days: 365 * 3)),
                );
                if (picked != null) {
                  setState(() => _dueDate = picked);
                }
              },
              borderRadius: BorderRadius.circular(10),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                decoration: BoxDecoration(
                  border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.event_outlined, color: Colors.deepOrange, size: 20),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        '$dueDateFormatted (${isAm ? "ከ $daysRemaining ቀናት በኋላ" : "in $daysRemaining days"})',
                        style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
                      ),
                    ),
                    const Icon(Icons.arrow_drop_down, color: Colors.grey),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 8),

            // Quick days chips
            Wrap(
              spacing: 8,
              children: [7, 14, 30, 60].map((days) {
                final target = DateTime.now().add(Duration(days: days));
                final isSelected = _dueDate.year == target.year && _dueDate.month == target.month && _dueDate.day == target.day;
                return ChoiceChip(
                  label: Text('+$days ${isAm ? "ቀን" : "days"}', style: const TextStyle(fontSize: 12)),
                  selected: isSelected,
                  onSelected: (val) {
                    if (val) {
                      setState(() => _dueDate = target);
                    }
                  },
                );
              }).toList(),
            ),
            const SizedBox(height: 16),

            // 5. Note / Description
            Text(
              Localizations.localeOf(context).languageCode == 'om'
                  ? 'Yaadannoo ykn Sababa (Filannoo)'
                  : (isAm ? 'ማስታወሻ ወይም ምክንያት (አማራጭ)' : 'Note or Reason (Optional)'),
              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 6),
            TextField(
              controller: _noteCtrl,
              decoration: InputDecoration(
                hintText: isAm ? 'ለምሳሌ፡ የሸቀጣ ሸቀጥ ዕቃዎች፣ ያለፈ ሂሳብ...' : 'e.g. Groceries, credit balance...',
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              ),
            ),
            const SizedBox(height: 16),

            // 6. Upfront Partial Payment
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: isDark ? Theme.of(context).colorScheme.surfaceContainerHighest : Colors.grey.shade50,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Checkbox(
                        value: _hasUpfrontPayment,
                        onChanged: (val) => setState(() => _hasUpfrontPayment = val ?? false),
                      ),
                      Expanded(
                        child: GestureDetector(
                          onTap: () => setState(() => _hasUpfrontPayment = !_hasUpfrontPayment),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                isAm ? 'ቅድመ ክፍያ ተቀብለዋል? (አማራጭ)' : 'Received upfront partial payment?',
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                              ),
                              Text(
                                isAm ? 'ደንበኛው የተወሰነ ከፍሎ ቀሪውን በዱቤ ሲወስድ' : 'Customer pays part now and remainder on credit',
                                style: TextStyle(fontSize: 11, color: Theme.of(context).colorScheme.onSurfaceVariant),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ],
                  ),
                  if (_hasUpfrontPayment) ...[
                    const Divider(height: 16),
                    TextField(
                      controller: _upfrontAmountCtrl,
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      decoration: InputDecoration(
                        labelText: isAm ? 'የቅድመ ክፍያ መጠን (ብር) *' : 'Upfront Amount (ETB) *',
                        prefixText: 'ETB ',
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                        isDense: true,
                      ),
                      onChanged: (_) => setState(() {}),
                    ),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        ChoiceChip(
                          label: Text(isAm ? 'ጥሬ ገንዘብ' : 'Cash'),
                          selected: _upfrontMethod == 'cash',
                          onSelected: (v) => setState(() => _upfrontMethod = 'cash'),
                        ),
                        const SizedBox(width: 8),
                        ChoiceChip(
                          label: Text(isAm ? 'ባንክ' : 'Bank'),
                          selected: _upfrontMethod == 'bank',
                          onSelected: (v) => setState(() => _upfrontMethod = 'bank'),
                        ),
                        const SizedBox(width: 8),
                        ChoiceChip(
                          label: Text(isAm ? 'ዋሌት' : 'Wallet'),
                          selected: _upfrontMethod == 'wallet',
                          onSelected: (v) => setState(() => _upfrontMethod = 'wallet'),
                        ),
                      ],
                    ),
                    if (_upfrontMethod == 'bank' && _bankProviders.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      DropdownButtonFormField<PaymentProvider>(
                        value: _selectedBank,
                        isDense: true,
                        decoration: InputDecoration(
                          labelText: isAm ? 'ባንክ' : 'Bank',
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                        ),
                        items: _bankProviders
                            .map((b) => DropdownMenuItem(value: b, child: Text(b.name ?? b.providerKey)))
                            .toList(),
                        onChanged: (v) => setState(() => _selectedBank = v),
                      ),
                    ],
                    if (_upfrontMethod == 'wallet' && _walletProviders.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      DropdownButtonFormField<PaymentProvider>(
                        value: _selectedWallet,
                        isDense: true,
                        decoration: InputDecoration(
                          labelText: isAm ? 'ዋሌት' : 'Wallet',
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                        ),
                        items: _walletProviders
                            .map((w) => DropdownMenuItem(value: w, child: Text(w.name ?? w.providerKey)))
                            .toList(),
                        onChanged: (v) => setState(() => _selectedWallet = v),
                      ),
                    ],
                    if (_upfrontMethod != 'cash') ...[
                      const SizedBox(height: 8),
                      TextField(
                        controller: _upfrontRefCtrl,
                        decoration: InputDecoration(
                          labelText: Localizations.localeOf(context).languageCode == 'om'
                              ? 'Lakkoofsa Mirkaneessaa'
                              : (isAm ? 'የደረሰኝ ወይም ማረጋገጫ ቁጥር' : 'Reference Number'),
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                          isDense: true,
                        ),
                      ),
                    ],
                  ],
                ],
              ),
            ),
            const SizedBox(height: 16),

            // Live Calculation Banner
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              decoration: BoxDecoration(
                color: Colors.deepOrange.withValues(alpha: 0.1),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: Colors.deepOrange.withValues(alpha: 0.3)),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        isAm ? 'የሚመዘገብ የተጣራ ዱቤ' : 'Net Dube Balance',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: Theme.of(context).colorScheme.onSurfaceVariant,
                        ),
                      ),
                      Text(
                        MoneyFormatter.formatCents((remainingVal * 100).round()),
                        style: const TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.bold,
                          color: Colors.deepOrange,
                        ),
                      ),
                    ],
                  ),
                  if (_hasUpfrontPayment && upfrontVal > 0)
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text(
                          '${isAm ? "ጠቅላላ" : "Total"}: ${MoneyFormatter.formatCents((totalVal * 100).round())}',
                          style: TextStyle(fontSize: 11, color: Theme.of(context).colorScheme.onSurfaceVariant),
                        ),
                        Text(
                          '${isAm ? "ቅድመ ክፍያ" : "Upfront"}: -${MoneyFormatter.formatCents((upfrontVal * 100).round())}',
                          style: const TextStyle(fontSize: 11, color: Colors.green, fontWeight: FontWeight.bold),
                        ),
                      ],
                    ),
                ],
              ),
            ),
            const SizedBox(height: 20),

            // Submit Button
            FilledButton.icon(
              style: FilledButton.styleFrom(
                backgroundColor: Colors.deepOrange,
                padding: const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              ),
              icon: _isSaving
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                    )
                  : const Icon(Icons.check_circle_outline),
              label: Text(
                _isSaving ? (isAm ? 'በመመዝገብ ላይ...' : 'Saving...') : (isAm ? 'ዱቤ መዝግብ' : 'Register Dube'),
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
              ),
              onPressed: _isSaving ? null : _submitDube,
            ),
          ],
        ),
      ),
    );
  }
}
