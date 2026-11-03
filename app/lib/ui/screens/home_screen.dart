import 'package:flutter/material.dart';

import '../../database/app_database.dart';
import '../../repositories/ledger_repository.dart';
import '../../services/api_client.dart';
import '../../services/auth_storage.dart';
import '../../services/sync_engine.dart';
import '../../utils/money_formatter.dart';
import '../widgets/sync_status_header.dart';
import 'adjust_screen.dart';
import 'history_screen.dart';
import 'products_screen.dart';
import 'sale_screen.dart';
import 'settings_screen.dart';
import 'stock_in_screen.dart';

class HomeScreen extends StatefulWidget {
  final AppDatabase db;
  final LedgerRepository repository;
  final ApiClient apiClient;
  final AuthStorage authStorage;
  final SyncEngine syncEngine;

  const HomeScreen({
    super.key,
    required this.db,
    required this.repository,
    required this.apiClient,
    required this.authStorage,
    required this.syncEngine,
  });

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver {
  String _shopName = 'Inventory';
  String _language = 'am';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _loadShopInfo();
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
      _loadShopInfo();
    }
  }

  Future<void> _loadShopInfo() async {
    final name = await widget.repository.getShopName();
    final lang = await widget.repository.getLanguage();
    if (mounted) {
      setState(() {
        _shopName = name;
        _language = lang;
      });
    }
  }

  void _notifyMutation() {
    widget.syncEngine.notifyLocalMutation();
    setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final isAm = _language == 'am';

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              _shopName,
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
            ),
            Text(
              isAm ? 'የቀን መዝገብ / Offline POS' : 'Offline Inventory & POS',
              style: const TextStyle(fontSize: 11, color: Colors.grey),
            ),
          ],
        ),
        actions: [
          IconButton(
            tooltip: 'Language / ቋንቋ',
            icon: CircleAvatar(
              radius: 13,
              backgroundColor: const Color(0xFF0F766E).withValues(alpha: 0.15),
              child: Text(
                isAm ? 'አማ' : 'EN',
                style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Color(0xFF0F766E)),
              ),
            ),
            onPressed: () async {
              final newLang = isAm ? 'en' : 'am';
              await widget.repository.setLanguage(newLang);
              await _loadShopInfo();
            },
          ),
          IconButton(
            icon: const Icon(Icons.settings_outlined),
            tooltip: 'Settings / ቅንብሮች',
            onPressed: () async {
              await Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (ctx) => SettingsScreen(
                    apiClient: widget.apiClient,
                    authStorage: widget.authStorage,
                    syncEngine: widget.syncEngine,
                  ),
                ),
              );
              _loadShopInfo();
            },
          ),
        ],
      ),
      body: Column(
        children: [
          SyncStatusHeader(syncEngine: widget.syncEngine),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              children: [
                // Top KPI Metrics (Today's Sales & Low Stock Count)
                Row(
                  children: [
                    // Today's Sales Total
                    Expanded(
                      child: StreamBuilder<int>(
                        stream: widget.repository.watchTodaySalesTotalCents(),
                        builder: (context, snapshot) {
                          final cents = snapshot.data ?? 0;
                          return Card(
                            elevation: 1,
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                            color: Colors.green.shade50,
                            child: Padding(
                              padding: const EdgeInsets.all(14),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    children: [
                                      Icon(Icons.point_of_sale_rounded, size: 18, color: Colors.green.shade900),
                                      const SizedBox(width: 6),
                                      Text(
                                        isAm ? 'የዛሬ ሽያጭ' : "Today's Sales",
                                        style: TextStyle(
                                          fontWeight: FontWeight.bold,
                                          fontSize: 12,
                                          color: Colors.green.shade900,
                                        ),
                                      ),
                                    ],
                                  ),
                                  const SizedBox(height: 8),
                                  Text(
                                    MoneyFormatter.formatCents(cents),
                                    style: TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 18,
                                      color: Colors.green.shade900,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          );
                        },
                      ),
                    ),
                    const SizedBox(width: 10),

                    // Low Stock Count
                    Expanded(
                      child: StreamBuilder<int>(
                        stream: widget.repository.watchLowStockCount(),
                        builder: (context, snapshot) {
                          final count = snapshot.data ?? 0;
                          final isWarning = count > 0;
                          return Card(
                            elevation: 1,
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                            color: isWarning ? Colors.red.shade50 : Colors.grey.shade100,
                            child: Padding(
                              padding: const EdgeInsets.all(14),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    children: [
                                      Icon(
                                        Icons.warning_amber_rounded,
                                        size: 18,
                                        color: isWarning ? Colors.red.shade900 : Colors.grey.shade800,
                                      ),
                                      const SizedBox(width: 6),
                                      Text(
                                        isAm ? 'ያነሱ ዕቃዎች' : 'Low Stock',
                                        style: TextStyle(
                                          fontWeight: FontWeight.bold,
                                          fontSize: 12,
                                          color: isWarning ? Colors.red.shade900 : Colors.grey.shade800,
                                        ),
                                      ),
                                    ],
                                  ),
                                  const SizedBox(height: 8),
                                  Text(
                                    isWarning ? '$count items' : 'All good',
                                    style: TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 18,
                                      color: isWarning ? Colors.red.shade900 : Colors.grey.shade800,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          );
                        },
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 16),

                // Primary Big Action Button: [🛒 Sale / ሽያጭ]
                FilledButton.icon(
                  style: FilledButton.styleFrom(
                    backgroundColor: Colors.deepOrange,
                    foregroundColor: Colors.white,
                    minimumSize: const Size.fromHeight(60),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                  ),
                  onPressed: () {
                    Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (ctx) => SaleScreen(
                          repository: widget.repository,
                          onMutation: _notifyMutation,
                        ),
                      ),
                    );
                  },
                  icon: const Icon(Icons.shopping_cart_checkout, size: 28),
                  label: Text(
                    isAm ? '🛒 አዲስ ሽያጭ ጀምር (Sale)' : '🛒 Record New Sale',
                    style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                  ),
                ),
                const SizedBox(height: 12),

                // Secondary Action Buttons Row: [➕ Stock In] and [📦 Products]
                Row(
                  children: [
                    Expanded(
                      child: FilledButton.tonalIcon(
                        style: FilledButton.styleFrom(
                          minimumSize: const Size.fromHeight(54),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        onPressed: () {
                          Navigator.of(context).push(
                            MaterialPageRoute(
                              builder: (ctx) => StockInScreen(
                                repository: widget.repository,
                                onMutation: _notifyMutation,
                              ),
                            ),
                          );
                        },
                        icon: const Icon(Icons.add_box_outlined, size: 22, color: Color(0xFF0F766E)),
                        label: Text(
                          isAm ? '➕ ገቢ ዕቃ' : '➕ Stock In',
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: FilledButton.tonalIcon(
                        style: FilledButton.styleFrom(
                          minimumSize: const Size.fromHeight(54),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        onPressed: () {
                          Navigator.of(context).push(
                            MaterialPageRoute(
                              builder: (ctx) => ProductsScreen(
                                repository: widget.repository,
                                onMutation: _notifyMutation,
                              ),
                            ),
                          );
                        },
                        icon: const Icon(Icons.inventory_2_outlined, size: 22),
                        label: Text(
                          isAm ? '📦 ዕቃዎች' : '📦 Products',
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),

                // Tertiary Buttons: [🔧 Adjust Stock] & [📜 History & Undo]
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        style: OutlinedButton.styleFrom(
                          minimumSize: const Size.fromHeight(48),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        onPressed: () {
                          Navigator.of(context).push(
                            MaterialPageRoute(
                              builder: (ctx) => AdjustScreen(
                                repository: widget.repository,
                                onMutation: _notifyMutation,
                              ),
                            ),
                          );
                        },
                        icon: const Icon(Icons.tune, size: 18),
                        label: Text(
                          isAm ? '🔧 ስቶክ ማስተካከል' : '🔧 Adjust',
                          style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5),
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: OutlinedButton.icon(
                        style: OutlinedButton.styleFrom(
                          minimumSize: const Size.fromHeight(48),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        onPressed: () {
                          Navigator.of(context).push(
                            MaterialPageRoute(
                              builder: (ctx) => HistoryScreen(
                                repository: widget.repository,
                                onMutation: _notifyMutation,
                              ),
                            ),
                          );
                        },
                        icon: const Icon(Icons.history, size: 18),
                        label: Text(
                          isAm ? '📜 ታሪክ / Undo' : '📜 History / Undo',
                          style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 20),

                // Recent Transactions Header
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      isAm ? 'የቅርብ ጊዜ እንቅስቃሴዎች' : 'Recent Transactions',
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                    ),
                    TextButton(
                      onPressed: () {
                        Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (ctx) => HistoryScreen(
                              repository: widget.repository,
                              onMutation: _notifyMutation,
                            ),
                          ),
                        );
                      },
                      child: Text(isAm ? 'ሁሉንም እይ' : 'View All'),
                    ),
                  ],
                ),
                const SizedBox(height: 6),

                // Recent movements list preview
                StreamBuilder<List<MovementWithProduct>>(
                  stream: widget.repository.watchMovements(),
                  builder: (context, snapshot) {
                    final items = (snapshot.data ?? []).take(5).toList();
                    if (items.isEmpty) {
                      return Card(
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        child: Padding(
                          padding: const EdgeInsets.all(24),
                          child: Center(
                            child: Text(
                              isAm
                                  ? 'ምንም እንቅስቃሴ አልተመዘገበም። ሽያጭ ወይም ገቢ ዕቃ ይጀምሩ።'
                                  : 'No movements yet. Start by recording a sale or stock intake.',
                              textAlign: TextAlign.center,
                              style: const TextStyle(color: Colors.grey, fontSize: 13),
                            ),
                          ),
                        ),
                      );
                    }

                    return Card(
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      child: ListView.separated(
                        shrinkWrap: true,
                        physics: const NeverScrollableScrollPhysics(),
                        itemCount: items.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (context, idx) {
                          final item = items[idx];
                          final m = item.movement;
                          final p = item.product;
                          final isPositive = m.qtyMilli > 0;
                          final qtyStr = (isPositive ? '+' : '') +
                              (m.qtyMilli / 1000).toStringAsFixed(1);

                          IconData icon = m.type == 'sale'
                              ? Icons.shopping_cart
                              : (m.type == 'in' ? Icons.add_box : Icons.tune);
                          Color color = m.type == 'sale'
                              ? Colors.deepOrange
                              : (m.type == 'in' ? Colors.green : Colors.blue);

                          return ListTile(
                            dense: true,
                            leading: Icon(icon, color: color, size: 20),
                            title: Text(
                              p?.name ?? 'Item',
                              style: const TextStyle(fontWeight: FontWeight.bold),
                            ),
                            subtitle: Text(m.type.toUpperCase()),
                            trailing: Text(
                              '$qtyStr ${p?.unit ?? ""}',
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                color: isPositive ? Colors.green.shade800 : Colors.red.shade800,
                              ),
                            ),
                          );
                        },
                      ),
                    );
                  },
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
