import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../database/app_database.dart';
import '../../repositories/ledger_repository.dart';
import '../../services/demo_data_service.dart';
import '../../services/export_service.dart';
import '../../utils/money_formatter.dart';
import '../../utils/quantity_formatter.dart';
import '../widgets/empty_state_widget.dart';

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

  bool _locationsEnabled = false;
  List<Location> _locations = [];
  String? _selectedLocationId; // null = all locations
  List<StockByLocationReportData>? _stockByLocationReport;
  List<InTransitTransfer> _inTransitTransfers = [];
  List<TransferDiscrepancy> _discrepancies = [];

  SalesReportData? _salesReport;
  StockReportData? _stockReport;
  Map<String, SalesByKindData> _salesByKind = {};
  List<MaterialUsedReportItem> _materialsUsed = [];

  Map<String, int> _receivedByMethod = {};
  Map<String, int> _receivedByProvider = {};
  int _cashInDrawer = 0;
  Map<String, int> _revenueVsReceivedVsNewDube = {};
  int _outstandingDubeTotal = 0;
  int _overdueDubeCount = 0;

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

    final locEnabled = await widget.repository.getLocationsEnabled();
    List<Location> locs = [];
    List<StockByLocationReportData>? stockByLoc;
    List<InTransitTransfer> inTransit = [];
    List<TransferDiscrepancy> disc = [];

    if (locEnabled) {
      locs = await widget.repository.listLocations();
      stockByLoc = await widget.repository.getStockByLocationReport();
      inTransit = await widget.repository.getInTransitTransfers();
      disc = await widget.repository.getDiscrepancies();
    }

    final sales = await widget.repository.getSalesReport(
      fromMs: fromMs,
      toMs: toMs,
      locationId: _selectedLocationId,
    );
    final stock = await widget.repository.getStockReport(
      locationId: _selectedLocationId,
    );
    final salesByKind = await widget.repository.getSalesByKind(
      fromMs: fromMs,
      toMs: toMs,
      locationId: _selectedLocationId,
    );
    final matUsed = await widget.repository.getMaterialsUsed(
      fromMs: fromMs,
      toMs: toMs,
      locationId: _selectedLocationId,
    );

    final recMethod = await widget.repository.getReceivedTodayByMethod();
    final recProvider = await widget.repository.getReceivedTodayByProvider();
    final cashDrawer = await widget.repository.getCashExpectedInDrawer();
    final revVsRec = await widget.repository.getRevenueVsReceivedVsNewDubeToday();
    final outDube = await widget.repository.getOutstandingDubeTotal();
    final overdueCount = await widget.repository.getOverdueCount();

    if (mounted) {
      setState(() {
        _locationsEnabled = locEnabled;
        _locations = locs;
        _stockByLocationReport = stockByLoc;
        _inTransitTransfers = inTransit;
        _discrepancies = disc;
        _salesReport = sales;
        _stockReport = stock;
        _salesByKind = salesByKind;
        _materialsUsed = matUsed;
        _receivedByMethod = recMethod;
        _receivedByProvider = recProvider;
        _cashInDrawer = cashDrawer;
        _revenueVsReceivedVsNewDube = revVsRec;
        _outstandingDubeTotal = outDube;
        _overdueDubeCount = overdueCount;
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
    final file = await _exportService.exportSalesCsv(
      fromMs: fromMs,
      toMs: toMs,
      locationId: _selectedLocationId,
    );
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

          if (_locationsEnabled) ...[
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              child: Row(
                children: [
                  const Icon(Icons.location_on_outlined, size: 18, color: Color(0xFF0F766E)),
                  const SizedBox(width: 8),
                  const Text('ቦታ / Location:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                  const SizedBox(width: 12),
                  Expanded(
                    child: DropdownButton<String?>(
                      isExpanded: true,
                      value: _selectedLocationId,
                      underline: const SizedBox(),
                      items: [
                        const DropdownMenuItem<String?>(
                          value: null,
                          child: Text('ሁሉም ቦታዎች (All Locations)', style: TextStyle(fontSize: 13)),
                        ),
                        ..._locations.map((l) => DropdownMenuItem<String?>(
                          value: l.id,
                          child: Text(l.name, style: const TextStyle(fontSize: 13)),
                        )),
                      ],
                      onChanged: (val) {
                        setState(() => _selectedLocationId = val);
                        _loadReport();
                      },
                    ),
                  ),
                ],
              ),
            ),
            const Divider(height: 1),
          ],

          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : ((_salesReport?.salesCount ?? 0) == 0 && (_stockReport?.totalProductsCount ?? 0) == 0)
                    ? EmptyStateWidget(
                        icon: Icons.insights_outlined,
                        title: 'ሪፖርት የሚሰራ ዳታ የለም / No Report Data',
                        description:
                            'ሽያጭ በማካሄድ ወይም ዕቃዎችን በማስገባት የእለት፣ የሳምንት እና የወር የንግድ ሪፖርቶችዎን እዚህ ይመልከቱ።\nStart selling or adding products to view business performance reports.',
                        primaryButtonLabel: 'ወደ ኋላ ተመለስ / Go Back',
                        primaryButtonIcon: Icons.arrow_back,
                        onPrimaryPressed: () => Navigator.of(context).pop(),
                        secondaryButtonLabel: 'የሙከራ ዕቃዎች ጫን / Load Demo Data',
                        secondaryButtonIcon: Icons.auto_awesome,
                        onSecondaryPressed: () async {
                          final demoService = DemoDataService(repository: widget.repository);
                          await demoService.loadDemoProducts();
                          await _loadReport();
                        },
                      )
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

                        // Section 1.5: Payments & Cash Drawer & Dube
                        _buildPaymentsAndDubeSection(),
                        const SizedBox(height: 18),

                        // Section 1.6: Sales by Type
                        _buildSalesByTypeSection(),
                        const SizedBox(height: 18),

                        // Section 1.7: Materials Used
                        _buildMaterialsUsedSection(),
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
                        const SizedBox(height: 18),

                        if (_locationsEnabled) ...[
                          // Section 5: Stock by Location
                          _buildStockByLocationCard(),
                          const SizedBox(height: 18),

                          // Section 6: In-Transit Transfers
                          _buildInTransitCard(),
                          const SizedBox(height: 18),

                          // Section 7: Discrepancies
                          _buildDiscrepanciesCard(),
                          const SizedBox(height: 18),
                        ],
                        const SizedBox(height: 12),
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
        if (profitCents != null) ...[
          const SizedBox(height: 6),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4),
            child: Text(
              '* Profit based on current raw material costs, not a historical cost snapshot / * ትርፉ አሁን ባለው የጥሬ ዕቃዎች መግዣ ዋጋ ላይ የተመሠረተ ነው።',
              style: TextStyle(fontSize: 10.5, fontStyle: FontStyle.italic, color: Colors.grey.shade600),
            ),
          ),
        ],
      ],
    );
  }

  Widget _buildSalesByTypeSection() {
    final stockRev = _salesByKind['stockable']?.totalRevenueCents ?? 0;
    final servRev = _salesByKind['service']?.totalRevenueCents ?? 0;
    final consRev = _salesByKind['consumable']?.totalRevenueCents ?? 0;
    final stockCount = _salesByKind['stockable']?.salesCount ?? 0;
    final servCount = _salesByKind['service']?.salesCount ?? 0;
    final consCount = _salesByKind['consumable']?.salesCount ?? 0;

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
                Icon(Icons.category_outlined, color: Color(0xFF0F766E), size: 20),
                SizedBox(width: 8),
                Text(
                  'Sales by Type / ሽያጭ በዓይነት',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: Colors.green.shade50,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('📦 Products', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
                        const SizedBox(height: 4),
                        Text(
                          MoneyFormatter.formatCents(stockRev),
                          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Colors.green.shade900),
                        ),
                        Text('$stockCount sold', style: TextStyle(fontSize: 11, color: Colors.grey.shade700)),
                      ],
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: Colors.blue.shade50,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('🛠 Services', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
                        const SizedBox(height: 4),
                        Text(
                          MoneyFormatter.formatCents(servRev),
                          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Colors.blue.shade900),
                        ),
                        Text('$servCount sold', style: TextStyle(fontSize: 11, color: Colors.grey.shade700)),
                      ],
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: Colors.purple.shade50,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('🧪 Consumables', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
                        const SizedBox(height: 4),
                        Text(
                          MoneyFormatter.formatCents(consRev),
                          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Colors.purple.shade900),
                        ),
                        Text('$consCount sold', style: TextStyle(fontSize: 11, color: Colors.grey.shade700)),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildMaterialsUsedSection() {
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
                Icon(Icons.inventory_2_outlined, color: Color(0xFF0F766E), size: 20),
                SizedBox(width: 8),
                Text(
                  'Materials Used / ጥቅም ላይ የዋሉ ጥሬ ዕቃዎች',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                ),
              ],
            ),
            const SizedBox(height: 12),
            if (_materialsUsed.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Text(
                  'No raw materials consumed in this period / በዚህ ወቅት ጥቅም ላይ የዋለ ጥሬ ዕቃ የለም',
                  style: TextStyle(color: Colors.grey.shade600, fontSize: 13),
                ),
              )
            else
              ListView.separated(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: _materialsUsed.length,
                separatorBuilder: (_, __) => const Divider(height: 12),
                itemBuilder: (context, idx) {
                  final mat = _materialsUsed[idx];
                  return Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Expanded(
                        child: Text(
                          mat.productName,
                          style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                        ),
                      ),
                      Text(
                        '${QuantityFormatter.formatDisplay(mat.totalConsumedMilli)} ${mat.unit}',
                        style: TextStyle(fontSize: 13, color: Colors.grey.shade800),
                      ),
                      const SizedBox(width: 16),
                      Text(
                        MoneyFormatter.formatCents(mat.totalCostCents),
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
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

  Widget _buildPaymentsAndDubeSection() {
    final cashRec = _receivedByMethod['cash'] ?? 0;
    final bankRec = _receivedByMethod['bank'] ?? 0;
    final walletRec = _receivedByMethod['wallet'] ?? 0;

    final rev = _revenueVsReceivedVsNewDube['revenue_cents'] ?? 0;
    final received = _revenueVsReceivedVsNewDube['money_received_cents'] ?? 0;
    final newDube = _revenueVsReceivedVsNewDube['new_dube_cents'] ?? 0;

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
                Icon(Icons.payments_outlined, color: Color(0xFF0F766E), size: 20),
                SizedBox(width: 8),
                Text(
                  'Payments & Cash Drawer / ክፍያዎች እና ካዝና',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                ),
              ],
            ),
            const SizedBox(height: 14),

            // Cash Drawer & Outstanding Dube
            Row(
              children: [
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.blue.shade50,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'Cash in Drawer (Net)',
                          style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.blue),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          MoneyFormatter.formatCents(_cashInDrawer),
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.blue.shade900),
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
                      color: Colors.amber.shade50,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'Outstanding Dube',
                          style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.brown),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          MoneyFormatter.formatCents(_outstandingDubeTotal),
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.amber.shade900),
                        ),
                        if (_overdueDubeCount > 0)
                          Text(
                            '$_overdueDubeCount overdue',
                            style: const TextStyle(fontSize: 10.5, color: Colors.red, fontWeight: FontWeight.bold),
                          ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 14),

            // Methods Breakdown
            const Text('Received Today by Method:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
            const SizedBox(height: 6),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text('💵 Cash: ${MoneyFormatter.formatCents(cashRec)}'),
                Text('🏦 Bank: ${MoneyFormatter.formatCents(bankRec)}'),
                Text('📱 Wallet: ${MoneyFormatter.formatCents(walletRec)}'),
              ],
            ),

            if (_receivedByProvider.isNotEmpty) ...[
              const SizedBox(height: 10),
              const Text('By Bank / Wallet Provider:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
              const SizedBox(height: 4),
              Wrap(
                spacing: 8,
                runSpacing: 4,
                children: _receivedByProvider.entries.map((e) {
                  return Chip(
                    visualDensity: VisualDensity.compact,
                    label: Text('${e.key}: ${MoneyFormatter.formatCents(e.value)}', style: const TextStyle(fontSize: 11)),
                  );
                }).toList(),
              ),
            ],

            const Divider(height: 20),

            // Comparison: Revenue vs Received vs New Dube
            const Text('Today: Revenue vs Received vs New Dube:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
            const SizedBox(height: 6),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: [
                _buildSummaryCol('Sales Revenue', rev, Colors.black87),
                _buildSummaryCol('Money Received', received, Colors.green.shade800),
                _buildSummaryCol('New Dube', newDube, Colors.deepOrange.shade800),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildSummaryCol(String label, int cents, Color color) {
    return Column(
      children: [
        Text(label, style: const TextStyle(fontSize: 11, color: Colors.grey)),
        const SizedBox(height: 2),
        Text(MoneyFormatter.formatCents(cents), style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.bold, color: color)),
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

  Widget _buildStockByLocationCard() {
    final report = _stockByLocationReport ?? [];

    // Aggregate stock by location
    final locTotals = <String, ({String kind, int unitsMilli})>{};
    for (final item in report) {
      for (final loc in item.locations) {
        final existing = locTotals[loc.locationName];
        locTotals[loc.locationName] = (
          kind: loc.locationKind,
          unitsMilli: (existing?.unitsMilli ?? 0) + loc.stockMilli,
        );
      }
    }

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
                Icon(Icons.warehouse_rounded, size: 20, color: Color(0xFF0F766E)),
                SizedBox(width: 8),
                Text(
                  'Stock by Location / የስቶክ ክፍፍል በቦታ',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                ),
              ],
            ),
            const SizedBox(height: 12),
            if (locTotals.isEmpty)
              const Text('No location stock data available.', style: TextStyle(color: Colors.grey, fontSize: 13))
            else
              ...locTotals.entries.map((e) {
                final isWarehouse = e.value.kind == 'warehouse';
                return Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Row(
                    children: [
                      Icon(
                        isWarehouse ? Icons.warehouse_outlined : Icons.storefront_outlined,
                        color: const Color(0xFF0F766E),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          e.key,
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                        ),
                      ),
                      Text(
                        '${QuantityFormatter.formatDisplay(e.value.unitsMilli)} units',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: Color(0xFF0F766E)),
                      ),
                    ],
                  ),
                );
              }),
          ],
        ),
      ),
    );
  }

  Widget _buildInTransitCard() {
    final inTransit = _inTransitTransfers;

    return Card(
      elevation: 1,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.local_shipping_outlined, size: 20, color: Color(0xFF0F766E)),
                const SizedBox(width: 8),
                const Text(
                  'In Transit / በመንገድ ላይ ያሉ ዝውውሮች',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                ),
                const Spacer(),
                if (inTransit.isNotEmpty)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(
                      color: Colors.amber.shade100,
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(
                      '${inTransit.length}',
                      style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.amber.shade900),
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 12),
            if (inTransit.isEmpty)
              const Text(
                'No shipments currently in transit / ምንም በመንገድ ላይ ያለ ዝውውር የለም',
                style: TextStyle(color: Colors.grey, fontSize: 13),
              )
            else
              ListView.separated(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: inTransit.length,
                separatorBuilder: (_, __) => const Divider(height: 12),
                itemBuilder: (context, index) {
                  final t = inTransit[index];
                  final timeStr = DateFormat('MMM d, HH:mm').format(
                    DateTime.fromMillisecondsSinceEpoch(t.sentAtMs),
                  );

                  return Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              t.productName,
                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5),
                            ),
                            Text(
                              '${t.fromLocationName} ➔ ${t.toLocationName} • $timeStr',
                              style: const TextStyle(fontSize: 11.5, color: Colors.grey),
                            ),
                          ],
                        ),
                      ),
                      Text(
                        '${QuantityFormatter.formatDisplay(t.qtyMilli)} ${t.unit}',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5, color: Color(0xFF0F766E)),
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

  Widget _buildDiscrepanciesCard() {
    final disc = _discrepancies;

    return Card(
      elevation: 1,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.warning_amber_rounded, size: 20, color: disc.isNotEmpty ? Colors.amber.shade900 : Colors.grey),
                const SizedBox(width: 8),
                const Text(
                  'Transfer Discrepancies / የዝውውር ልዩነቶች',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                ),
                const Spacer(),
                if (disc.isNotEmpty)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(
                      color: Colors.amber.shade100,
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(
                      '${disc.length}',
                      style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.amber.shade900),
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 12),
            if (disc.isEmpty)
              const Text(
                'No discrepancies reported / ምንም የዝውውር ልዩነት አልተመዘገበም',
                style: TextStyle(color: Colors.grey, fontSize: 13),
              )
            else
              ListView.separated(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: disc.length,
                separatorBuilder: (_, __) => const Divider(height: 12),
                itemBuilder: (context, index) {
                  final d = disc[index];
                  final diff = d.diffMilli;
                  return Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              d.productName,
                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5),
                            ),
                            Text(
                              'Sent: ${QuantityFormatter.formatDisplay(d.sentQtyMilli)} • Recv: ${QuantityFormatter.formatDisplay(d.receivedQtyMilli)}',
                              style: const TextStyle(fontSize: 11.5, color: Colors.grey),
                            ),
                            if (d.reason != null && d.reason!.isNotEmpty)
                              Text(
                                'Reason: ${d.reason}',
                                style: TextStyle(fontSize: 11, fontStyle: FontStyle.italic, color: Colors.grey.shade700),
                              ),
                          ],
                        ),
                      ),
                      Text(
                        '${diff > 0 ? "+" : ""}${QuantityFormatter.formatDisplay(diff)} ${d.unit}',
                        style: TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 13,
                          color: diff < 0 ? Colors.red.shade800 : Colors.orange.shade800,
                        ),
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
}
