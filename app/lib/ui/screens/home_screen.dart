import 'package:flutter/material.dart';

import '../../database/app_database.dart';
import '../../services/api_client.dart';
import '../../services/auth_storage.dart';
import '../../services/sync_engine.dart';
import '../../utils/money_formatter.dart';
import '../../utils/quantity_formatter.dart';
import '../widgets/sync_status_header.dart';
import 'settings_screen.dart';

class HomeScreen extends StatefulWidget {
  final AppDatabase db;
  final ApiClient apiClient;
  final AuthStorage authStorage;
  final SyncEngine syncEngine;

  const HomeScreen({
    super.key,
    required this.db,
    required this.apiClient,
    required this.authStorage,
    required this.syncEngine,
  });

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      widget.syncEngine.onAppResume();
    }
  }

  Future<void> _showAddProductDialog() async {
    final nameCtrl = TextEditingController();
    final sellPriceCtrl = TextEditingController();
    final costPriceCtrl = TextEditingController();
    final unitCtrl = TextEditingController(text: 'pcs');
    final openingStockCtrl = TextEditingController(text: '0');

    final result = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Add Product / አዲስ ዕቃ መዝግብ'),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: nameCtrl,
                decoration: const InputDecoration(labelText: 'Name / ስም (e.g. Sugar, ጤፍ)'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: sellPriceCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(labelText: 'Sell Price ETB / መሸጫ ዋጋ'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: costPriceCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(labelText: 'Cost Price ETB / መግዣ ዋጋ (Optional)'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: unitCtrl,
                decoration: const InputDecoration(labelText: 'Unit / መለኪያ (pcs, kg, lt, pack)'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: openingStockCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(labelText: 'Opening Stock / የመጀመሪያ ስቶክ'),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancel')),
          FilledButton(
            onPressed: () {
              if (nameCtrl.text.trim().isEmpty) return;
              Navigator.of(ctx).pop(true);
            },
            child: const Text('Save'),
          ),
        ],
      ),
    );

    if (result == true) {
      final name = nameCtrl.text.trim();
      final sellPriceCents = MoneyFormatter.parseCents(sellPriceCtrl.text);
      final costPriceCents = MoneyFormatter.parseCents(costPriceCtrl.text);
      final unit = unitCtrl.text.trim().isNotEmpty ? unitCtrl.text.trim() : 'pcs';
      final openingMilli = QuantityFormatter.parseQty(openingStockCtrl.text);

      final product = await widget.db.createProduct(
        name: name,
        unit: unit,
        sellPriceCents: sellPriceCents,
        costPriceCents: costPriceCents,
      );

      if (openingMilli > 0) {
        await widget.db.recordMovement(
          productId: product.id,
          type: 'in',
          qtyMilli: openingMilli,
          unitPriceCents: costPriceCents,
          note: 'Opening stock',
        );
      }

      widget.syncEngine.notifyLocalMutation();
    }
  }

  Future<void> _recordQuickMovement({
    required Product product,
    required bool isSale,
  }) async {
    final qtyCtrl = TextEditingController(text: '1');
    final title = isSale ? 'Record Sale (ሽያጭ)' : 'Stock Intake (ገቢ ስቶክ)';

    final result = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('$title — ${product.name}'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: qtyCtrl,
              autofocus: true,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(
                labelText: 'Quantity (${product.unit})',
                suffixText: product.unit,
              ),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancel')),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: isSale ? Colors.deepOrange : Colors.green,
            ),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: Text(isSale ? 'Sell' : 'Add Stock'),
          ),
        ],
      ),
    );

    if (result == true) {
      final qtyInput = QuantityFormatter.parseQty(qtyCtrl.text);
      if (qtyInput <= 0) return;

      final signedQtyMilli = isSale ? -qtyInput : qtyInput;
      final type = isSale ? 'sale' : 'in';
      final unitPrice = isSale ? product.sellPriceCents : product.costPriceCents;

      await widget.db.recordMovement(
        productId: product.id,
        type: type,
        qtyMilli: signedQtyMilli,
        unitPriceCents: unitPrice,
        note: isSale ? 'Mobile app sale' : 'Stock in',
      );

      widget.syncEngine.notifyLocalMutation();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Inventory / እቃ ዝርዝር'),
        actions: [
          IconButton(
            icon: const Icon(Icons.settings),
            tooltip: 'Settings / ቅንብሮች',
            onPressed: () {
              Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (ctx) => SettingsScreen(
                    apiClient: widget.apiClient,
                    authStorage: widget.authStorage,
                    syncEngine: widget.syncEngine,
                  ),
                ),
              );
            },
          ),
        ],
      ),
      body: Column(
        children: [
          SyncStatusHeader(syncEngine: widget.syncEngine),
          Expanded(
            child: StreamBuilder<List<ProductWithStock>>(
              stream: widget.db.watchAllProductsWithStock(),
              builder: (context, snapshot) {
                if (snapshot.connectionState == ConnectionState.waiting && !snapshot.hasData) {
                  return const Center(child: CircularProgressIndicator());
                }

                final items = snapshot.data ?? [];
                if (items.isEmpty) {
                  return Center(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.inventory_2_outlined, size: 64, color: Colors.grey.shade400),
                        const SizedBox(height: 12),
                        const Text(
                          'No products yet / ምንም ዕቃዎች አልተመዘገቡም',
                          style: TextStyle(fontSize: 16, color: Colors.grey),
                        ),
                        const SizedBox(height: 8),
                        const Text(
                          'Tap + below to add your first product.',
                          style: TextStyle(fontSize: 13, color: Colors.grey),
                        ),
                      ],
                    ),
                  );
                }

                return ListView.separated(
                  padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
                  itemCount: items.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 6),
                  itemBuilder: (context, index) {
                    final item = items[index];
                    final p = item.product;
                    final stock = item.stockMilli;
                    final isNegative = item.isNegative;
                    final isLow = item.isLow;

                    Color stockColor = Colors.green.shade800;
                    if (isNegative) {
                      stockColor = Colors.amber.shade900;
                    } else if (isLow) {
                      stockColor = Colors.red.shade800;
                    }

                    return Card(
                      elevation: 1,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
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
                                    '${MoneyFormatter.formatCents(p.sellPriceCents)} • Stock: ${QuantityFormatter.formatDisplay(stock)} ${p.unit}',
                                    style: TextStyle(
                                      fontSize: 13,
                                      fontWeight: FontWeight.w600,
                                      color: stockColor,
                                    ),
                                  ),
                                  if (isNegative) ...[
                                    const SizedBox(height: 4),
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                      decoration: BoxDecoration(
                                        color: Colors.amber.shade100,
                                        borderRadius: BorderRadius.circular(4),
                                      ),
                                      child: Text(
                                        '⚠️ Check Stock / የስቶክ መጠን ያረጋግጡ',
                                        style: TextStyle(
                                          fontSize: 11,
                                          fontWeight: FontWeight.bold,
                                          color: Colors.amber.shade900,
                                        ),
                                      ),
                                    ),
                                  ],
                                ],
                              ),
                            ),
                            Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                IconButton.filledTonal(
                                  icon: const Icon(Icons.add, size: 18),
                                  tooltip: 'Stock in',
                                  onPressed: () => _recordQuickMovement(product: p, isSale: false),
                                ),
                                const SizedBox(width: 6),
                                IconButton.filled(
                                  style: IconButton.styleFrom(backgroundColor: Colors.deepOrange),
                                  icon: const Icon(Icons.shopping_cart_checkout, size: 18),
                                  tooltip: 'Record sale',
                                  onPressed: () => _recordQuickMovement(product: p, isSale: true),
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
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _showAddProductDialog,
        icon: const Icon(Icons.add),
        label: const Text('Add Product'),
      ),
    );
  }
}
