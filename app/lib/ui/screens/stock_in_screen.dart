import 'package:flutter/material.dart';

import '../../database/app_database.dart';
import '../../repositories/ledger_repository.dart';
import '../../utils/money_formatter.dart';
import '../../utils/quantity_formatter.dart';
import '../widgets/location_selector_chip.dart';

class StockInScreen extends StatefulWidget {
  final LedgerRepository repository;
  final VoidCallback? onMutation;

  const StockInScreen({
    super.key,
    required this.repository,
    this.onMutation,
  });

  @override
  State<StockInScreen> createState() => _StockInScreenState();
}

class _StockInScreenState extends State<StockInScreen> {
  Product? _selectedProduct;
  Location? _activeLocation;
  final _qtyController = TextEditingController();
  final _costController = TextEditingController();
  final _noteController = TextEditingController();

  int _currentStock = 0;
  bool _isLoading = false;

  @override
  void initState() {
    super.initState();
    _loadLocation();
  }

  Future<void> _loadLocation() async {
    final active = await widget.repository.getActiveLocation();
    if (mounted) setState(() => _activeLocation = active);
  }

  @override
  void dispose() {
    _qtyController.dispose();
    _costController.dispose();
    _noteController.dispose();
    super.dispose();
  }

  Future<void> _onProductSelected(Product p) async {
    final stock = await widget.repository.stockOf(p.id, locationId: _activeLocation?.id);
    setState(() {
      _selectedProduct = p;
      _currentStock = stock;
      _costController.text = (p.costPriceCents / 100).toStringAsFixed(2);
      _qtyController.text = '10';
    });
  }

  Future<void> _pickProduct() async {
    final products = await widget.repository.listProducts(kindFilter: 'stockable');
    if (!mounted) return;

    final p = await showModalBottomSheet<Product>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => _ProductSelectionSheet(products: products),
    );

    if (p != null) {
      await _onProductSelected(p);
    }
  }

  Future<void> _confirmStockIn() async {
    if (_selectedProduct == null) return;
    final qtyInput = QuantityFormatter.parseQty(_qtyController.text);
    if (qtyInput <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please enter a valid positive quantity.')),
      );
      return;
    }

    setState(() => _isLoading = true);
    try {
      final newCostCents = MoneyFormatter.parseCents(_costController.text);
      final note = _noteController.text.trim();

      await widget.repository.recordStockIn(
        productId: _selectedProduct!.id,
        qtyMilli: qtyInput,
        unitPriceCents: newCostCents > 0 ? newCostCents : _selectedProduct!.costPriceCents,
        note: note.isNotEmpty ? note : 'Stock intake',
        locationId: _activeLocation?.id,
      );

      // If cost price changed, update product
      if (newCostCents > 0 && newCostCents != _selectedProduct!.costPriceCents) {
        await widget.repository.updateProduct(
          _selectedProduct!.copyWith(costPriceCents: newCostCents),
        );
      }

      widget.onMutation?.call();

      final newStock = await widget.repository.stockOf(_selectedProduct!.id, locationId: _activeLocation?.id);
      final addedStr = QuantityFormatter.formatDisplay(qtyInput);
      final newStockStr = QuantityFormatter.formatDisplay(newStock);

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            backgroundColor: Colors.green,
            content: Text(
              '✅ Added +$addedStr ${_selectedProduct!.unit} to ${_selectedProduct!.name}. New stock: $newStockStr',
            ),
          ),
        );
        Navigator.of(context).pop();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Stock Intake / ገቢ ዕቃ መዝግብ'),
        actions: [
          LocationSelectorChip(
            repository: widget.repository,
            onLocationChanged: (loc) {
              setState(() => _activeLocation = loc);
              if (_selectedProduct != null) {
                _onProductSelected(_selectedProduct!);
              }
            },
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Product Selector Card
            Card(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              child: InkWell(
                borderRadius: BorderRadius.circular(12),
                onTap: _pickProduct,
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Row(
                    children: [
                      const Icon(Icons.inventory_2_outlined, size: 28, color: Color(0xFF0F766E)),
                      const SizedBox(width: 14),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              _selectedProduct?.name ?? 'Select Product / ዕቃ ይምረጡ',
                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                            ),
                            if (_selectedProduct != null) ...[
                              const SizedBox(height: 4),
                              Text(
                                'Current Stock: ${QuantityFormatter.formatDisplay(_currentStock)} ${_selectedProduct!.unit}',
                                style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant, fontSize: 13),
                              ),
                            ] else
                              Text('Tap to choose product from catalog', style: TextStyle(fontSize: 12, color: Theme.of(context).colorScheme.onSurfaceVariant)),
                          ],
                        ),
                      ),
                      Icon(Icons.arrow_forward_ios, size: 16, color: Theme.of(context).colorScheme.onSurfaceVariant),
                    ],
                  ),
                ),
              ),
            ),
            const SizedBox(height: 20),

            if (_selectedProduct != null) ...[
              // Quick Quantity Buttons
              Text('Quick Quantity / ፈጣን መጠን:', style: TextStyle(fontSize: 12, color: Theme.of(context).colorScheme.onSurfaceVariant)),
              const SizedBox(height: 8),
              Row(
                children: [5, 10, 25, 50, 100].map((n) {
                  return Expanded(
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 2),
                      child: OutlinedButton(
                        style: OutlinedButton.styleFrom(
                          padding: const EdgeInsets.symmetric(vertical: 10),
                        ),
                        onPressed: () {
                          setState(() {
                            _qtyController.text = n.toString();
                          });
                        },
                        child: Text('+$n', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
                      ),
                    ),
                  );
                }).toList(),
              ),
              const SizedBox(height: 16),

              // Quantity Input
              TextField(
                controller: _qtyController,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(
                  labelText: 'Quantity to Add / የሚጨመር መጠን (${_selectedProduct!.unit}) *',
                  border: const OutlineInputBorder(),
                  prefixIcon: const Icon(Icons.add_box_outlined),
                ),
              ),
              const SizedBox(height: 16),

              // Cost Price Input
              TextField(
                controller: _costController,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(
                  labelText: 'New Unit Cost Price (ETB) / አዲስ የመግዣ ዋጋ',
                  border: OutlineInputBorder(),
                  prefixIcon: Icon(Icons.attach_money),
                ),
              ),
              const SizedBox(height: 16),

              // Note Input
              TextField(
                controller: _noteController,
                decoration: const InputDecoration(
                  labelText: 'Supplier / Note / ማስታወሻ (Optional)',
                  border: OutlineInputBorder(),
                  prefixIcon: Icon(Icons.note_alt_outlined),
                ),
              ),
              const SizedBox(height: 28),

              FilledButton.icon(
                style: FilledButton.styleFrom(
                  backgroundColor: const Color(0xFF0F766E),
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                ),
                onPressed: _isLoading ? null : _confirmStockIn,
                icon: _isLoading
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                      )
                    : const Icon(Icons.check_circle_outline),
                label: const Text('Confirm Stock Intake / ገቢ መዝግብ', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _ProductSelectionSheet extends StatefulWidget {
  final List<ProductWithStock> products;

  const _ProductSelectionSheet({required this.products});

  @override
  State<_ProductSelectionSheet> createState() => _ProductSelectionSheetState();
}

class _ProductSelectionSheetState extends State<_ProductSelectionSheet> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final filtered = widget.products.where((p) {
      if (_query.isEmpty) return true;
      final q = _query.toLowerCase();
      return p.product.name.toLowerCase().contains(q) ||
          (p.product.sku?.toLowerCase().contains(q) ?? false);
    }).toList();

    return DraggableScrollableSheet(
      initialChildSize: 0.8,
      minChildSize: 0.5,
      maxChildSize: 0.95,
      expand: false,
      builder: (ctx, scroll) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Column(
          children: [
            Container(width: 40, height: 4, decoration: BoxDecoration(color: Theme.of(ctx).colorScheme.outline, borderRadius: BorderRadius.circular(2))),
            const SizedBox(height: 12),
            TextField(
              autofocus: true,
              decoration: InputDecoration(
                hintText: 'Search product...',
                prefixIcon: const Icon(Icons.search),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                contentPadding: const EdgeInsets.symmetric(vertical: 0, horizontal: 12),
              ),
              onChanged: (val) => setState(() => _query = val),
            ),
            const SizedBox(height: 10),
            Expanded(
              child: ListView.separated(
                controller: scroll,
                itemCount: filtered.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (ctx, index) {
                  final item = filtered[index];
                  return ListTile(
                    title: Text(item.product.name, style: const TextStyle(fontWeight: FontWeight.w600)),
                    subtitle: Text('Stock: ${QuantityFormatter.formatDisplay(item.stockMilli)} ${item.product.unit}'),
                    trailing: const Icon(Icons.arrow_forward_ios, size: 14),
                    onTap: () => Navigator.of(ctx).pop(item.product),
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
