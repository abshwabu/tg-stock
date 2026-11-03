import 'dart:async';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../repositories/ledger_repository.dart';
import '../../utils/money_formatter.dart';
import '../../utils/quantity_formatter.dart';

class HistoryScreen extends StatefulWidget {
  final LedgerRepository repository;
  final VoidCallback? onMutation;

  const HistoryScreen({
    super.key,
    required this.repository,
    this.onMutation,
  });

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> with SingleTickerProviderStateMixin {
  late final TabController _tabController;
  String _selectedFilter = 'all';
  Timer? _countdownTimer;
  List<SaleWithDetails> _salesList = [];
  bool _isLoadingSales = true;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _countdownTimer = Timer.periodic(const Duration(seconds: 10), (_) {
      if (mounted) setState(() {});
    });
    _loadSales();
  }

  @override
  void dispose() {
    _tabController.dispose();
    _countdownTimer?.cancel();
    super.dispose();
  }

  Future<void> _loadSales() async {
    final list = await widget.repository.listSalesWithDetails();
    if (mounted) {
      setState(() {
        _salesList = list;
        _isLoadingSales = false;
      });
    }
  }

  Future<void> _undoSale(SaleWithDetails saleWithDetails) async {
    final s = saleWithDetails.sale;
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Undo Sale / ሽያጭ ሰርዝ?'),
        content: Text(
          'Are you sure you want to undo this sale of ${MoneyFormatter.formatCents(s.totalCents)}?\n\n'
          'This will:\n'
          '• Void the sale\n'
          '• Restore stock to inventory\n'
          '• Reverse recorded payments',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancel')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.deepOrange),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Confirm Undo / ሰርዝ'),
          ),
        ],
      ),
    );

    if (confirm != true || !mounted) return;

    try {
      await widget.repository.undoSale(s.id);
      widget.onMutation?.call();
      await _loadSales();

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            backgroundColor: Colors.green,
            content: Text('↩️ Sale voided and stock restored successfully!'),
          ),
        );
      }
    } on UndoExpiredException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(backgroundColor: Colors.red, content: Text('❌ $e')),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(backgroundColor: Colors.red, content: Text('Undo failed: $e')),
        );
      }
    }
  }

  Future<void> _undoLastMovement() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Undo Last Transaction? / ግብይት መልስ?'),
        content: const Text(
          'This will record a compensating movement to reverse your last action.\n\nActions can only be undone within 10 minutes of creation.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancel')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.deepOrange),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Confirm Undo / መልስ'),
          ),
        ],
      ),
    );

    if (confirm != true || !mounted) return;

    try {
      final compensating = await widget.repository.undoLastAction();
      widget.onMutation?.call();
      await _loadSales();

      final qtyStr = (compensating.qtyMilli > 0 ? '+' : '') +
          QuantityFormatter.formatDisplay(compensating.qtyMilli);

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            backgroundColor: Colors.green,
            content: Text('↩️ Action undone! Recorded compensating movement: $qtyStr'),
          ),
        );
      }
    } on UndoExpiredException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(backgroundColor: Colors.red, content: Text('❌ $e')),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(backgroundColor: Colors.red, content: Text('Undo failed: $e')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('History & Ledger / ታሪክ እና መዝገብ'),
        bottom: TabBar(
          controller: _tabController,
          tabs: const [
            Tab(icon: Icon(Icons.point_of_sale), text: 'Sales / ሽያጮች'),
            Tab(icon: Icon(Icons.swap_vert), text: 'Movements / እንቅስቃሴ'),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabController,
        children: [
          _buildSalesTab(),
          _buildMovementsTab(),
        ],
      ),
    );
  }

  Widget _buildSalesTab() {
    if (_isLoadingSales) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_salesList.isEmpty) {
      return const Center(
        child: Text('No sales recorded yet / ምንም ሽያጭ የለም', style: TextStyle(color: Colors.grey)),
      );
    }

    return RefreshIndicator(
      onRefresh: _loadSales,
      child: ListView.separated(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        itemCount: _salesList.length,
        separatorBuilder: (_, __) => const SizedBox(height: 6),
        itemBuilder: (context, index) {
          final s = _salesList[index];
          final sale = s.sale;
          final dateStr = DateFormat('MMM d, HH:mm').format(
            DateTime.fromMillisecondsSinceEpoch(sale.createdAtMs).toLocal(),
          );

          Color badgeColor;
          Color badgeTextColor;
          switch (s.statusBadge) {
            case 'Paid':
              badgeColor = Colors.green.shade100;
              badgeTextColor = Colors.green.shade900;
              break;
            case 'Partial':
              badgeColor = Colors.amber.shade100;
              badgeTextColor = Colors.amber.shade900;
              break;
            case 'Dube':
              badgeColor = Colors.deepOrange.shade100;
              badgeTextColor = Colors.deepOrange.shade900;
              break;
            default: // Voided
              badgeColor = Colors.grey.shade200;
              badgeTextColor = Colors.grey.shade800;
              break;
          }

          return Card(
            elevation: 1,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        MoneyFormatter.formatCents(sale.totalCents),
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                      Row(
                        children: [
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: badgeColor,
                              borderRadius: BorderRadius.circular(6),
                            ),
                            child: Text(
                              s.statusBadge,
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                                color: badgeTextColor,
                              ),
                            ),
                          ),
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: Colors.blueGrey.shade50,
                              borderRadius: BorderRadius.circular(6),
                            ),
                            child: Text(
                              s.primaryMethod.toUpperCase(),
                              style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w600,
                                color: Colors.blueGrey.shade800,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        '$dateStr • ${s.customer?.name ?? "Anonymous"}',
                        style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                      ),
                      if (s.canUndo)
                        TextButton.icon(
                          style: TextButton.styleFrom(
                            foregroundColor: Colors.red,
                            visualDensity: VisualDensity.compact,
                            padding: EdgeInsets.zero,
                          ),
                          onPressed: () => _undoSale(s),
                          icon: const Icon(Icons.undo, size: 16),
                          label: const Text('Undo', style: TextStyle(fontWeight: FontWeight.bold)),
                        ),
                    ],
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _buildMovementsTab() {
    return Column(
      children: [
        // Filter Row
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(
            children: [
              _buildFilterChip('all', 'All / ሁሉም'),
              const SizedBox(width: 6),
              _buildFilterChip('sale', 'Sales / ሽያጭ'),
              const SizedBox(width: 6),
              _buildFilterChip('in', 'Intake / ገቢ'),
              const SizedBox(width: 6),
              _buildFilterChip('adjust', 'Adjust / እርማት'),
            ],
          ),
        ),
        const Divider(height: 1),

        // Movements stream list
        Expanded(
          child: StreamBuilder<List<MovementWithProduct>>(
            stream: widget.repository.watchMovements(type: _selectedFilter),
            builder: (context, snapshot) {
              if (!snapshot.hasData) {
                return const Center(child: CircularProgressIndicator());
              }

              final list = snapshot.data!;
              if (list.isEmpty) {
                return const Center(
                  child: Text('No movements recorded yet / ምንም እንቅስቃሴ የለም', style: TextStyle(color: Colors.grey)),
                );
              }

              final mostRecent = list.first;
              final canUndoMostRecent = mostRecent.canUndo;

              return Column(
                children: [
                  if (canUndoMostRecent) ...[
                    Container(
                      margin: const EdgeInsets.all(12),
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                      decoration: BoxDecoration(
                        color: Colors.amber.shade50,
                        borderRadius: BorderRadius.circular(10),
                        border: Border.all(color: Colors.amber.shade400),
                      ),
                      child: Row(
                        children: [
                          Icon(Icons.history_toggle_off, color: Colors.amber.shade900),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  'Last action: ${mostRecent.product?.name ?? "Item"} (${mostRecent.movement.type})',
                                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Colors.amber.shade900),
                                ),
                                const Text(
                                  'Eligible for 1-tap undo within 10 minutes',
                                  style: TextStyle(fontSize: 11, color: Colors.black87),
                                ),
                              ],
                            ),
                          ),
                          FilledButton.tonalIcon(
                            style: FilledButton.styleFrom(
                              backgroundColor: Colors.amber.shade200,
                              foregroundColor: Colors.amber.shade900,
                            ),
                            onPressed: _undoLastMovement,
                            icon: const Icon(Icons.undo, size: 16),
                            label: const Text('Undo', style: TextStyle(fontWeight: FontWeight.bold)),
                          ),
                        ],
                      ),
                    ),
                  ],
                  Expanded(
                    child: ListView.separated(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                      itemCount: list.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 4),
                      itemBuilder: (context, index) {
                        final item = list[index];
                        final m = item.movement;
                        final p = item.product;

                        final isPositive = m.qtyMilli > 0;
                        final qtyStr = (isPositive ? '+' : '') +
                            QuantityFormatter.formatDisplay(m.qtyMilli);

                        IconData iconData;
                        Color iconColor;
                        Color qtyColor;

                        switch (m.type) {
                          case 'sale':
                            iconData = Icons.shopping_cart;
                            iconColor = Colors.deepOrange;
                            qtyColor = Colors.deepOrange.shade800;
                            break;
                          case 'in':
                            iconData = Icons.add_box;
                            iconColor = Colors.green;
                            qtyColor = Colors.green.shade800;
                            break;
                          default:
                            iconData = Icons.tune;
                            iconColor = Colors.blue;
                            qtyColor = isPositive ? Colors.green.shade800 : Colors.red.shade800;
                            break;
                        }

                        final dateStr = DateFormat('MMM d, HH:mm').format(
                          DateTime.fromMillisecondsSinceEpoch(m.createdAtMs).toLocal(),
                        );

                        return Card(
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                            child: Row(
                              children: [
                                CircleAvatar(
                                  radius: 18,
                                  backgroundColor: iconColor.withValues(alpha: 0.15),
                                  child: Icon(iconData, size: 18, color: iconColor),
                                ),
                                const SizedBox(width: 12),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        p?.name ?? 'Deleted Product',
                                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14.5),
                                      ),
                                      const SizedBox(height: 2),
                                      Text(
                                        '$dateStr${m.note != null ? " • ${m.note}" : ""}',
                                        style: TextStyle(fontSize: 11.5, color: Colors.grey.shade600),
                                      ),
                                    ],
                                  ),
                                ),
                                Column(
                                  crossAxisAlignment: CrossAxisAlignment.end,
                                  children: [
                                    Text(
                                      '$qtyStr ${p?.unit ?? ""}',
                                      style: TextStyle(
                                        fontWeight: FontWeight.bold,
                                        fontSize: 15,
                                        color: qtyColor,
                                      ),
                                    ),
                                    if (m.unitPriceCents > 0) ...[
                                      const SizedBox(height: 2),
                                      Text(
                                        MoneyFormatter.formatCents(
                                          ((m.qtyMilli.abs() * m.unitPriceCents) / 1000).round(),
                                        ),
                                        style: TextStyle(fontSize: 11.5, color: Colors.grey.shade700),
                                      ),
                                    ],
                                  ],
                                ),
                              ],
                            ),
                          ),
                        );
                      },
                    ),
                  ),
                ],
              );
            },
          ),
        ),
      ],
    );
  }

  Widget _buildFilterChip(String type, String label) {
    final isSelected = _selectedFilter == type;
    return Expanded(
      child: FilterChip(
        selected: isSelected,
        label: Text(label, style: TextStyle(fontSize: 11, fontWeight: isSelected ? FontWeight.bold : FontWeight.normal)),
        onSelected: (_) => setState(() => _selectedFilter = type),
      ),
    );
  }
}
