import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../repositories/ledger_repository.dart';
import '../../services/export_service.dart';
import '../../utils/money_formatter.dart';
import '../../utils/quantity_formatter.dart';

enum ReportRange {
  today,
  yesterday,
  week,
  month,
  custom,
}

class ReportsScreen extends StatefulWidget {
  final LedgerRepository repository;

  const ReportsScreen({super.key, required this.repository});

  @override
  State<ReportsScreen> createState() => _ReportsScreenState();
}

class _ReportsScreenState extends State<ReportsScreen> {
  ReportRange _selectedRange = ReportRange.today;
  DateTimeRange? _customDateRange;
  bool _isLoading = true;

  SalesReportData? _salesReport;
  StockReportData? _stockReport;

  late final ExportService _exportService;

  @override
  void initState() {
    super.initState();
    _exportService = ExportService(repository: widget.repository);
    _loadReport();
  }

  (int, int) _calculateTimeWindow() {
    final now = DateTime.now();
    switch (_selectedRange) {
      case ReportRange.today:
        final start = DateTime(now.year, now.month, now.day);
        final end = DateTime(now.year, now.month, now.day, 23, 59, 59, 999);
        return (start.toUtc().millisecondsSinceEpoch, end.toUtc().millisecondsSinceEpoch);

      case ReportRange.yesterday:
        final yest = now.subtract(const Duration(days: 1));
        final start = DateTime(yest.year, yest.month, yest.day);
        final end = DateTime(yest.year, yest.month, yest.day, 23, 59, 59, 999);
        return (start.toUtc().millisecondsSinceEpoch, end.toUtc().millisecondsSinceEpoch);

      case ReportRange.week:
        final start = DateTime(now.year, now.month, now.day).subtract(const Duration(days: 6));
        final end = DateTime(now.year, now.month, now.day, 23, 59, 59, 999);
        return (start.toUtc().millisecondsSinceEpoch, end.toUtc().millisecondsSinceEpoch);

      case ReportRange.month:
        final start = DateTime(now.year, now.month, 1);
        final end = DateTime(now.year, now.month, now.day, 23, 59, 59, 999);
        return (start.toUtc().millisecondsSinceEpoch, end.toUtc().millisecondsSinceEpoch);

      case ReportRange.custom:
        if (_customDateRange != null) {
          final start = DateTime(_customDateRange!.start.year, _customDateRange!.start.month, _customDateRange!.start.day);
          final end = DateTime(_customDateRange!.end.year, _customDateRange!.end.month, _customDateRange!.end.day, 23, 59, 59, 999);
          return (start.toUtc().millisecondsSinceEpoch, end.toUtc().millisecondsSinceEpoch);
        }
        final start = DateTime(now.year, now.month, now.day);
        final end = DateTime(now.year, now.month, now.day, 23, 59, 59, 999);
        return (start.toUtc().millisecondsSinceEpoch, end.toUtc().millisecondsSinceEpoch);
    }
  }

  Future<void> _loadReport() async {
    setState(() => _isLoading = true);
    final (fromMs, toMs) = _calculateTimeWindow();

    final sales = await widget.repository.getSalesReport(fromMs: fromMs, toMs: toMs);
    final stock = await widget.repository.getStockReport();

    if (mounted) {
      setState(() {
        _salesReport = sales;
        _stockReport = stock;
        _isLoading = false;
      });
    }
  }

  Future<void> _pickCustomRange() async {
    final now = DateTime.now();
    final picked = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2020),
      lastDate: DateTime(now.year + 2),
      initialDateRange: _customDateRange ?? DateTimeRange(
        start: now.subtract(const Duration(days: 7)),
        end: now,
      ),
    );

    if (picked != null) {
      setState(() {
        _customDateRange = picked;
        _selectedRange = ReportRange.custom;
      });
      await _loadReport();
    }
  }

  String _getRangeLabel() {
    switch (_selectedRange) {
      case ReportRange.today:
        return 'Today / ዛሬ';
      case ReportRange.yesterday:
        return 'Yesterday / ትላንት';
      case ReportRange.week:
        return 'Past 7 Days / ያለፉት 7 ቀናት';
      case ReportRange.month:
        return 'This Month / በዚህ ወር';
      case ReportRange.custom:
        if (_customDateRange != null) {
          final f = DateFormat('MMM d');
          return '${f.format(_customDateRange!.start)} – ${f.format(_customDateRange!.end)}';
        }
        return 'Custom';
    }
  }

  Future<void> _exportSalesReport() async {
    final (fromMs, toMs) = _calculateTimeWindow();
    final file = await _exportService.exportSalesCsv(fromMs: fromMs, toMs: toMs);
    await _exportService.shareFile(file, subject: 'Sales Report (${_getRangeLabel()})');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Reports & Analytics / ሪፖርቶች'),
        actions: [
          IconButton(
            icon: const Icon(Icons.share_outlined),
            tooltip: 'Export Sales CSV',
            onPressed: _salesReport == null ? null : _exportSalesReport,
          ),
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadReport,
          ),
        ],
      ),
      body: Column(
        children: [
          // Range Filter Bar
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            child: Row(
              children: [
                _filterChip('Today (ዛሬ)', ReportRange.today),
                const SizedBox(width: 6),
                _filterChip('Yesterday (ትላንት)', ReportRange.yesterday),
                const SizedBox(width: 6),
                _filterChip('Week (ሳምንት)', ReportRange.week),
                const SizedBox(width: 6),
                _filterChip('Month (ወር)', ReportRange.month),
                const SizedBox(width: 6),
                ActionChip(
                  avatar: const Icon(Icons.calendar_month, size: 16),
                  label: Text(_selectedRange == ReportRange.custom && _customDateRange != null
                      ? _getRangeLabel()
                      : 'Custom (ምረጥ)'),
                  backgroundColor: _selectedRange == ReportRange.custom ? const Color(0xFF0F766E).withValues(alpha: 0.15) : null,
                  onPressed: _pickCustomRange,
                ),
              ],
            ),
          ),
          const Divider(height: 1),

          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : RefreshIndicator(
                    onRefresh: _loadReport,
                    child: ListView(
                      padding: const EdgeInsets.all(16),
                      children: [
                        // Range indicator banner
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                          decoration: BoxDecoration(
                            color: Colors.grey.shade100,
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Row(
                            children: [
                              const Icon(Icons.analytics_outlined, size: 18, color: Color(0xFF0F766E)),
                              const SizedBox(width: 8),
                              Text(
                                'Viewing: ${_getRangeLabel()}',
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 14),

                        // Section 1: Sales Performance KPI Cards
                        _buildSalesKpiCards(),
                        const SizedBox(height: 18),

                        // Section 2: Top Products by Revenue
                        _buildTopProductsSection(
                          title: '🏆 Top Products by Revenue / በገቢ ቀዳሚ',
                          items: _salesReport?.topByRevenue ?? [],
                          isRevenue: true,
                        ),
                        const SizedBox(height: 16),

                        // Section 3: Top Products by Quantity
                        _buildTopProductsSection(
                          title: '📦 Top Products by Quantity / በብዛት ቀዳሚ',
                          items: _salesReport?.topByQuantity ?? [],
                          isRevenue: false,
                        ),
                        const SizedBox(height: 18),

                        // Section 4: Stock Valuation Report
                        _buildStockValuationCard(),
                        const SizedBox(height: 24),
                      ],
                    ),
                  ),
          ),
        ],
      ),
    );
  }

  Widget _filterChip(String label, ReportRange range) {
    final isSelected = _selectedRange == range;
    return ChoiceChip(
      label: Text(label),
      selected: isSelected,
      onSelected: (selected) {
        if (selected) {
          setState(() => _selectedRange = range);
          _loadReport();
        }
      },
    );
  }

  Widget _buildSalesKpiCards() {
    final report = _salesReport;
    final revCents = report?.totalRevenueCents ?? 0;
    final count = report?.salesCount ?? 0;
    final profitCents = report?.estimatedProfitCents;

    return Column(
      children: [
        Row(
          children: [
            // Revenue Card
            Expanded(
              child: Card(
                elevation: 1,
                color: Colors.green.shade50,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Total Revenue / ጠቅላላ ገቢ',
                        style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.green.shade900),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        MoneyFormatter.formatCents(revCents),
                        style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Colors.green.shade900),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '$count items sold',
                        style: TextStyle(fontSize: 11, color: Colors.green.shade700),
                      ),
                    ],
                  ),
                ),
              ),
            ),
            const SizedBox(width: 10),

            // Estimated Profit Card
            Expanded(
              child: Card(
                elevation: 1,
                color: profitCents != null ? Colors.blue.shade50 : Colors.grey.shade100,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Estimated Profit / ትርፍ',
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.bold,
                          color: profitCents != null ? Colors.blue.shade900 : Colors.grey.shade800,
                        ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        profitCents != null ? MoneyFormatter.formatCents(profitCents) : 'N/A',
                        style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                          color: profitCents != null ? Colors.blue.shade900 : Colors.grey.shade600,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        profitCents != null
                            ? '${report?.itemsWithCostCount ?? 0} items with cost'
                            : 'Set cost price to see',
                        style: TextStyle(
                          fontSize: 10.5,
                          color: profitCents != null ? Colors.blue.shade700 : Colors.grey.shade600,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildTopProductsSection({
    required String title,
    required List<TopProductItem> items,
    required bool isRevenue,
  }) {
    return Card(
      elevation: 1,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title,
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
            ),
            const SizedBox(height: 12),
            if (items.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 8),
                child: Text('No sales recorded in this period.', style: TextStyle(color: Colors.grey, fontSize: 13)),
              )
            else
              ListView.separated(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: items.length,
                separatorBuilder: (_, __) => const Divider(height: 10),
                itemBuilder: (context, index) {
                  final item = items[index];
                  final rank = index + 1;
                  final qtyFormatted = QuantityFormatter.formatDisplay(item.totalQtyMilli);
                  final revFormatted = MoneyFormatter.formatCents(item.totalRevenueCents);

                  return Row(
                    children: [
                      CircleAvatar(
                        radius: 12,
                        backgroundColor: rank == 1
                            ? Colors.amber.shade200
                            : (rank == 2 ? Colors.grey.shade300 : const Color(0xFF0F766E).withValues(alpha: 0.15)),
                        child: Text(
                          '$rank',
                          style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.black87),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          item.productName,
                          style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5),
                        ),
                      ),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text(
                            isRevenue ? revFormatted : '$qtyFormatted ${item.unit}',
                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5),
                          ),
                          Text(
                            isRevenue ? '$qtyFormatted ${item.unit}' : revFormatted,
                            style: const TextStyle(fontSize: 11, color: Colors.grey),
                          ),
                        ],
                      ),
                    ],
                  );
                },
              ),
          ],
        ),
      ),
    );
  }

  Widget _buildStockValuationCard() {
    final stock = _stockReport;
    final totalCost = stock?.totalValueCostCents ?? 0;
    final totalSell = stock?.totalValueSellCents ?? 0;
    final lowCount = stock?.lowStockCount ?? 0;
    final outCount = stock?.outOfStockCount ?? 0;
    final totalCount = stock?.totalProductsCount ?? 0;

    return Card(
      elevation: 1,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(
              children: [
                Icon(Icons.inventory_2_outlined, size: 20, color: Color(0xFF0F766E)),
                SizedBox(width: 8),
                Text(
                  'Inventory Stock Valuation / የስቶክ ዋጋ',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                ),
              ],
            ),
            const SizedBox(height: 14),

            // Values
            Row(
              children: [
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.grey.shade50,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: Colors.grey.shade200),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'Total Value (Cost) / በመግዣ',
                          style: TextStyle(fontSize: 11, color: Colors.grey),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          MoneyFormatter.formatCents(totalCost),
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.deepOrange.shade50,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: Colors.deepOrange.shade100),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Total Value (Retail) / በመሸጫ',
                          style: TextStyle(fontSize: 11, color: Colors.deepOrange.shade900),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          MoneyFormatter.formatCents(totalSell),
                          style: TextStyle(
                            fontWeight: FontWeight.bold,
                            fontSize: 15,
                            color: Colors.deepOrange.shade900,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),

            // Breakdown
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  'Total Products: $totalCount',
                  style: const TextStyle(fontSize: 12, color: Colors.grey),
                ),
                if (lowCount > 0)
                  Text(
                    '⚠️ $lowCount low stock',
                    style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.amber.shade900),
                  ),
                if (outCount > 0)
                  Text(
                    '🔴 $outCount out of stock',
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.red),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
