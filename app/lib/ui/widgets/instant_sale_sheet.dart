import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../database/app_database.dart';
import '../../repositories/ledger_repository.dart';
import '../../utils/money_formatter.dart';
import '../theme.dart';

/// Opens the Instant Sale modal sheet directly from the Home screen.
Future<bool?> showInstantSaleSheet(
  BuildContext context, {
  required LedgerRepository repository,
  VoidCallback? onComplete,
}) {
  return showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
    ),
    builder: (ctx) => InstantSaleSheet(
      repository: repository,
      onComplete: onComplete,
    ),
  );
}

class InstantSaleSheet extends StatefulWidget {
  final LedgerRepository repository;
  final VoidCallback? onComplete;

  const InstantSaleSheet({
    super.key,
    required this.repository,
    this.onComplete,
  });

  @override
  State<InstantSaleSheet> createState() => _InstantSaleSheetState();
}

class _InstantSaleSheetState extends State<InstantSaleSheet> {
  final _priceCtrl = TextEditingController();
  final _noteCtrl = TextEditingController();
  final _refCtrl = TextEditingController();

  String _selectedMethod = 'cash';
  List<PaymentProvider> _bankProviders = [];
  List<PaymentProvider> _walletProviders = [];
  PaymentProvider? _selectedBank;
  PaymentProvider? _selectedWallet;

  bool _isLoading = true;
  bool _isSaving = false;
  bool _showDetails = false;

  @override
  void initState() {
    super.initState();
    _loadProviders();
  }

  @override
  void dispose() {
    _priceCtrl.dispose();
    _noteCtrl.dispose();
    _refCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadProviders() async {
    final banks = await widget.repository.listPaymentProviders(kind: 'bank', enabledOnly: true);
    final wallets = await widget.repository.listPaymentProviders(kind: 'wallet', enabledOnly: true);

    if (mounted) {
      setState(() {
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

  void _addPreset(int etbAmount) {
    HapticFeedback.selectionClick();
    final currentCents = MoneyFormatter.parseCents(_priceCtrl.text);
    final newCents = currentCents + (etbAmount * 100);
    _priceCtrl.text = (newCents / 100).toStringAsFixed(newCents % 100 == 0 ? 0 : 2);
    setState(() {});
  }

  void _setPreset(int etbAmount) {
    HapticFeedback.selectionClick();
    _priceCtrl.text = etbAmount.toString();
    setState(() {});
  }

  Future<void> _finishSale() async {
    if (_isSaving) return;

    final isAm = Localizations.localeOf(context).languageCode == 'am';
    final priceCents = MoneyFormatter.parseCents(_priceCtrl.text);
    if (priceCents <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          backgroundColor: Colors.red.shade700,
          behavior: SnackBarBehavior.floating,
          content: Text(
            isAm ? 'እባክዎ ትክክለኛ የሽያጭ ዋጋ ያስገቡ' : 'Please enter a valid sale price',
          ),
        ),
      );
      return;
    }

    setState(() => _isSaving = true);
    try {
      String? providerKey;
      if (_selectedMethod == 'bank') providerKey = _selectedBank?.providerKey;
      if (_selectedMethod == 'wallet') providerKey = _selectedWallet?.providerKey;

      await widget.repository.recordInstantSale(
        priceCents: priceCents,
        paymentMethod: _selectedMethod,
        providerKey: providerKey,
        reference: _refCtrl.text.trim().isNotEmpty ? _refCtrl.text.trim() : null,
        note: _noteCtrl.text.trim().isNotEmpty ? _noteCtrl.text.trim() : 'Instant Sale',
      );

      HapticFeedback.mediumImpact();
      if (mounted) {
        Navigator.of(context).pop(true);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            backgroundColor: const Color(0xFF0F766E),
            behavior: SnackBarBehavior.floating,
            content: Row(
              children: [
                const Icon(Icons.check_circle, color: Colors.white),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    isAm
                        ? 'የ ${MoneyFormatter.formatCents(priceCents)} ቅጽበታዊ ሽያጭ ተጠናቋል!'
                        : 'Instant sale of ${MoneyFormatter.formatCents(priceCents)} completed!',
                    style: const TextStyle(fontWeight: FontWeight.bold),
                  ),
                ),
              ],
            ),
          ),
        );
        widget.onComplete?.call();
      }
    } catch (e) {
      if (mounted) {
        setState(() => _isSaving = false);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            backgroundColor: Colors.red.shade800,
            content: Text('Error: $e'),
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final isAm = Localizations.localeOf(context).languageCode == 'am';
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final currentCents = MoneyFormatter.parseCents(_priceCtrl.text);

    return Container(
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        top: 14,
        bottom: MediaQuery.of(context).viewInsets.bottom + 20,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Top pill handle
          Center(
            child: Container(
              width: 38,
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
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: Colors.amber.shade700.withValues(alpha: 0.15),
                  shape: BoxShape.circle,
                ),
                child: Icon(
                  Icons.bolt_rounded,
                  color: isDark ? Colors.amber.shade400 : Colors.amber.shade800,
                  size: 26,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      isAm ? 'ቅጽበታዊ ሽያጭ' : 'Instant Sale',
                      style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                    ),
                    Text(
                      isAm ? 'ዋጋ ብቻ በማስገባት ፈጣን ሽያጭ ይጨርሱ' : 'Enter price only to finish sale instantly',
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
          const SizedBox(height: 18),

          // Price Input Field (Large, autofocus)
          TextField(
            controller: _priceCtrl,
            autofocus: true,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textInputAction: TextInputAction.done,
            onSubmitted: (_) => _finishSale(),
            style: TextStyle(
              fontSize: 28,
              fontWeight: FontWeight.bold,
              color: isDark ? Colors.amber.shade300 : const Color(0xFF0F766E),
            ),
            decoration: InputDecoration(
              labelText: isAm ? 'የሽያጭ ዋጋ (ብር) *' : 'Sale Price (ETB) *',
              hintText: '0.00',
              prefixText: 'ETB  ',
              prefixStyle: TextStyle(
                fontSize: 22,
                fontWeight: FontWeight.bold,
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
              filled: true,
              fillColor: isDark
                  ? Theme.of(context).colorScheme.surfaceContainerHighest
                  : Colors.grey.shade50,
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(16),
                borderSide: BorderSide(color: Theme.of(context).colorScheme.outline),
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(16),
                borderSide: BorderSide(
                  color: isDark ? Colors.amber.shade400 : const Color(0xFF0F766E),
                  width: 2,
                ),
              ),
              suffixIcon: _priceCtrl.text.isNotEmpty
                  ? IconButton(
                      icon: const Icon(Icons.clear_rounded),
                      onPressed: () {
                        _priceCtrl.clear();
                        setState(() {});
                      },
                    )
                  : null,
            ),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 12),

          // Quick Preset Amount Chips
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                ActionChip(
                  avatar: const Icon(Icons.add, size: 16),
                  label: const Text('10'),
                  onPressed: () => _addPreset(10),
                ),
                const SizedBox(width: 6),
                ActionChip(
                  avatar: const Icon(Icons.add, size: 16),
                  label: const Text('50'),
                  onPressed: () => _addPreset(50),
                ),
                const SizedBox(width: 6),
                ActionChip(
                  avatar: const Icon(Icons.add, size: 16),
                  label: const Text('100'),
                  onPressed: () => _addPreset(100),
                ),
                const SizedBox(width: 6),
                ActionChip(
                  avatar: const Icon(Icons.add, size: 16),
                  label: const Text('200'),
                  onPressed: () => _addPreset(200),
                ),
                const SizedBox(width: 6),
                ActionChip(
                  avatar: const Icon(Icons.add, size: 16),
                  label: const Text('500'),
                  onPressed: () => _addPreset(500),
                ),
                const SizedBox(width: 6),
                ActionChip(
                  avatar: const Icon(Icons.add, size: 16),
                  label: const Text('1,000'),
                  onPressed: () => _addPreset(1000),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),

          // Payment Method Selector (Defaults to Cash)
          Row(
            children: [
              ChoiceChip(
                avatar: const Icon(Icons.money_rounded, size: 18),
                label: Text(isAm ? 'ጥሬ ገንዘብ' : 'Cash'),
                selected: _selectedMethod == 'cash',
                onSelected: (sel) {
                  if (sel) setState(() => _selectedMethod = 'cash');
                },
              ),
              const SizedBox(width: 8),
              ChoiceChip(
                avatar: const Icon(Icons.account_balance_rounded, size: 18),
                label: Text(isAm ? 'ባንክ' : 'Bank'),
                selected: _selectedMethod == 'bank',
                onSelected: (sel) {
                  if (sel) setState(() => _selectedMethod = 'bank');
                },
              ),
              const SizedBox(width: 8),
              ChoiceChip(
                avatar: const Icon(Icons.phone_android_rounded, size: 18),
                label: Text(isAm ? 'ዋሌት' : 'Wallet'),
                selected: _selectedMethod == 'wallet',
                onSelected: (sel) {
                  if (sel) setState(() => _selectedMethod = 'wallet');
                },
              ),
              const Spacer(),
              TextButton.icon(
                style: TextButton.styleFrom(
                  padding: EdgeInsets.zero,
                  visualDensity: VisualDensity.compact,
                ),
                onPressed: () => setState(() => _showDetails = !_showDetails),
                icon: Icon(
                  _showDetails ? Icons.expand_less : Icons.expand_more,
                  size: 18,
                ),
                label: Text(
                  _showDetails ? (isAm ? 'አሳንስ' : 'Less') : (isAm ? 'ተጨማሪ' : 'Details'),
                  style: const TextStyle(fontSize: 12),
                ),
              ),
            ],
          ),

          // Expanded Details: Bank/Wallet selection, Reference, and Note
          if (_selectedMethod == 'bank' && _bankProviders.isNotEmpty) ...[
            const SizedBox(height: 10),
            DropdownButtonFormField<PaymentProvider>(
              initialValue: _selectedBank,
              decoration: InputDecoration(
                labelText: isAm ? 'ባንክ ይምረጡ' : 'Select Bank',
                border: const OutlineInputBorder(),
                isDense: true,
              ),
              items: _bankProviders
                  .map((b) => DropdownMenuItem(value: b, child: Text(b.name ?? b.providerKey)))
                  .toList(),
              onChanged: (b) => setState(() => _selectedBank = b),
            ),
          ],
          if (_selectedMethod == 'wallet' && _walletProviders.isNotEmpty) ...[
            const SizedBox(height: 10),
            DropdownButtonFormField<PaymentProvider>(
              initialValue: _selectedWallet,
              decoration: InputDecoration(
                labelText: isAm ? 'ዋሌት ይምረጡ' : 'Select Wallet',
                border: const OutlineInputBorder(),
                isDense: true,
              ),
              items: _walletProviders
                  .map((w) => DropdownMenuItem(value: w, child: Text(w.name ?? w.providerKey)))
                  .toList(),
              onChanged: (w) => setState(() => _selectedWallet = w),
            ),
          ],
          if (_showDetails || _selectedMethod != 'cash') ...[
            const SizedBox(height: 10),
            TextField(
              controller: _refCtrl,
              decoration: InputDecoration(
                labelText: isAm ? 'የግብይት ማረጋገጫ ቁጥር (አማራጭ)' : 'Transaction Reference (optional)',
                border: const OutlineInputBorder(),
                isDense: true,
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _noteCtrl,
              decoration: InputDecoration(
                labelText: isAm ? 'ማስታወሻ (አማራጭ)' : 'Note (optional)',
                hintText: isAm ? 'ለምሳሌ፡ የተለያዩ እቃዎች' : 'e.g. Miscellaneous items',
                border: const OutlineInputBorder(),
                isDense: true,
              ),
            ),
          ],
          const SizedBox(height: 18),

          // Finish Sale Button
          FilledButton.icon(
            style: FilledButton.styleFrom(
              backgroundColor: isDark ? const Color(0xFFD97706) : Colors.amber.shade800,
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(vertical: 14),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              elevation: 2,
            ),
            onPressed: _isSaving ? null : _finishSale,
            icon: _isSaving
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                  )
                : const Icon(Icons.check_circle_rounded, size: 22),
            label: Text(
              _isSaving
                  ? (isAm ? 'በመመዝገብ ላይ...' : 'Recording...')
                  : (currentCents > 0
                      ? '${isAm ? "ሽያጭ ጨርስ" : "Finish Sale"} • ${MoneyFormatter.formatCents(currentCents)}'
                      : (isAm ? 'ሽያጭ ጨርስ' : 'Finish Sale')),
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
            ),
          ),
        ],
      ),
    );
  }
}
