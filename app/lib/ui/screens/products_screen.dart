import 'package:drift/drift.dart' show Value;
import 'package:flutter/material.dart';

import '../../database/app_database.dart';
import '../../repositories/ledger_repository.dart';
import '../../utils/money_formatter.dart';
import '../../utils/quantity_formatter.dart';

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
  final _searchController = TextEditingController();
  String _searchQuery = '';

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _showAddOrEditProductDialog({Product? existing}) async {
    final nameCtrl = TextEditingController(text: existing?.name ?? '');
    final skuCtrl = TextEditingController(text: existing?.sku ?? '');
    final unitCtrl = TextEditingController(text: existing?.unit ?? 'pcs');
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

    final isEdit = existing != null;
    final title = isEdit ? 'Edit Product / ዕቃ አሻሽል' : 'Add Product / አዲስ ዕቃ መዝግብ';

    final result = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: nameCtrl,
                autofocus: true,
                decoration: const InputDecoration(labelText: 'Product Name / ስም *'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: skuCtrl,
                decoration: const InputDecoration(labelText: 'SKU / Barcode / ባርኮድ (Optional)'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: sellPriceCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(labelText: 'Selling Price ETB / መሸጫ ዋጋ *'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: costPriceCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(labelText: 'Cost Price ETB / መግዣ ዋጋ'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: unitCtrl,
                decoration: const InputDecoration(labelText: 'Unit / መለኪያ (pcs, kg, lt, pack)'),
              ),
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
      ),
    );

    if (result == true) {
      final name = nameCtrl.text.trim();
      final sku = skuCtrl.text.trim().isNotEmpty ? skuCtrl.text.trim() : null;
      final unit = unitCtrl.text.trim().isNotEmpty ? unitCtrl.text.trim() : 'pcs';
      final sellPriceCents = MoneyFormatter.parseCents(sellPriceCtrl.text);
      final costPriceCents = MoneyFormatter.parseCents(costPriceCtrl.text);
      final lowStockMilli = QuantityFormatter.parseQty(lowStockCtrl.text);

      if (isEdit) {
        final updated = existing.copyWith(
          name: name,
          sku: sku != null ? Value(sku) : const Value.absent(),
          unit: unit,
          sellPriceCents: sellPriceCents,
          costPriceCents: costPriceCents,
          lowStockAtMilli: Value(lowStockMilli > 0 ? lowStockMilli : null),
        );
        await widget.repository.updateProduct(updated);
      } else {
        final openingMilli = QuantityFormatter.parseQty(openingStockCtrl.text);
        await widget.repository.createProduct(
          name: name,
          sku: sku,
          unit: unit,
          sellPriceCents: sellPriceCents,
          costPriceCents: costPriceCents,
          lowStockAtMilli: lowStockMilli > 0 ? lowStockMilli : null,
          openingStockMilli: openingMilli > 0 ? openingMilli : null,
        );
      }

      widget.onMutation?.call();
      setState(() {});
    }
  }

  Future<void> _confirmDeleteProduct(Product product) async {
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
            Text(
              'Stock: $stockFormatted ${p.unit} • Cost: ${MoneyFormatter.formatCents(p.costPriceCents)}',
              style: TextStyle(color: Colors.grey.shade700, fontSize: 13),
            ),
            const Divider(height: 24),
            ListTile(
              leading: const Icon(Icons.edit_outlined, color: Colors.blue),
              title: const Text('Edit Product Details / መረጃ አሻሽል'),
              onTap: () {
                Navigator.of(ctx).pop();
                _showAddOrEditProductDialog(existing: p);
              },
            ),
            ListTile(
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
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
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
              onChanged: (val) => setState(() => _searchQuery = val),
            ),
          ),
          Expanded(
            child: StreamBuilder<List<ProductWithStock>>(
              stream: widget.repository.watchProducts(query: _searchQuery),
              builder: (context, snapshot) {
                if (!snapshot.hasData) {
                  return const Center(child: CircularProgressIndicator());
                }

                final products = snapshot.data!;
                if (products.isEmpty) {
                  return Center(
                    child: Text(
                      _searchQuery.isNotEmpty
                          ? 'No matching products found / ምንም አልተገኘም'
                          : 'No products yet / ምንም ዕቃዎች አልተመዘገቡም',
                      style: const TextStyle(color: Colors.grey),
                    ),
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
                    final isNegative = item.isNegative;
                    final isLow = item.isLow;

                    Color badgeBg = Colors.green.shade50;
                    Color badgeFg = Colors.green.shade900;
                    String badgeText = '🟢 In Stock';

                    if (isNegative) {
                      badgeBg = Colors.amber.shade100;
                      badgeFg = Colors.amber.shade900;
                      badgeText = '⚠️ Negative (${QuantityFormatter.formatDisplay(stock)})';
                    } else if (isLow) {
                      badgeBg = Colors.red.shade50;
                      badgeFg = Colors.red.shade900;
                      badgeText = '🔴 Low (${QuantityFormatter.formatDisplay(stock)})';
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
                                      'Price: ${MoneyFormatter.formatCents(p.sellPriceCents)} • Stock: ${QuantityFormatter.formatDisplay(stock)} ${p.unit}',
                                      style: TextStyle(fontSize: 13, color: Colors.grey.shade800),
                                    ),
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
