import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../database/app_database.dart';
import '../../repositories/ledger_repository.dart';
import '../../services/notification_service.dart';
import '../../utils/money_formatter.dart';
import '../../utils/quantity_formatter.dart';
import '../theme.dart';
import '../widgets/location_selector_chip.dart';
import '../widgets/payment_step_sheet.dart';

class SaleScreen extends StatefulWidget {
  final LedgerRepository repository;
  final VoidCallback? onMutation;

  const SaleScreen({
    super.key,
    required this.repository,
    this.onMutation,
  });

  @override
  State<SaleScreen> createState() => _SaleScreenState();
}

class _SaleScreenState extends State<SaleScreen> {
  final List<SaleLineItem> _cart = [];
  List<Product> _recentProducts = [];
  Location? _activeLocation;
  bool _isLoading = false;

  @override
  void initState() {
    super.initState();
    _loadRecents();
  }

  Future<void> _loadRecents() async {
    final recents = await widget.repository.getRecentProducts(limit: 6);
    final activeLoc = await widget.repository.getActiveLocation();
    if (mounted) {
      setState(() {
        _recentProducts = recents;
        _activeLocation = activeLoc;
      });
    }
  }

  int get _cartTotalCents {
    return _cart.fold<int>(0, (sum, item) => sum + item.totalCents);
  }

  Future<void> _showProductPicker() async {
    final all = await widget.repository.listProducts();
    if (!mounted) return;

    final selected = await showModalBottomSheet<Product>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => _ProductPickerSheet(products: all),
    );

    if (selected != null) {
      _promptQuantityAndPrice(selected);
    }
  }

  Future<void> _promptQuantityAndPrice(Product product) async {
    final isService = product.kind == 'service';
    final isConsumable = product.kind == 'consumable';
    final currentStock = isService ? 0 : await widget.repository.stockOf(product.id, locationId: _activeLocation?.id);
    int qtyMilli = 1000; // default 1 unit
    int priceCents = product.sellPriceCents;

    // Load recipe materials if consumable
    final List<({Product materialProduct, int stockMilli, int requiredPerUnitMilli})> recipeMaterials = [];
    if (isConsumable) {
      final mats = await widget.repository.getProductMaterials(product.id);
      for (final m in mats) {
        final p = await widget.repository.getProduct(m.materialProductId);
        if (p != null) {
          final s = await widget.repository.stockOf(p.id, locationId: _activeLocation?.id);
          recipeMaterials.add((materialProduct: p, stockMilli: s, requiredPerUnitMilli: m.qtyMilli));
        }
      }
    }

    final priceCtrl = TextEditingController(
      text: (product.askPrice && priceCents == 0) ? '' : (priceCents / 100).toStringAsFixed(2),
    );
    final customQtyCtrl = TextEditingController(text: '1');

    if (!mounted) return;

    final added = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheetState) {
          final isDark = Theme.of(ctx).brightness == Brightness.dark;
          final warningTokens = AppColors.warningTokens(isDark);
          final stockWarning = !isService && !isConsumable && (currentStock < qtyMilli);

          final materialShortages = <String>[];
          if (isConsumable) {
            for (final rm in recipeMaterials) {
              final needed = ((rm.requiredPerUnitMilli * qtyMilli) + 500) ~/ 1000;
              if (rm.stockMilli < needed) {
                materialShortages.add(
                  '${rm.materialProduct.name}: needs ${QuantityFormatter.formatDisplay(needed)} ${rm.materialProduct.unit}, '
                  'only ${QuantityFormatter.formatDisplay(rm.stockMilli)} available',
                );
              }
            }
          }

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
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Text(
                        product.name,
                        style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                      ),
                    ),
                    if (isService)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                        decoration: BoxDecoration(
                          color: isDark ? const Color(0xFF1E3A8A).withValues(alpha: 0.35) : Colors.blue.shade50,
                          borderRadius: BorderRadius.circular(6),
                          border: Border.all(color: isDark ? const Color(0xFF1D4ED8) : Colors.blue.shade200),
                        ),
                        child: Text(
                          '🛠 Service',
                          style: TextStyle(
                            color: isDark ? const Color(0xFF93C5FD) : Colors.blue.shade900,
                            fontWeight: FontWeight.bold,
                            fontSize: 11,
                          ),
                        ),
                      )
                    else if (isConsumable)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                        decoration: BoxDecoration(
                          color: isDark ? const Color(0xFF581C87).withValues(alpha: 0.35) : Colors.purple.shade50,
                          borderRadius: BorderRadius.circular(6),
                          border: Border.all(color: isDark ? const Color(0xFF7E22CE) : Colors.purple.shade200),
                        ),
                        child: Text(
                          '🧪 Consumable',
                          style: TextStyle(
                            color: isDark ? const Color(0xFFC084FC) : Colors.purple.shade900,
                            fontWeight: FontWeight.bold,
                            fontSize: 11,
                          ),
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 4),
                if (isService)
                  Text(
                    'Service • No stock tracking',
                    style: TextStyle(
                      fontSize: 13,
                      color: isDark ? const Color(0xFF60A5FA) : Colors.blue.shade700,
                      fontWeight: FontWeight.w500,
                    ),
                  )
                else if (isConsumable)
                  Text(
                    'Consumable • ${recipeMaterials.isEmpty ? 'No raw materials' : '${recipeMaterials.length} raw material(s)'}',
                    style: TextStyle(
                      fontSize: 13,
                      color: isDark ? const Color(0xFFC084FC) : Colors.purple.shade700,
                      fontWeight: FontWeight.w500,
                    ),
                  )
                else
                  Text(
                    'Available Stock: ${QuantityFormatter.formatDisplay(currentStock)} ${product.unit}',
                    style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: currentStock <= 0
                          ? (isDark ? const Color(0xFFF87171) : Colors.red)
                          : (isDark ? const Color(0xFF4ADE80) : Colors.green.shade800),
                    ),
                  ),
                if (stockWarning) ...[
                  const SizedBox(height: 6),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: warningTokens.bg,
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: warningTokens.border),
                    ),
                    child: Text(
                      '⚠️ Warning: Requesting more than available stock',
                      style: TextStyle(fontSize: 12, color: warningTokens.fg, fontWeight: FontWeight.w600),
                    ),
                  ),
                ],
                if (materialShortages.isNotEmpty) ...[
                  const SizedBox(height: 6),
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: warningTokens.bg,
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: warningTokens.border),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '⚠️ Material Shortage Warning:',
                          style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.bold, color: warningTokens.fg),
                        ),
                        const SizedBox(height: 2),
                        ...materialShortages.map((s) => Text(
                          '• $s',
                          style: TextStyle(fontSize: 11, color: warningTokens.fg),
                        )),
                      ],
                    ),
                  ),
                ],
                const SizedBox(height: 16),
                const Text('Quick Quantity / ፈጣን መጠን:', style: TextStyle(fontSize: 12, color: Colors.grey)),
                const SizedBox(height: 8),
                Row(
                  children: [1, 2, 5, 10].map((n) {
                    final isSelected = qtyMilli == n * 1000;
                    final isDark = Theme.of(ctx).brightness == Brightness.dark;
                    return Expanded(
                      child: Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 3),
                        child: OutlinedButton(
                          style: OutlinedButton.styleFrom(
                            backgroundColor: isSelected
                                ? (isDark ? Colors.deepOrange.withValues(alpha: 0.25) : Colors.deepOrange.shade50)
                                : null,
                            side: BorderSide(
                              color: isSelected ? Colors.deepOrange : Theme.of(ctx).colorScheme.outline,
                              width: isSelected ? 2 : 1,
                            ),
                          ),
                          onPressed: () {
                            setSheetState(() {
                              qtyMilli = n * 1000;
                              customQtyCtrl.text = n.toString();
                            });
                          },
                          child: Text(
                            '+$n',
                            style: TextStyle(
                              fontWeight: FontWeight.bold,
                              color: isSelected ? Colors.deepOrange : Theme.of(ctx).colorScheme.onSurface,
                            ),
                          ),
                        ),
                      ),
                    );
                  }).toList(),
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      flex: 1,
                      child: TextField(
                        controller: customQtyCtrl,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: InputDecoration(
                          labelText: 'Custom Qty (${product.unit})',
                          border: const OutlineInputBorder(),
                        ),
                        onChanged: (val) {
                          setSheetState(() {
                            qtyMilli = QuantityFormatter.parseQty(val);
                          });
                        },
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      flex: 1,
                      child: TextField(
                        controller: priceCtrl,
                        autofocus: product.askPrice,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: InputDecoration(
                          labelText: product.askPrice ? 'Unit Price * (Ask)' : 'Unit Price (ETB)',
                          border: const OutlineInputBorder(),
                        ),
                        onChanged: (val) {
                          setSheetState(() {
                            priceCents = MoneyFormatter.parseCents(val);
                          });
                        },
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 20),
                FilledButton.icon(
                  style: FilledButton.styleFrom(
                    backgroundColor: Colors.deepOrange,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  onPressed: () {
                    if (qtyMilli <= 0) return;
                    Navigator.of(ctx).pop(true);
                  },
                  icon: const Icon(Icons.add_shopping_cart),
                  label: Text('Add to Cart (${MoneyFormatter.formatCents((qtyMilli * priceCents) ~/ 1000)})'),
                ),
              ],
            ),
          );
        },
      ),
    );

    if (added == true) {
      HapticFeedback.lightImpact();
      setState(() {
        _cart.add(SaleLineItem(
          product: product,
          qtyMilli: qtyMilli,
          unitPriceCents: priceCents,
        ));
      });
    }
  }

  Future<void> _finishSale() async {
    if (_cart.isEmpty || _isLoading) return;

    setState(() => _isLoading = true);
    try {
      final targetLocId = _activeLocation?.id;
      final allowNegative = await widget.repository.getAllowNegativeStock();

      if (!allowNegative) {
        for (final item in _cart) {
          if (item.product.kind == 'stockable') {
            final currentStock = await widget.repository.stockOf(item.product.id, locationId: targetLocId);
            if (currentStock < item.qtyMilli) {
              HapticFeedback.vibrate();
              if (mounted) {
                showDialog(
                  context: context,
                  builder: (ctx) => AlertDialog(
                    title: const Text('⚠️ Insufficient Stock'),
                    content: Text(
                      'Cannot complete sale because "${item.product.name}" has only '
                      '${QuantityFormatter.formatDisplay(currentStock)} available in stock.\n\n'
                      'To allow selling into negative stock, enable "Allow Negative Stock" in Settings.',
                    ),
                    actions: [
                      FilledButton(
                        onPressed: () => Navigator.of(ctx).pop(),
                        child: const Text('OK'),
                      ),
                    ],
                  ),
                );
              }
              return;
            }
          }
        }
      }

      final blockMaterialShort = await widget.repository.getBlockSaleWhenMaterialShort();
      if (blockMaterialShort) {
        for (final item in _cart) {
          if (item.product.kind == 'consumable') {
            final materials = await widget.repository.getProductMaterials(item.product.id);
            for (final mat in materials) {
              final matProd = await widget.repository.getProduct(mat.materialProductId);
              final matStock = await widget.repository.stockOf(mat.materialProductId, locationId: targetLocId);
              final neededMilli = ((mat.qtyMilli * item.qtyMilli) + 500) ~/ 1000;
              if (matStock < neededMilli) {
                HapticFeedback.vibrate();
                if (mounted) {
                  showDialog(
                    context: context,
                    builder: (ctx) => AlertDialog(
                      title: const Text('⚠️ Material Shortage / የጥሬ ዕቃ እጥረት'),
                      content: Text(
                        'Cannot complete sale because raw material "${matProd?.name ?? mat.materialProductId}" '
                        'for "${item.product.name}" is short (needs ${QuantityFormatter.formatDisplay(neededMilli)}, '
                        'only ${QuantityFormatter.formatDisplay(matStock)} available).\n\n'
                        'To allow selling when materials are short, disable "Block sale when raw materials are short" in Settings.',
                      ),
                      actions: [
                        FilledButton(
                          onPressed: () => Navigator.of(ctx).pop(),
                          child: const Text('OK'),
                        ),
                      ],
                    ),
                  );
                }
                return;
              }
            }
          }
        }
      }

      final itemsToNotify = List<SaleLineItem>.from(_cart);
      final totalCents = _cartTotalCents;

      if (!mounted) return;
      setState(() => _isLoading = false);

      await showModalBottomSheet(
        context: context,
        isScrollControlled: true,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
        ),
        builder: (ctx) => PaymentStepSheet(
          repository: widget.repository,
          totalCents: totalCents,
          items: itemsToNotify,
          locationId: _activeLocation?.id,
          onComplete: () async {
            HapticFeedback.lightImpact();
            final notifService = NotificationService(repository: widget.repository);
            for (final item in itemsToNotify) {
              if (item.product.kind == 'stockable') {
                final currentStock = await widget.repository.stockOf(
                  item.product.id,
                  locationId: _activeLocation?.id,
                );
                await notifService.notifyLowStock(
                  product: item.product,
                  currentStockMilli: currentStock,
                  locationName: _activeLocation?.name,
                );
              } else if (item.product.kind == 'consumable') {
                final materials = await widget.repository.getProductMaterials(item.product.id);
                for (final mat in materials) {
                  final matProd = await widget.repository.getProduct(mat.materialProductId);
                  if (matProd != null) {
                    final matStock = await widget.repository.stockOf(
                      mat.materialProductId,
                      locationId: _activeLocation?.id,
                    );
                    await notifService.notifyLowStock(
                      product: matProd,
                      currentStockMilli: matStock,
                      locationName: _activeLocation?.name,
                    );
                  }
                }
              }
            }

            widget.onMutation?.call();

            if (mounted) {
              final totalFormatted = MoneyFormatter.formatCents(totalCents);
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(
                  backgroundColor: Colors.green,
                  content: Text('🎉 Sale completed! Total: $totalFormatted'),
                ),
              );
              setState(() {
                _cart.clear();
              });
            }
          },
        ),
      );
    } finally {
      if (mounted) {
        setState(() => _isLoading = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Sale / ሽያጭ'),
        actions: [
          LocationSelectorChip(
            repository: widget.repository,
            onLocationChanged: (loc) => setState(() => _activeLocation = loc),
          ),
          if (_cart.isNotEmpty)
            TextButton(
              onPressed: () => setState(() => _cart.clear()),
              child: const Text('Clear', style: TextStyle(color: Colors.red)),
            ),
        ],
      ),
      body: Column(
        children: [
          // Recent items chips
          if (_recentProducts.isNotEmpty) ...[
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Text(
                  'Recent / ተደጋጋሚ ዕቃዎች:',
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.bold,
                    color: Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
                ),
              ),
            ),
            SizedBox(
              height: 42,
              child: ListView.separated(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                scrollDirection: Axis.horizontal,
                itemCount: _recentProducts.length,
                separatorBuilder: (_, __) => const SizedBox(width: 8),
                itemBuilder: (context, index) {
                  final p = _recentProducts[index];
                  return ActionChip(
                    avatar: const Icon(Icons.flash_on, size: 16, color: Colors.deepOrange),
                    label: Text(p.name),
                    onPressed: () => _promptQuantityAndPrice(p),
                  );
                },
              ),
            ),
            const Divider(height: 16),
          ],

          // Add Product button
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            child: OutlinedButton.icon(
              style: OutlinedButton.styleFrom(
                minimumSize: const Size.fromHeight(46),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              ),
              onPressed: _showProductPicker,
              icon: const Icon(Icons.search),
              label: const Text('Search & Select Product / ዕቃ ምረጥ'),
            ),
          ),

          // Cart items
          Expanded(
            child: _cart.isEmpty
                ? Center(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.shopping_cart_outlined, size: 64, color: Theme.of(context).colorScheme.outlineVariant),
                        const SizedBox(height: 12),
                        Text(
                          'Cart is empty / ቅርጫቱ ባዶ ነው',
                          style: TextStyle(fontSize: 15, color: Theme.of(context).colorScheme.onSurfaceVariant),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          'Pick an item above to add to this sale.',
                          style: TextStyle(fontSize: 12, color: Theme.of(context).colorScheme.onSurfaceVariant),
                        ),
                      ],
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.all(16),
                    itemCount: _cart.length,
                    separatorBuilder: (_, __) => const Divider(height: 12),
                    itemBuilder: (context, index) {
                      final item = _cart[index];
                      final qtyDisplay = QuantityFormatter.formatDisplay(item.qtyMilli);

                      return ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(item.product.name, style: const TextStyle(fontWeight: FontWeight.bold)),
                        subtitle: Text(
                          '$qtyDisplay ${item.product.unit} × ${MoneyFormatter.formatCents(item.unitPriceCents)}',
                        ),
                        trailing: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(
                              MoneyFormatter.formatCents(item.totalCents),
                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                            ),
                            IconButton(
                              icon: const Icon(Icons.close, size: 18, color: Colors.red),
                              onPressed: () => setState(() => _cart.removeAt(index)),
                            ),
                          ],
                        ),
                      );
                    },
                  ),
          ),

          // Bottom Finish Sale Bar
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Theme.of(context).colorScheme.surface,
              border: Border(top: BorderSide(color: Theme.of(context).colorScheme.outline, width: 0.8)),
              boxShadow: Theme.of(context).brightness == Brightness.dark
                  ? null
                  : [
                      BoxShadow(color: Colors.black.withValues(alpha: 0.08), blurRadius: 10, offset: const Offset(0, -2)),
                    ],
            ),
            child: SafeArea(
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Total / ጠቅላላ ድምር:',
                          style: TextStyle(fontSize: 12, color: Theme.of(context).colorScheme.onSurfaceVariant),
                        ),
                        Text(
                          MoneyFormatter.formatCents(_cartTotalCents),
                          style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold),
                        ),
                      ],
                    ),
                  ),
                  FilledButton.icon(
                    style: FilledButton.styleFrom(
                      backgroundColor: Colors.deepOrange,
                      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    ),
                    onPressed: (_cart.isEmpty || _isLoading) ? null : _finishSale,
                    icon: _isLoading
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                          )
                        : const Icon(Icons.check_circle_outline),
                    label: const Text('Finish Sale / ሽጥ', style: TextStyle(fontWeight: FontWeight.bold)),
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

class _ProductPickerSheet extends StatefulWidget {
  final List<ProductWithStock> products;

  const _ProductPickerSheet({required this.products});

  @override
  State<_ProductPickerSheet> createState() => _ProductPickerSheetState();
}

class _ProductPickerSheetState extends State<_ProductPickerSheet> {
  String _filter = '';
  Timer? _debounceTimer;

  @override
  void dispose() {
    _debounceTimer?.cancel();
    super.dispose();
  }

  void _onSearchChanged(String val) {
    _debounceTimer?.cancel();
    _debounceTimer = Timer(const Duration(milliseconds: 250), () {
      if (mounted) {
        setState(() => _filter = val.trim());
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final filtered = widget.products.where((p) {
      if (_filter.isEmpty) return true;
      final q = _filter.toLowerCase();
      return p.product.name.toLowerCase().contains(q) ||
          (p.product.sku?.toLowerCase().contains(q) ?? false);
    }).toList();

    return DraggableScrollableSheet(
      initialChildSize: 0.8,
      minChildSize: 0.5,
      maxChildSize: 0.95,
      expand: false,
      builder: (ctx, scrollCtrl) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Column(
          children: [
            Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.outline,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              autofocus: true,
              decoration: InputDecoration(
                hintText: 'Search product...',
                prefixIcon: const Icon(Icons.search),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                contentPadding: const EdgeInsets.symmetric(vertical: 0, horizontal: 12),
              ),
              onChanged: _onSearchChanged,
            ),
            const SizedBox(height: 10),
            Expanded(
              child: ListView.separated(
                controller: scrollCtrl,
                itemCount: filtered.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (ctx, index) {
                  final item = filtered[index];
                  final p = item.product;
                  String subtitle = '${MoneyFormatter.formatCents(p.sellPriceCents)} • Stock: ${QuantityFormatter.formatDisplay(item.stockMilli)} ${p.unit}';
                  if (item.isService) {
                    subtitle = '${MoneyFormatter.formatCents(p.sellPriceCents)} • 🛠 Service${p.askPrice ? ' (Ask Price)' : ''}';
                  } else if (item.isConsumable) {
                    subtitle = '${MoneyFormatter.formatCents(p.sellPriceCents)} • 🧪 Consumable';
                  }

                  return ListTile(
                    title: Text(p.name, style: const TextStyle(fontWeight: FontWeight.w600)),
                    subtitle: Text(subtitle),
                    trailing: const Icon(Icons.add_circle_outline, color: Colors.deepOrange),
                    onTap: () => Navigator.of(ctx).pop(p),
                  );
                },
              ),
            ),
          ],
        ),
      ),
    );
  }
}
