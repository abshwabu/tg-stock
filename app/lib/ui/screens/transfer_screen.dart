// ignore_for_file: deprecated_member_use
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../database/app_database.dart';
import '../../repositories/ledger_repository.dart';
import '../../utils/quantity_formatter.dart';
import '../theme.dart';

class TransferScreen extends StatefulWidget {
  final LedgerRepository repository;
  final VoidCallback? onMutation;

  const TransferScreen({
    super.key,
    required this.repository,
    this.onMutation,
  });

  @override
  State<TransferScreen> createState() => _TransferScreenState();
}

class _TransferScreenState extends State<TransferScreen> with SingleTickerProviderStateMixin {
  late final TabController _tabController;

  List<Location> _locations = [];
  List<Product> _products = [];
  bool _requiresReceipt = false;
  bool _isLoading = true;

  // New Transfer Form state
  Location? _fromLocation;
  Location? _toLocation;
  Product? _selectedProduct;
  int _availableStockMilli = 0;
  final _qtyController = TextEditingController();
  final _noteController = TextEditingController();
  bool _isSubmitting = false;

  // Discrepancies list
  List<TransferDiscrepancy> _discrepancies = [];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
    _loadInitialData();
  }

  @override
  void dispose() {
    _tabController.dispose();
    _qtyController.dispose();
    _noteController.dispose();
    super.dispose();
  }

  Future<void> _loadInitialData() async {
    setState(() => _isLoading = true);
    final locs = await widget.repository.listLocations();
    final prods = (await widget.repository.listProducts(kindFilter: 'stockable')).map((pws) => pws.product).toList();
    final reqReceipt = await widget.repository.getTransferRequiresReceipt();
    final activeLoc = await widget.repository.getActiveLocation();
    final disc = await widget.repository.getDiscrepancies();

    Location? fromLoc = activeLoc;
    Location? toLoc;
    if (locs.isNotEmpty) {
      toLoc = locs.firstWhere((l) => l.id != fromLoc.id, orElse: () => locs.first);
    }

    if (mounted) {
      setState(() {
        _locations = locs;
        _products = prods;
        _requiresReceipt = reqReceipt;
        _fromLocation = fromLoc;
        _toLocation = toLoc;
        _discrepancies = disc;
        _isLoading = false;
      });
      if (prods.isNotEmpty) {
        _onProductSelected(prods.first);
      }
    }
  }

  Future<void> _onProductSelected(Product p) async {
    int stock = 0;
    if (_fromLocation != null) {
      stock = await widget.repository.stockOf(p.id, locationId: _fromLocation!.id);
    }
    setState(() {
      _selectedProduct = p;
      _availableStockMilli = stock;
    });
  }

  Future<void> _refreshDiscrepancies() async {
    final disc = await widget.repository.getDiscrepancies();
    if (mounted) setState(() => _discrepancies = disc);
  }

  Future<void> _submitTransfer() async {
    if (_fromLocation == null || _toLocation == null || _selectedProduct == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please select source, destination, and product.')),
      );
      return;
    }

    if (_fromLocation!.id == _toLocation!.id) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Source and destination locations cannot be the same.')),
      );
      return;
    }

    final qtyMilli = QuantityFormatter.parseQty(_qtyController.text);
    if (qtyMilli <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please enter a valid transfer quantity.')),
      );
      return;
    }

    // Check stock if negative stock not allowed
    final allowNeg = await widget.repository.getAllowNegativeStock();
    if (!mounted) return;
    if (!allowNeg && _availableStockMilli < qtyMilli) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Cannot transfer: only ${QuantityFormatter.formatDisplay(_availableStockMilli)} ${_selectedProduct!.unit} available at ${_fromLocation!.name}.',
          ),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    setState(() => _isSubmitting = true);
    try {
      final note = _noteController.text.trim().isNotEmpty ? _noteController.text.trim() : null;

      if (_requiresReceipt) {
        // Two-step transfer
        await widget.repository.transferOut(
          productId: _selectedProduct!.id,
          fromLocationId: _fromLocation!.id,
          toLocationId: _toLocation!.id,
          qtyMilli: qtyMilli,
          note: note,
        );

        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('🚚 Transfer dispatched! Awaiting receipt confirmation on receiver device.'),
              backgroundColor: Color(0xFF0F766E),
            ),
          );
        }
      } else {
        // Instant transfer
        await widget.repository.transferInstant(
          productId: _selectedProduct!.id,
          fromLocationId: _fromLocation!.id,
          toLocationId: _toLocation!.id,
          qtyMilli: qtyMilli,
          note: note,
        );

        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('✅ Instant transfer completed! Stock moved immediately.'),
              backgroundColor: Colors.green,
            ),
          );
        }
      }

      _qtyController.clear();
      _noteController.clear();
      widget.onMutation?.call();

      if (_selectedProduct != null) {
        await _onProductSelected(_selectedProduct!);
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Transfer failed: $e'), backgroundColor: Colors.red),
        );
      }
    } finally {
      if (mounted) setState(() => _isSubmitting = false);
    }
  }

  Future<void> _receiveFull(InTransitTransfer t) async {
    try {
      await widget.repository.transferIn(
        transferId: t.transferId,
        productId: t.productId,
        toLocationId: t.toLocationId,
        qtyReceivedMilli: t.qtyMilli,
      );
      widget.onMutation?.call();
      await _refreshDiscrepancies();

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('✅ Received ${QuantityFormatter.formatDisplay(t.qtyMilli)} ${t.unit} into ${t.toLocationName}'),
            backgroundColor: Colors.green,
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Failed to receive: $e'), backgroundColor: Colors.red),
        );
      }
    }
  }

  Future<void> _receiveDifferent(InTransitTransfer t) async {
    final qtyCtrl = TextEditingController(text: QuantityFormatter.formatDisplay(t.qtyMilli));
    final reasonCtrl = TextEditingController();

    final isAm = Localizations.localeOf(context).languageCode == 'am';
    final result = await showDialog<bool>(
      context: context,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setDialogState) {
            final enteredMilli = QuantityFormatter.parseQty(qtyCtrl.text);
            final diffMilli = enteredMilli - t.qtyMilli;
            final hasDiscrepancy = diffMilli != 0;

            return AlertDialog(
              title: Text(isAm ? 'የተለየ ብዛት ተቀበል' : 'Receive Different Qty'),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${isAm ? "ዕቃ" : "Product"}: ${t.productName}', style: const TextStyle(fontWeight: FontWeight.bold)),
                    Text('${isAm ? "የተላከ ብዛት" : "Sent Qty"}: ${QuantityFormatter.formatDisplay(t.qtyMilli)} ${t.unit}'),
                    const SizedBox(height: 12),
                    TextField(
                      controller: qtyCtrl,
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      decoration: InputDecoration(
                        labelText: isAm ? 'ትክክለኛ የተቀበሉት ብዛት (${t.unit})' : 'Actual Received Qty (${t.unit})',
                        border: const OutlineInputBorder(),
                      ),
                      onChanged: (_) => setDialogState(() {}),
                    ),
                    if (hasDiscrepancy) ...[
                      const SizedBox(height: 12),
                      Builder(builder: (ctx) {
                        final isDark = Theme.of(ctx).brightness == Brightness.dark;
                        final tokens = AppColors.warningTokens(isDark);
                        return Container(
                          padding: const EdgeInsets.all(8),
                          decoration: BoxDecoration(
                            color: tokens.bg,
                            borderRadius: BorderRadius.circular(6),
                            border: Border.all(color: tokens.border),
                          ),
                          child: Text(
                            isAm
                                ? '⚠️ ልዩነት: ${QuantityFormatter.formatDisplay(diffMilli)} ${t.unit}\nይህ ልዩነት በሪፖርት ውስጥ ምልክት ይደረግበታል፤ ክምችት በራሱ አይስተካከልም።'
                                : '⚠️ Discrepancy: ${QuantityFormatter.formatDisplay(diffMilli)} ${t.unit}\nThis discrepancy will be flagged in reports. Stock will not be auto-adjusted.',
                            style: TextStyle(fontSize: 12, color: tokens.fg),
                          ),
                        );
                      }),
                      const SizedBox(height: 10),
                      TextField(
                        controller: reasonCtrl,
                        decoration: InputDecoration(
                          labelText: isAm ? 'የልዩነቱ ምክንያት' : 'Reason for difference',
                          hintText: isAm ? 'ምሳሌ፡ በመንገድ ላይ የተሰበረ፣ የፈሰሰ፣ የጎደለ' : 'e.g. Broken in transit, spilled, missing',
                          border: const OutlineInputBorder(),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.of(ctx).pop(false),
                  child: Text(isAm ? 'ይቅር' : 'Cancel'),
                ),
                FilledButton(
                  onPressed: () => Navigator.of(ctx).pop(true),
                  child: Text(isAm ? 'አረጋግጥ' : 'Confirm Receipt'),
                ),
              ],
            );
          },
        );
      },
    );

    if (result == true) {
      final receivedMilli = QuantityFormatter.parseQty(qtyCtrl.text);
      final reason = reasonCtrl.text.trim().isNotEmpty ? reasonCtrl.text.trim() : null;

      try {
        await widget.repository.transferIn(
          transferId: t.transferId,
          productId: t.productId,
          toLocationId: t.toLocationId,
          qtyReceivedMilli: receivedMilli,
          note: reason,
        );
        widget.onMutation?.call();
        await _refreshDiscrepancies();

        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text('Recorded receipt of ${QuantityFormatter.formatDisplay(receivedMilli)} ${t.unit}'),
              backgroundColor: const Color(0xFF0F766E),
            ),
          );
        }
      } catch (e) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Failed to record receipt: $e'), backgroundColor: Colors.red),
          );
        }
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final isAm = Localizations.localeOf(context).languageCode == 'am';
    if (_isLoading) {
      return Scaffold(
        appBar: AppBar(title: Text(isAm ? '🚚 የዕቃ ዝውውር' : '🚚 Stock Transfers')),
        body: const Center(child: CircularProgressIndicator()),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(isAm ? '🚚 የዕቃ ዝውውር' : '🚚 Stock Transfers'),
        bottom: TabBar(
          controller: _tabController,
          tabs: [
            Tab(icon: const Icon(Icons.send_rounded), text: isAm ? 'አዲስ ዝውውር' : 'New Transfer'),
            StreamBuilder<List<InTransitTransfer>>(
              stream: widget.repository.watchInTransitTransfers(),
              builder: (context, snapshot) {
                final count = (snapshot.data ?? []).length;
                return Tab(
                  icon: Badge(
                    isLabelVisible: count > 0,
                    label: Text('$count'),
                    child: const Icon(Icons.move_to_inbox_rounded),
                  ),
                  text: isAm ? 'የሚደርሱ' : 'Incoming',
                );
              },
            ),
            Tab(
              icon: Badge(
                isLabelVisible: _discrepancies.isNotEmpty,
                label: Text('${_discrepancies.length}'),
                backgroundColor: Colors.amber.shade900,
                child: const Icon(Icons.warning_amber_rounded),
              ),
              text: isAm ? 'ልዩነቶች' : 'Discrepancies',
            ),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabController,
        children: [
          _buildNewTransferTab(),
          _buildIncomingTab(),
          _buildDiscrepanciesTab(),
        ],
      ),
    );
  }

  Widget _buildOfflineNoticeBanner() {
    final isAm = Localizations.localeOf(context).languageCode == 'am';
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final tokens = AppColors.infoTokens(isDark);
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: tokens.bg,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: tokens.border),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.info_outline, color: tokens.fg, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              isAm
                  ? 'ማስታወሻ፡ በሁለት የተለያዩ ስልኮች መካከል የሚደረግ ባለሁለት ደረጃ ዝውውር ወደ ተቀባዩ ስልክ የሚደርሰው ሁለቱም ስልኮች ሲመሳከሩ (sync ሲያደርጉ) ብቻ ነው።'
                  : 'Note: A two-step transfer between two different phones appears on the receiving phone only after both devices have synced.',
              style: TextStyle(
                fontSize: 12,
                color: isDark ? Theme.of(context).colorScheme.onSurface : Colors.blueGrey,
                height: 1.3,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildNewTransferTab() {
    final isAm = Localizations.localeOf(context).languageCode == 'am';
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final modeTokens = _requiresReceipt
        ? AppColors.warningTokens(isDark)
        : AppColors.successTokens(isDark);

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _buildOfflineNoticeBanner(),

          // Mode Indicator Card
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: modeTokens.bg,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: modeTokens.border),
            ),
            child: Row(
              children: [
                Icon(
                  _requiresReceipt ? Icons.receipt_long_rounded : Icons.flash_on_rounded,
                  color: modeTokens.fg,
                  size: 24,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        isAm
                            ? (_requiresReceipt ? 'ሁነታ: ባለሁለት ደረጃ (በደረሰኝ ማረጋገጫ)' : 'ሁነታ: ፈጣን ዝውውር')
                            : (_requiresReceipt ? 'Mode: Two-Step (Receipt Confirmation)' : 'Mode: Instant Transfer'),
                        style: TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 13,
                          color: modeTokens.fg,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        isAm
                            ? (_requiresReceipt
                                ? 'ላኪው የወጪ ዝውውር ይመዘግባል፤ ተቀባዩ አረጋግጦ ይረከባል።'
                                : 'ዕቃው ያለምንም ሁለተኛ ማረጋገጫ ወዲያውኑ ከቦታ ቦታ ይዘዋወራል።')
                            : (_requiresReceipt
                                ? 'Sender records transfer-out; receiver verifies and confirms received stock.'
                                : 'Stock moves immediately from source to destination without secondary confirmation.'),
                        style: TextStyle(
                          fontSize: 11.5,
                          color: Theme.of(context).colorScheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),

          // Source and Destination Locations
          Row(
            children: [
              Expanded(
                child: DropdownButtonFormField<Location>(
                  value: _fromLocation,
                  decoration: InputDecoration(
                    labelText: isAm ? 'ከየት' : 'From',
                    border: const OutlineInputBorder(),
                    prefixIcon: const Icon(Icons.outbox_rounded, color: Colors.redAccent),
                  ),
                  items: _locations.map((loc) {
                    return DropdownMenuItem<Location>(
                      value: loc,
                      child: Text(loc.name, overflow: TextOverflow.ellipsis),
                    );
                  }).toList(),
                  onChanged: (loc) {
                    if (loc != null) {
                      setState(() => _fromLocation = loc);
                      if (_selectedProduct != null) {
                        _onProductSelected(_selectedProduct!);
                      }
                    }
                  },
                ),
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 8),
                child: Icon(Icons.arrow_forward_rounded, color: Theme.of(context).colorScheme.onSurfaceVariant),
              ),
              Expanded(
                child: DropdownButtonFormField<Location>(
                  value: _toLocation,
                  decoration: InputDecoration(
                    labelText: isAm ? 'ወዴት' : 'To',
                    border: const OutlineInputBorder(),
                    prefixIcon: const Icon(Icons.move_to_inbox_rounded, color: Colors.green),
                  ),
                  items: _locations.map((loc) {
                    return DropdownMenuItem<Location>(
                      value: loc,
                      child: Text(loc.name, overflow: TextOverflow.ellipsis),
                    );
                  }).toList(),
                  onChanged: (loc) {
                    if (loc != null) setState(() => _toLocation = loc);
                  },
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),

          // Product Selector
          DropdownButtonFormField<Product>(
            value: _selectedProduct,
            decoration: InputDecoration(
              labelText: isAm ? 'ዕቃ ይምረጡ' : 'Select Product',
              border: const OutlineInputBorder(),
              prefixIcon: const Icon(Icons.inventory_2_outlined),
              helperText: _selectedProduct != null && _fromLocation != null
                  ? (isAm
                      ? 'በ${_fromLocation!.name} የሚገኝ: ${QuantityFormatter.formatDisplay(_availableStockMilli)} ${_selectedProduct!.unit}'
                      : 'Available at ${_fromLocation!.name}: ${QuantityFormatter.formatDisplay(_availableStockMilli)} ${_selectedProduct!.unit}')
                  : null,
              helperStyle: TextStyle(
                fontWeight: FontWeight.bold,
                color: _availableStockMilli > 0
                    ? (isDark ? const Color(0xFF4ADE80) : Colors.green.shade800)
                    : (isDark ? const Color(0xFFF87171) : Colors.red.shade800),
              ),
            ),
            items: _products.map((p) {
              return DropdownMenuItem<Product>(
                value: p,
                child: Text(p.name, overflow: TextOverflow.ellipsis),
              );
            }).toList(),
            onChanged: (p) {
              if (p != null) _onProductSelected(p);
            },
          ),
          const SizedBox(height: 16),

          // Quantity to transfer
          TextField(
            controller: _qtyController,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(
              labelText: isAm
                  ? 'የሚዘዋወረው ብዛት (${_selectedProduct?.unit ?? "ፍሬ"})'
                  : 'Quantity to Transfer (${_selectedProduct?.unit ?? "pcs"})',
              hintText: 'e.g. 5 or 12.5',
              border: const OutlineInputBorder(),
              prefixIcon: const Icon(Icons.pin_outlined),
            ),
          ),
          const SizedBox(height: 16),

          // Note
          TextField(
            controller: _noteController,
            decoration: InputDecoration(
              labelText: isAm ? 'ማስታወሻ (አማራጭ)' : 'Note (Optional)',
              hintText: isAm ? 'ምሳሌ፡ ለጠዋት ፈረቃ ማሟያ' : 'e.g. Replenishment for morning shift',
              border: const OutlineInputBorder(),
              prefixIcon: const Icon(Icons.edit_note_rounded),
            ),
          ),
          const SizedBox(height: 24),

          FilledButton.icon(
            style: FilledButton.styleFrom(
              backgroundColor: const Color(0xFF0F766E),
              padding: const EdgeInsets.symmetric(vertical: 16),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            ),
            onPressed: _isSubmitting ? null : _submitTransfer,
            icon: _isSubmitting
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                  )
                : const Icon(Icons.send_rounded),
            label: Text(
              isAm
                  ? (_requiresReceipt ? 'ዝውውር ላክ' : 'ዝውውሩን አረጋግጥ')
                  : (_requiresReceipt ? 'Dispatch Transfer' : 'Confirm Transfer'),
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildIncomingTab() {
    final isAm = Localizations.localeOf(context).languageCode == 'am';
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: _buildOfflineNoticeBanner(),
        ),
        Expanded(
          child: StreamBuilder<List<InTransitTransfer>>(
            stream: widget.repository.watchInTransitTransfers(),
            builder: (context, snapshot) {
              final items = snapshot.data ?? [];
              if (items.isEmpty) {
                return Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(Icons.inbox_outlined, size: 64, color: Theme.of(context).colorScheme.outlineVariant),
                      const SizedBox(height: 12),
                      Text(
                        isAm ? 'ምንም በመንገድ ላይ ያለ ዝውውር የለም' : 'No incoming transfers pending',
                        textAlign: TextAlign.center,
                        style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant, fontSize: 14),
                      ),
                    ],
                  ),
                );
              }

              return ListView.separated(
                padding: const EdgeInsets.all(16),
                itemCount: items.length,
                separatorBuilder: (_, __) => const SizedBox(height: 12),
                itemBuilder: (context, idx) {
                  final t = items[idx];
                  final timeStr = DateFormat('MMM d, HH:mm').format(
                    DateTime.fromMillisecondsSinceEpoch(t.sentAtMs),
                  );

                  return Card(
                    elevation: 2,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              const Icon(Icons.local_shipping_outlined, color: Color(0xFF0F766E), size: 22),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  t.productName,
                                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                                ),
                              ),
                              Builder(builder: (ctx) {
                                final isDark = Theme.of(ctx).brightness == Brightness.dark;
                                final tokens = AppColors.warningTokens(isDark);
                                return Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                  decoration: BoxDecoration(
                                    color: tokens.bg,
                                    borderRadius: BorderRadius.circular(6),
                                    border: Border.all(color: tokens.border, width: 0.8),
                                  ),
                                  child: Text(
                                    isAm ? 'በመንገድ ላይ' : 'In Transit',
                                    style: TextStyle(
                                      fontSize: 11,
                                      fontWeight: FontWeight.bold,
                                      color: tokens.fg,
                                    ),
                                  ),
                                );
                              }),
                            ],
                          ),
                          const SizedBox(height: 8),
                          Row(
                            children: [
                              Text(
                                '${t.fromLocationName} ➔ ${t.toLocationName}',
                                style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                              ),
                              const Spacer(),
                              Text(
                                '${QuantityFormatter.formatDisplay(t.qtyMilli)} ${t.unit}',
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: Color(0xFF0F766E)),
                              ),
                            ],
                          ),
                          if (t.note != null && t.note!.isNotEmpty) ...[
                            const SizedBox(height: 4),
                            Text(
                              '${isAm ? "ማስታወሻ" : "Note"}: ${t.note}',
                              style: TextStyle(
                                fontSize: 12,
                                color: Theme.of(context).colorScheme.onSurfaceVariant,
                              ),
                            ),
                          ],
                          const SizedBox(height: 4),
                          Text(
                            '${isAm ? "የተላከው" : "Sent"}: $timeStr',
                            style: TextStyle(fontSize: 11, color: Theme.of(context).colorScheme.onSurfaceVariant),
                          ),
                          const Divider(height: 20),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.end,
                            children: [
                              OutlinedButton.icon(
                                icon: const Icon(Icons.edit, size: 16),
                                label: Text(isAm ? 'የተለየ ብዛት' : 'Different Qty'),
                                onPressed: () => _receiveDifferent(t),
                              ),
                              const SizedBox(width: 8),
                              FilledButton.icon(
                                style: FilledButton.styleFrom(backgroundColor: const Color(0xFF0F766E)),
                                icon: const Icon(Icons.check, size: 16),
                                label: Text(isAm ? 'ሙሉ ተቀበል' : 'Receive Full'),
                                onPressed: () => _receiveFull(t),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  );
                },
              );
            },
          ),
        ),
      ],
    );
  }

  Widget _buildDiscrepanciesTab() {
    final isAm = Localizations.localeOf(context).languageCode == 'am';
    if (_discrepancies.isEmpty) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.check_circle_outline, size: 64, color: Colors.green.shade400),
            const SizedBox(height: 12),
            Text(
              isAm ? 'ምንም የዝውውር ልዩነት የለም' : 'No transfer discrepancies',
              textAlign: TextAlign.center,
              style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant, fontSize: 14),
            ),
          ],
        ),
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: _discrepancies.length,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (context, idx) {
        final d = _discrepancies[idx];
        final timeStr = DateFormat('MMM d, HH:mm').format(
          DateTime.fromMillisecondsSinceEpoch(d.receivedAtMs),
        );

        final diff = d.diffMilli;
        final isShortage = diff < 0;

        final isDark = Theme.of(context).brightness == Brightness.dark;
        final tokens = isShortage ? AppColors.dangerTokens(isDark) : AppColors.warningTokens(isDark);

        return Card(
          elevation: isDark ? 0 : 1,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(10),
            side: BorderSide(color: isDark ? tokens.border : AppColors.outlineLight, width: 0.8),
          ),
          color: tokens.bg,
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(
                      isShortage ? Icons.trending_down_rounded : Icons.trending_up_rounded,
                      color: tokens.fg,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        d.productName,
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                      ),
                    ),
                    Text(
                      '${diff > 0 ? "+" : ""}${QuantityFormatter.formatDisplay(diff)} ${d.unit}',
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 14,
                        color: tokens.fg,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  isAm
                      ? 'ከ: ${d.fromLocationName} ➔ ወደ: ${d.toLocationName}'
                      : 'From: ${d.fromLocationName} ➔ To: ${d.toLocationName}',
                  style: const TextStyle(fontSize: 12.5),
                ),
                Text(
                  isAm
                      ? 'የተላከ: ${QuantityFormatter.formatDisplay(d.sentQtyMilli)} • የተቀበሉት: ${QuantityFormatter.formatDisplay(d.receivedQtyMilli)}'
                      : 'Sent: ${QuantityFormatter.formatDisplay(d.sentQtyMilli)} • Received: ${QuantityFormatter.formatDisplay(d.receivedQtyMilli)}',
                  style: TextStyle(fontSize: 12, color: Theme.of(context).colorScheme.onSurface),
                ),
                if (d.reason != null && d.reason!.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    '${isAm ? "ምክንያት" : "Reason"}: ${d.reason}',
                    style: TextStyle(
                      fontSize: 12,
                      fontStyle: FontStyle.italic,
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
                const SizedBox(height: 4),
                Text(
                  timeStr,
                  style: TextStyle(fontSize: 11, color: Theme.of(context).colorScheme.onSurfaceVariant),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}
