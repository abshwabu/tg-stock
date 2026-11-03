import 'package:flutter/material.dart';

import '../../database/app_database.dart';
import '../../repositories/ledger_repository.dart';
import '../../utils/quantity_formatter.dart';
import '../widgets/location_selector_chip.dart';

class AdjustScreen extends StatefulWidget {
  final LedgerRepository repository;
  final VoidCallback? onMutation;

  const AdjustScreen({
    super.key,
    required this.repository,
    this.onMutation,
  });

  @override
  State<AdjustScreen> createState() => _AdjustScreenState();
}

class _AdjustScreenState extends State<AdjustScreen> {
  Product? _selectedProduct;
  Location? _activeLocation;
  int _currentStock = 0;
  final _countedController = TextEditingController();
  final _noteController = TextEditingController();

  String _reason = 'damaged';
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
    _countedController.dispose();
    _noteController.dispose();
    super.dispose();
  }

  Future<void> _onProductSelected(Product p) async {
    final stock = await widget.repository.stockOf(p.id, locationId: _activeLocation?.id);
    setState(() {
      _selectedProduct = p;
      _currentStock = stock;
      _countedController.text = QuantityFormatter.formatDisplay(stock);
    });
  }

  Future<void> _pickProduct() async {
    final products = await widget.repository.listProducts();
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

  int get _countedMilli {
    return QuantityFormatter.parseQty(_countedController.text);
  }

  int get _diffMilli {
    return _countedMilli - _currentStock;
  }

  Future<void> _confirmAdjust() async {
    if (_selectedProduct == null) return;

    setState(() => _isLoading = true);
    try {
      final note = _noteController.text.trim();

      await widget.repository.recordAdjustment(
        productId: _selectedProduct!.id,
        countedQtyMilli: _countedMilli,
        reason: _reason,
        note: note.isNotEmpty ? note : null,
        locationId: _activeLocation?.id,
      );

      widget.onMutation?.call();

      final newStock = await widget.repository.stockOf(_selectedProduct!.id, locationId: _activeLocation?.id);
      final diffStr = (_diffMilli > 0 ? '+' : '') + QuantityFormatter.formatDisplay(_diffMilli);
      final newStockStr = QuantityFormatter.formatDisplay(newStock);

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            backgroundColor: Colors.blue.shade800,
            content: Text(
              '🔧 Adjusted ${_selectedProduct!.name} by $diffStr ${_selectedProduct!.unit}. New stock: $newStockStr',
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
    final diffFormatted = (_diffMilli > 0 ? '+' : '') + QuantityFormatter.formatDisplay(_diffMilli);
    Color diffColor = Colors.grey.shade700;
    if (_diffMilli > 0) {
      diffColor = Colors.green.shade800;
    } else if (_diffMilli < 0) {
      diffColor = Colors.red.shade800;
    }

    return Scaffold(
      appBar: AppBar(
        title: const Text('Stock Adjustment / ስቶክ ማስተካከል'),
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
            // Product selector
            Card(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              child: InkWell(
                borderRadius: BorderRadius.circular(12),
                onTap: _pickProduct,
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Row(
                    children: [
                      const Icon(Icons.build_circle_outlined, size: 28, color: Colors.blue),
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
                                'System Stock: ${QuantityFormatter.formatDisplay(_currentStock)} ${_selectedProduct!.unit}',
                                style: TextStyle(color: Colors.grey.shade700, fontSize: 13),
                              ),
                            ] else
                              const Text('Tap to choose product', style: TextStyle(fontSize: 12, color: Colors.grey)),
                          ],
                        ),
                      ),
                      const Icon(Icons.arrow_forward_ios, size: 16, color: Colors.grey),
                    ],
                  ),
                ),
              ),
            ),
            const SizedBox(height: 20),

            if (_selectedProduct != null) ...[
              // Counted physical stock
              TextField(
                controller: _countedController,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(
                  labelText: 'Actual Counted Quantity / የተቆጠረው ትክክለኛ መጠን (${_selectedProduct!.unit}) *',
                  border: const OutlineInputBorder(),
                  prefixIcon: const Icon(Icons.pin_outlined),
                ),
                onChanged: (_) => setState(() {}),
              ),
              const SizedBox(height: 16),

              // Live difference preview
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: Colors.grey.shade100,
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: Colors.grey.shade300),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text('Adjustment Difference / ልዩነት:', style: TextStyle(fontWeight: FontWeight.w600)),
                    Text(
                      '$diffFormatted ${_selectedProduct!.unit}',
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: diffColor),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),

              // Reason dropdown
              const Text('Reason / ምክንያት:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
              const SizedBox(height: 8),
              DropdownButtonFormField<String>(
                initialValue: _reason,
                decoration: const InputDecoration(border: OutlineInputBorder()),
                items: const [
                  DropdownMenuItem(value: 'damaged', child: Text('Damaged / Spoilage (የተበላሸ / የወደቀ)')),
                  DropdownMenuItem(value: 'lost', child: Text('Lost / Shrinkage (የጠፋ / ጉድለት)')),
                  DropdownMenuItem(value: 'count_correction', child: Text('Count Correction (የቆጠራ ስህተት እርማት)')),
                  DropdownMenuItem(value: 'other', child: Text('Other (ሌላ ምክንያት)')),
                ],
                onChanged: (val) {
                  if (val != null) setState(() => _reason = val);
                },
              ),
              const SizedBox(height: 16),

              // Note
              TextField(
                controller: _noteController,
                decoration: const InputDecoration(
                  labelText: 'Additional Details / ማስታወሻ (Optional)',
                  border: OutlineInputBorder(),
                  prefixIcon: Icon(Icons.note_alt_outlined),
                ),
              ),
              const SizedBox(height: 28),

              FilledButton.icon(
                style: FilledButton.styleFrom(
                  backgroundColor: Colors.blue.shade800,
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                ),
                onPressed: _isLoading ? null : _confirmAdjust,
                icon: _isLoading
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                      )
                    : const Icon(Icons.check_circle_outline),
                label: const Text('Save Adjustment / ማስተካከያውን መዝግብ', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
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
            Container(width: 40, height: 4, decoration: BoxDecoration(color: Colors.grey.shade300, borderRadius: BorderRadius.circular(2))),
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
