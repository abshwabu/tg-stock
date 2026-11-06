import 'dart:async';

import 'package:drift/drift.dart' show Value;
import 'package:flutter/material.dart';

import '../../database/app_database.dart';
import '../../repositories/ledger_repository.dart';
import '../../services/demo_data_service.dart';
import '../../utils/money_formatter.dart';
import '../../utils/quantity_formatter.dart';
import '../widgets/empty_state_widget.dart';
import 'adjust_screen.dart';
import 'stock_in_screen.dart';
import 'transfer_screen.dart';

class ProductsScreen extends StatefulWidget {
  final LedgerRepository repository;
  final VoidCallback? onMutation;

  const ProductsScreen({
    super.key,
    required this.repository,
    this.onMutation,
  });

  @override
  State<ProductsScreen> createState() => _ProductsScreenState();
}

class _ProductsScreenState extends State<ProductsScreen> {
  late final DemoDataService _demoService;
  final _searchController = TextEditingController();
  String _searchQuery = '';
  String _selectedKindFilter = 'all';
  bool _isDemoLoaded = false;
  Timer? _searchDebounceTimer;

  @override
  void initState() {
    super.initState();
    _demoService = DemoDataService(repository: widget.repository);
    _checkDemoStatus();
  }

  Future<void> _checkDemoStatus() async {
    final loaded = await _demoService.isDemoProductsLoaded();
    if (mounted) setState(() => _isDemoLoaded = loaded);
  }

  Future<void> _loadDemoProducts() async {
    await _demoService.loadDemoProducts();
    await _checkDemoStatus();
    widget.onMutation?.call();
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Demo products loaded! / የሙከራ ዕቃዎች ተጭነዋል')),
      );
    }
  }

  Future<void> _removeDemoProducts() async {
    await _demoService.removeDemoProducts();
    await _checkDemoStatus();
    widget.onMutation?.call();
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Demo products removed. / የሙከራ ዕቃዎች ተሰርዘዋል')),
      );
    }
  }

  void _onSearchChanged(String val) {
    _searchDebounceTimer?.cancel();
    _searchDebounceTimer = Timer(const Duration(milliseconds: 250), () {
      if (mounted) {
        setState(() => _searchQuery = val.trim());
      }
    });
  }

  @override
  void dispose() {
    _searchDebounceTimer?.cancel();
    _searchController.dispose();
    super.dispose();
  }

  Future<({Product materialProduct, int qtyMilli})?> _showAddMaterialDialog(
    BuildContext parentCtx,
    String? excludeProductId,
  ) async {
    final stockables = await widget.repository.listProducts(kindFilter: 'stockable');
    final available = stockables
        .map((pws) => pws.product)
        .where((p) => p.id != excludeProductId)
        .toList();

    if (!mounted || !parentCtx.mounted) return null;

    if (available.isEmpty) {
      ScaffoldMessenger.of(parentCtx).showSnackBar(
        const SnackBar(content: Text('No stockable products available to use as raw materials.')),
      );
      return null;
    }

    Product selectedProduct = available.first;
    final qtyCtrl = TextEditingController(text: '1');

    return showDialog<({Product materialProduct, int qtyMilli})>(
      context: parentCtx,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: const Text('Add Raw Material / ጥሬ ዕቃ ጨምር'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Select Material / ጥሬ ዕቃ ይምረጡ:', style: TextStyle(fontSize: 12, color: Colors.grey)),
              const SizedBox(height: 6),
              DropdownButtonFormField<Product>(
                initialValue: selectedProduct,
                isExpanded: true,
                items: available.map((p) {
                  return DropdownMenuItem(
                    value: p,
                    child: Text('${p.name} (${p.unit}) - Cost: ${MoneyFormatter.formatCents(p.costPriceCents)}'),
                  );
                }).toList(),
                onChanged: (p) {
                  if (p != null) {
                    setDialogState(() => selectedProduct = p);
                  }
                },
              ),
              const SizedBox(height: 14),
              TextField(
                controller: qtyCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(
                  labelText: 'Qty per 1 unit sold (${selectedProduct.unit}) *',
                  hintText: 'e.g. 0.02',
                ),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(null),
              child: const Text('Cancel / ሰርዝ'),
            ),
            FilledButton(
              onPressed: () {
                final qtyMilli = QuantityFormatter.parseQty(qtyCtrl.text);
                if (qtyMilli <= 0) return;
                Navigator.of(ctx).pop((materialProduct: selectedProduct, qtyMilli: qtyMilli));
              },
              child: const Text('Add / ጨምር'),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _showAddOrEditProductDialog({Product? existing}) async {
    final isEdit = existing != null;
    String selectedKind = existing?.kind ?? 'stockable';
    bool askPrice = existing?.askPrice ?? false;

    final nameCtrl = TextEditingController(text: existing?.name ?? '');
    final skuCtrl = TextEditingController(text: existing?.sku ?? '');
    final unitCtrl = TextEditingController(
      text: existing?.unit ?? (selectedKind == 'service' ? 'service' : 'pcs'),
    );
    final sellPriceCtrl = TextEditingController(
      text: existing != null ? (existing.sellPriceCents / 100).toStringAsFixed(2) : '',
    );
    final costPriceCtrl = TextEditingController(
      text: existing != null ? (existing.costPriceCents / 100).toStringAsFixed(2) : '',
    );
    final lowStockCtrl = TextEditingController(
      text: existing?.lowStockAtMilli != null
          ? QuantityFormatter.formatDisplay(existing!.lowStockAtMilli!)
          : '5',
    );
    final openingStockCtrl = TextEditingController(text: '0');

    // Load existing materials if consumable
    List<({Product materialProduct, int qtyMilli})> materials = [];
    if (isEdit && selectedKind == 'consumable') {
      final existingMats = await widget.repository.getProductMaterials(existing.id);
      for (final em in existingMats) {
        final p = await widget.repository.getProduct(em.materialProductId);
        if (p != null) {
          materials.add((materialProduct: p, qtyMilli: em.qtyMilli));
        }
      }
    }

    if (!mounted) return;

    final title = isEdit ? 'Edit Item / ዕቃ አሻሽል' : 'Add Item / አዲስ ዕቃ መዝግብ';

    final result = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) {
          final isProduct = selectedKind == 'stockable';
          final isService = selectedKind == 'service';
          final isConsumable = selectedKind == 'consumable';

          // Calculate estimated material cost and margin for consumable
          int estimatedMatCostCents = 0;
          for (final m in materials) {
            estimatedMatCostCents += ((m.qtyMilli * m.materialProduct.costPriceCents) + 500) ~/ 1000;
          }
          final sellPriceCents = MoneyFormatter.parseCents(sellPriceCtrl.text);
          final marginCents = sellPriceCents - estimatedMatCostCents;
          final marginPct = sellPriceCents > 0
              ? ((marginCents * 100.0) / sellPriceCents).toStringAsFixed(1)
              : '0.0';

          return AlertDialog(
            title: Text(title),
            content: SizedBox(
              width: double.maxFinite,
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Text(
                      'What are you adding? / ምን ዓይነት ዕቃ ነው የሚመዘግቡት?',
                      style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Colors.grey),
                    ),
                    const SizedBox(height: 8),
                    SegmentedButton<String>(
                      segments: const [
                        ButtonSegment(value: 'stockable', label: Text('📦 Product')),
                        ButtonSegment(value: 'service', label: Text('🛠 Service')),
                        ButtonSegment(value: 'consumable', label: Text('🧪 Consumable')),
                      ],
                      selected: {selectedKind},
                      onSelectionChanged: (newSelection) async {
                        final newKind = newSelection.first;
                        if (isEdit && newKind != existing.kind) {
                          try {
                            await widget.repository.validateProductKindChange(existing, newKind);
                          } catch (e) {
                            if (ctx.mounted) {
                              ScaffoldMessenger.of(ctx).showSnackBar(
                                SnackBar(
                                  backgroundColor: Colors.red,
                                  content: Text(e.toString().replaceAll('StateError: ', '')),
                                ),
                              );
                            }
                            return;
                          }
                        }

                        setDialogState(() {
                          selectedKind = newKind;
                          if (selectedKind == 'service' && unitCtrl.text == 'pcs') {
                            unitCtrl.text = 'service';
                          } else if (selectedKind != 'service' && unitCtrl.text == 'service') {
                            unitCtrl.text = 'pcs';
                          }
                        });
                      },
                    ),
                    const SizedBox(height: 16),
                    TextField(
                      controller: nameCtrl,
                      autofocus: !isEdit,
                      decoration: const InputDecoration(labelText: 'Name / ስም *'),
                    ),
                    if (isProduct) ...[
                      const SizedBox(height: 10),
                      TextField(
                        controller: skuCtrl,
                        decoration: const InputDecoration(labelText: 'SKU / Barcode / ባርኮድ (Optional)'),
                      ),
                    ],
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: sellPriceCtrl,
                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                            decoration: const InputDecoration(labelText: 'Sell Price ETB *'),
                            onChanged: (_) => setDialogState(() {}),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: TextField(
                            controller: unitCtrl,
                            decoration: const InputDecoration(labelText: 'Unit / መለኪያ *'),
                          ),
                        ),
                      ],
                    ),
                    if (isProduct || isService) ...[
                      const SizedBox(height: 10),
                      TextField(
                        controller: costPriceCtrl,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: InputDecoration(
                          labelText: isService
                              ? 'Cost Price ETB / የወጪ ዋጋ (Optional)'
                              : 'Cost Price ETB / መግዣ ዋጋ',
                        ),
                      ),
                    ],
                    if (isService) ...[
                      const SizedBox(height: 10),
                      SwitchListTile(
                        contentPadding: EdgeInsets.zero,
                        title: const Text('Ask price at every sale', style: TextStyle(fontSize: 13.5)),
                        subtitle: const Text('Prompt for price when adding to cart', style: TextStyle(fontSize: 11)),
                        value: askPrice,
                        onChanged: (val) => setDialogState(() => askPrice = val),
                      ),
                    ],
                    if (isProduct) ...[
                      const SizedBox(height: 10),
                      TextField(
                        controller: lowStockCtrl,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: const InputDecoration(labelText: 'Low Stock Alert Limit / አነስተኛ ስቶክ ገደብ'),
                      ),
                      if (!isEdit) ...[
                        const SizedBox(height: 10),
                        TextField(
                          controller: openingStockCtrl,
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          decoration: const InputDecoration(labelText: 'Opening Stock / የመጀመሪያ ስቶክ'),
                        ),
                      ],
                    ],
                    if (isConsumable) ...[
                      const SizedBox(height: 16),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text(
                            'Materials used / ጥሬ ዕቃዎች:',
                            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                          ),
                          TextButton.icon(
                            icon: const Icon(Icons.add, size: 16),
                            label: const Text('Add Material'),
                            onPressed: () async {
                              final picked = await _showAddMaterialDialog(ctx, existing?.id);
                              if (picked != null) {
                                setDialogState(() {
                                  // replace or add
                                  materials.removeWhere((m) => m.materialProduct.id == picked.materialProduct.id);
                                  materials.add(picked);
                                });
                              }
                            },
                          ),
                        ],
                      ),
                      if (materials.isEmpty)
                        Container(
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(
                            color: Colors.grey.shade100,
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: const Text(
                            'No raw materials selected. (Optional recipe)',
                            style: TextStyle(fontSize: 12, color: Colors.grey),
                          ),
                        )
                      else ...[
                        ...materials.map((m) {
                          final costCents = ((m.qtyMilli * m.materialProduct.costPriceCents) + 500) ~/ 1000;
                          return Container(
                            margin: const EdgeInsets.only(bottom: 6),
                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                            decoration: BoxDecoration(
                              color: Colors.purple.shade50,
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(color: Colors.purple.shade200),
                            ),
                            child: Row(
                              children: [
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        m.materialProduct.name,
                                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                      ),
                                      Text(
                                        '${QuantityFormatter.formatDisplay(m.qtyMilli)} ${m.materialProduct.unit} '
                                        '(${MoneyFormatter.formatCents(costCents)})',
                                        style: TextStyle(fontSize: 11, color: Colors.grey.shade800),
                                      ),
                                    ],
                                  ),
                                ),
                                IconButton(
                                  icon: const Icon(Icons.close, size: 18, color: Colors.red),
                                  onPressed: () {
                                    setDialogState(() {
                                      materials.removeWhere((item) => item.materialProduct.id == m.materialProduct.id);
                                    });
                                  },
                                ),
                              ],
                            ),
                          );
                        }),
                        const SizedBox(height: 8),
                        Container(
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(
                            color: Colors.grey.shade100,
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Text('Est. Material Cost:', style: TextStyle(fontSize: 11, color: Colors.grey)),
                                  Text(
                                    MoneyFormatter.formatCents(estimatedMatCostCents),
                                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                  ),
                                ],
                              ),
                              Column(
                                crossAxisAlignment: CrossAxisAlignment.end,
                                children: [
                                  const Text('Margin / Unit:', style: TextStyle(fontSize: 11, color: Colors.grey)),
                                  Text(
                                    '${MoneyFormatter.formatCents(marginCents)} ($marginPct%)',
                                    style: TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 13,
                                      color: marginCents >= 0 ? Colors.green.shade800 : Colors.red,
                                    ),
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      ],
                    ],
                  ],
                ),
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancel / ሰርዝ')),
              FilledButton(
                onPressed: () {
                  if (nameCtrl.text.trim().isEmpty) return;
                  Navigator.of(ctx).pop(true);
                },
                child: Text(isEdit ? 'Update' : 'Save'),
              ),
            ],
          );
        },
      ),
    );

    if (result == true) {
      final name = nameCtrl.text.trim();
      final sku = skuCtrl.text.trim().isNotEmpty ? skuCtrl.text.trim() : null;
      final unit = unitCtrl.text.trim().isNotEmpty ? unitCtrl.text.trim() : 'pcs';
      final sellPriceCents = MoneyFormatter.parseCents(sellPriceCtrl.text);
      final costPriceCents = MoneyFormatter.parseCents(costPriceCtrl.text);
      final lowStockMilli = QuantityFormatter.parseQty(lowStockCtrl.text);

      final materialPayload = selectedKind == 'consumable'
          ? materials.map((m) => {
              'product_id': m.materialProduct.id,
              'qty_milli': m.qtyMilli,
            }).toList()
          : null;

      if (isEdit) {
        final updated = existing.copyWith(
          name: name,
          sku: sku != null ? Value(sku) : const Value.absent(),
          unit: unit,
          sellPriceCents: sellPriceCents,
          costPriceCents: costPriceCents,
          lowStockAtMilli: Value(selectedKind == 'stockable' && lowStockMilli > 0 ? lowStockMilli : null),
          kind: selectedKind,
          askPrice: selectedKind == 'service' ? askPrice : false,
        );
        await widget.repository.updateProduct(updated, materials: materialPayload);
      } else {
        final openingMilli = QuantityFormatter.parseQty(openingStockCtrl.text);
        await widget.repository.createProduct(
          name: name,
          sku: sku,
          unit: unit,
          sellPriceCents: sellPriceCents,
          costPriceCents: costPriceCents,
          lowStockAtMilli: selectedKind == 'stockable' && lowStockMilli > 0 ? lowStockMilli : null,
          openingStockMilli: selectedKind == 'stockable' && openingMilli > 0 ? openingMilli : null,
          kind: selectedKind,
          askPrice: selectedKind == 'service' ? askPrice : false,
          materials: materialPayload,
        );
      }

      widget.onMutation?.call();
      setState(() {});
    }
  }

  Future<void> _confirmDeleteProduct(Product product) async {
    final isUsed = await widget.repository.isUsedAsMaterial(product.id);
    if (isUsed) {
      if (mounted) {
        showDialog(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('Cannot Delete / መሰረዝ አይቻልም'),
            content: Text(
              'Cannot delete "${product.name}" because it is used as a raw material in one or more recipes.\n\n'
              'Remove it from recipes before deleting.',
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

    if (!mounted) return;

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete Product / ዕቃ ሰርዝ'),
        content: Text('Are you sure you want to remove "${product.name}" from your catalog?'),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancel')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );

    if (confirm == true) {
      await widget.repository.deleteProduct(product.id);
      widget.onMutation?.call();
      setState(() {});
    }
  }

  void _showProductOptions(ProductWithStock item) {
    final p = item.product;
    final isStockable = item.isStockable;
    final stockFormatted = QuantityFormatter.formatDisplay(item.stockMilli);

    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    p.name,
                    style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                  ),
                ),
                Text(
                  '${MoneyFormatter.formatCents(p.sellPriceCents)} / ${p.unit}',
                  style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                ),
              ],
            ),
            const SizedBox(height: 6),
            if (isStockable)
              Text(
                'Stock: $stockFormatted ${p.unit} • Cost: ${MoneyFormatter.formatCents(p.costPriceCents)}',
                style: TextStyle(color: Colors.grey.shade700, fontSize: 13),
              )
            else if (item.isService)
              Text(
                '🛠 Service • No stock tracking',
                style: TextStyle(color: Colors.blue.shade700, fontSize: 13, fontWeight: FontWeight.w500),
              )
            else
              Text(
                '🧪 Consumable • Stock tracked via raw materials',
                style: TextStyle(color: Colors.purple.shade700, fontSize: 13, fontWeight: FontWeight.w500),
              ),
            if (isStockable && item.stockByLocation.isNotEmpty) ...[
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 4,
                children: [
                  ...item.stockByLocation.entries.map((e) {
                    return Chip(
                      label: Text('${e.key}: ${QuantityFormatter.formatDisplay(e.value)} ${p.unit}'),
                      visualDensity: VisualDensity.compact,
                    );
                  }),
                  if (item.inTransitMilli > 0)
                    Chip(
                      avatar: const Icon(Icons.local_shipping, size: 16, color: Colors.amber),
                      label: Text('In transit: ${QuantityFormatter.formatDisplay(item.inTransitMilli)} ${p.unit}'),
                      visualDensity: VisualDensity.compact,
                    ),
                ],
              ),
            ],
            const Divider(height: 20),
            if (isStockable) ...[
              ListTile(
                dense: true,
                leading: const Icon(Icons.add_shopping_cart, color: Colors.green),
                title: const Text('Stock In / ገቢ መዝግብ'),
                onTap: () {
                  Navigator.of(ctx).pop();
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (c) => StockInScreen(
                        repository: widget.repository,
                        onMutation: widget.onMutation,
                      ),
                    ),
                  );
                },
              ),
              ListTile(
                dense: true,
                leading: const Icon(Icons.tune, color: Colors.orange),
                title: const Text('Adjust Stock / ስቶክ ማስተካከል'),
                onTap: () {
                  Navigator.of(ctx).pop();
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (c) => AdjustScreen(
                        repository: widget.repository,
                        onMutation: widget.onMutation,
                      ),
                    ),
                  );
                },
              ),
              ListTile(
                dense: true,
                leading: const Icon(Icons.swap_horiz, color: Colors.teal),
                title: const Text('Transfer / ዕቃ ማስተላለፍ'),
                onTap: () {
                  Navigator.of(ctx).pop();
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (c) => TransferScreen(
                        repository: widget.repository,
                        onMutation: widget.onMutation,
                      ),
                    ),
                  );
                },
              ),
              const Divider(height: 12),
            ],
            ListTile(
              dense: true,
              leading: const Icon(Icons.edit_outlined, color: Colors.blue),
              title: const Text('Edit Product Details / መረጃ አሻሽል'),
              onTap: () {
                Navigator.of(ctx).pop();
                _showAddOrEditProductDialog(existing: p);
              },
            ),
            ListTile(
              dense: true,
              leading: const Icon(Icons.delete_outline, color: Colors.red),
              title: const Text('Delete Product / ዕቃ ሰርዝ'),
              onTap: () {
                Navigator.of(ctx).pop();
                _confirmDeleteProduct(p);
              },
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Products / የዕቃዎች ዝርዝር'),
      ),
      body: Column(
        children: [
          // Search Bar
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
            child: TextField(
              controller: _searchController,
              decoration: InputDecoration(
                hintText: 'Search products (e.g. Sugar, ጤፍ)...',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: _searchQuery.isNotEmpty
                    ? IconButton(
                        icon: const Icon(Icons.clear),
                        onPressed: () {
                          _searchController.clear();
                          setState(() => _searchQuery = '');
                        },
                      )
                    : null,
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                contentPadding: const EdgeInsets.symmetric(vertical: 0, horizontal: 12),
              ),
              onChanged: _onSearchChanged,
            ),
          ),

          // Demo Data Banner
          if (_isDemoLoaded)
            Container(
              margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: const Color(0xFFFEF3C7),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: const Color(0xFFF59E0B)),
              ),
              child: Row(
                children: [
                  const Icon(Icons.info_outline, size: 20, color: Color(0xFFB45309)),
                  const SizedBox(width: 8),
                  const Expanded(
                    child: Text(
                      'የሙከራ ዕቃዎች እየታዩ ነው (Demo Products)',
                      style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold, color: Color(0xFF92400E)),
                    ),
                  ),
                  TextButton.icon(
                    style: TextButton.styleFrom(
                      foregroundColor: Colors.red.shade900,
                      visualDensity: VisualDensity.compact,
                    ),
                    onPressed: _removeDemoProducts,
                    icon: const Icon(Icons.delete_outline, size: 16),
                    label: const Text('ሰርዝ / Remove'),
                  ),
                ],
              ),
            ),

          // Filter Chips
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            child: Row(
              children: [
                ChoiceChip(
                  label: const Text('All / ሁሉም'),
                  selected: _selectedKindFilter == 'all',
                  onSelected: (sel) => setState(() => _selectedKindFilter = 'all'),
                ),
                const SizedBox(width: 8),
                ChoiceChip(
                  label: const Text('📦 Products / ዕቃዎች'),
                  selected: _selectedKindFilter == 'stockable',
                  onSelected: (sel) => setState(() => _selectedKindFilter = 'stockable'),
                ),
                const SizedBox(width: 8),
                ChoiceChip(
                  label: const Text('🛠 Services / አገልግሎቶች'),
                  selected: _selectedKindFilter == 'service',
                  onSelected: (sel) => setState(() => _selectedKindFilter = 'service'),
                ),
                const SizedBox(width: 8),
                ChoiceChip(
                  label: const Text('🧪 Consumables / የተዘጋጁ'),
                  selected: _selectedKindFilter == 'consumable',
                  onSelected: (sel) => setState(() => _selectedKindFilter = 'consumable'),
                ),
                const SizedBox(width: 8),
                ChoiceChip(
                  label: const Text('🌾 Materials / ጥሬ ዕቃዎች'),
                  selected: _selectedKindFilter == 'material',
                  onSelected: (sel) => setState(() => _selectedKindFilter = 'material'),
                ),
              ],
            ),
          ),
          const SizedBox(height: 4),

          Expanded(
            child: StreamBuilder<List<ProductWithStock>>(
              stream: widget.repository.watchProducts(
                query: _searchQuery,
                kindFilter: _selectedKindFilter,
              ),
              builder: (context, snapshot) {
                if (!snapshot.hasData) {
                  return const Center(child: CircularProgressIndicator());
                }

                final products = snapshot.data!;
                if (products.isEmpty) {
                  if (_searchQuery.isNotEmpty) {
                    return Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          const Icon(Icons.search_off_rounded, size: 48, color: Colors.grey),
                          const SizedBox(height: 12),
                          Text(
                            'No matching products for "$_searchQuery"',
                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                          ),
                          const SizedBox(height: 8),
                          OutlinedButton(
                            onPressed: () {
                              _searchController.clear();
                              setState(() => _searchQuery = '');
                            },
                            child: const Text('Clear Search / ፍለጋውን አጽዳ'),
                          ),
                        ],
                      ),
                    );
                  }

                  return EmptyStateWidget(
                    icon: Icons.inventory_2_outlined,
                    title: 'ምንም ዕቃዎች አልተመዘገቡም\nNo Products Yet',
                    description:
                        'የመጀመሪያዎን ዕቃ መዝግበው ሽያጭ ይጀምሩ ወይም ለሙከራ ዝግጁ የሆኑ የኢትዮጵያ ሱቅ ዕቃዎችን ይጫኑ።\n'
                        'Add your first item or load realistic sample products to try out the app immediately.',
                    primaryButtonLabel: 'አዲስ ዕቃ መዝግብ / Add Product',
                    primaryButtonIcon: Icons.add_circle_outline,
                    onPrimaryPressed: () => _showAddOrEditProductDialog(),
                    secondaryButtonLabel: 'የሙከራ ዕቃዎችን ጫን / Load Demo Items',
                    secondaryButtonIcon: Icons.auto_awesome,
                    onSecondaryPressed: _loadDemoProducts,
                  );
                }

                return ListView.separated(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  itemCount: products.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 6),
                  itemBuilder: (context, index) {
                    final item = products[index];
                    final p = item.product;
                    final stock = item.stockMilli;

                    Color badgeBg;
                    Color badgeFg;
                    String badgeText;
                    String subtitleText;

                    if (item.isService) {
                      badgeBg = Colors.blue.shade50;
                      badgeFg = Colors.blue.shade900;
                      badgeText = '🛠 Service';
                      subtitleText = 'Price: ${p.askPrice ? 'Ask Price' : MoneyFormatter.formatCents(p.sellPriceCents)} • Service';
                    } else if (item.isConsumable) {
                      badgeBg = Colors.purple.shade50;
                      badgeFg = Colors.purple.shade900;
                      badgeText = '🧪 Consumable';
                      subtitleText = 'Price: ${MoneyFormatter.formatCents(p.sellPriceCents)} • Consumable';
                    } else {
                      subtitleText = 'Price: ${MoneyFormatter.formatCents(p.sellPriceCents)} • Stock: ${QuantityFormatter.formatDisplay(stock)} ${p.unit}';
                      if (item.isNegative) {
                        badgeBg = Colors.amber.shade100;
                        badgeFg = Colors.amber.shade900;
                        badgeText = '⚠️ Negative (${QuantityFormatter.formatDisplay(stock)})';
                      } else if (item.isLow) {
                        badgeBg = Colors.red.shade50;
                        badgeFg = Colors.red.shade900;
                        badgeText = '🔴 Low (${QuantityFormatter.formatDisplay(stock)})';
                      } else {
                        badgeBg = Colors.green.shade50;
                        badgeFg = Colors.green.shade900;
                        badgeText = '🟢 In Stock';
                      }
                    }

                    return Card(
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      child: InkWell(
                        borderRadius: BorderRadius.circular(10),
                        onTap: () => _showProductOptions(item),
                        child: Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      p.name,
                                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                                    ),
                                    const SizedBox(height: 4),
                                    Text(
                                      subtitleText,
                                      style: TextStyle(fontSize: 13, color: Colors.grey.shade800),
                                    ),
                                    if (item.isStockable && item.stockByLocation.isNotEmpty) ...[
                                      const SizedBox(height: 6),
                                      Wrap(
                                        spacing: 6,
                                        runSpacing: 4,
                                        children: [
                                          ...item.stockByLocation.entries.map((e) {
                                            return Container(
                                              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                              decoration: BoxDecoration(
                                                color: Colors.grey.shade100,
                                                borderRadius: BorderRadius.circular(4),
                                                border: Border.all(color: Colors.grey.shade300, width: 0.8),
                                              ),
                                              child: Text(
                                                '${e.key}: ${QuantityFormatter.formatDisplay(e.value)}',
                                                style: TextStyle(fontSize: 11, color: Colors.grey.shade800, fontWeight: FontWeight.w500),
                                              ),
                                            );
                                          }),
                                          if (item.inTransitMilli > 0)
                                            Container(
                                              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                              decoration: BoxDecoration(
                                                color: Colors.amber.shade50,
                                                borderRadius: BorderRadius.circular(4),
                                                border: Border.all(color: Colors.amber.shade300, width: 0.8),
                                              ),
                                              child: Text(
                                                '🚚 In transit: ${QuantityFormatter.formatDisplay(item.inTransitMilli)}',
                                                style: TextStyle(fontSize: 11, color: Colors.amber.shade900, fontWeight: FontWeight.bold),
                                              ),
                                            ),
                                        ],
                                      ),
                                    ],
                                  ],
                                ),
                              ),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                decoration: BoxDecoration(
                                  color: badgeBg,
                                  borderRadius: BorderRadius.circular(6),
                                ),
                                child: Text(
                                  badgeText,
                                  style: TextStyle(
                                    color: badgeFg,
                                    fontSize: 11,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    );
                  },
                );
              },
            ),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _showAddOrEditProductDialog(),
        icon: const Icon(Icons.add),
        label: const Text('Add Product'),
      ),
    );
  }
}
