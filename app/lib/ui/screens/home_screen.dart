import 'package:flutter/material.dart';

import '../../database/app_database.dart';
import '../../main.dart';
import '../../repositories/ledger_repository.dart';
import '../../services/api_client.dart';
import '../../services/app_update_service.dart';
import '../../services/auth_storage.dart';
import '../../services/backup_service.dart';
import '../../services/licensing/clock_service.dart';
import '../../services/licensing/install_id_service.dart';
import '../../services/licensing/license_gate.dart';
import '../../services/sync_engine.dart';
import '../../utils/money_formatter.dart';
import '../theme.dart';
import '../widgets/instant_sale_sheet.dart';
import '../widgets/license_banner.dart';
import '../widgets/location_selector_chip.dart';
import '../widgets/sync_status_header.dart';
import '../widgets/warehouse_setup_sheet.dart';
import 'adjust_screen.dart';
import 'dube_screen.dart';
import 'history_screen.dart';
import 'income_expense_screen.dart';
import 'license_screen.dart';
import 'products_screen.dart';
import 'reports_screen.dart';
import 'sale_screen.dart';
import 'settings_screen.dart';
import 'stock_in_screen.dart';
import 'transfer_screen.dart';

class HomeScreen extends StatefulWidget {
  final AppDatabase db;
  final LedgerRepository repository;
  final ApiClient apiClient;
  final AuthStorage authStorage;
  final SyncEngine syncEngine;
  final AppUpdateService? updateService;
  final LicenseGate? licenseGate;
  final ClockService? clockService;
  final InstallIdService? installIdService;

  const HomeScreen({
    super.key,
    required this.db,
    required this.repository,
    required this.apiClient,
    required this.authStorage,
    required this.syncEngine,
    this.updateService,
    this.licenseGate,
    this.clockService,
    this.installIdService,
  });

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver {
  late final BackupService _backupService;
  String _shopName = 'Inventory';
  String _language = 'am';
  bool _langOmEnabled = false;
  bool _langAmEnabled = true;
  bool _locationsEnabled = false;
  bool _locationsPromptDismissed = false;
  bool _isOnline = false;
  int? _lastBackupMs;
  bool _isBackupOverdue = false;
  AppUpdateInfo? _updateInfo;
  bool _updateBannerDismissed = false;

  String _t({required String en, required String am, required String om}) {
    if (_language == 'om') return om;
    if (_language == 'am') return am;
    return en;
  }

  @override
  void initState() {
    super.initState();
    _backupService = BackupService(db: widget.db, repository: widget.repository);
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
    if (widget.licenseGate != null) {
      await widget.licenseGate!.reevaluate();
    }
    final name = await widget.repository.getShopName();
    final lang = await widget.repository.getLanguage();
    final langOm = await widget.repository.isOromoEnabled();
    final langAm = await widget.repository.isAmharicEnabled();
    final locEnabled = await widget.repository.getLocationsEnabled();
    final promptDismissed = await widget.repository.getLocationsPromptDismissed();
    final isOnline = await widget.authStorage.isOnlineEnabled();
    final lastBackup = await widget.repository.getLastBackupMs();
    final isOverdue = await _backupService.isBackupOverdue();
    AppUpdateInfo? updateInfo;
    if (widget.updateService != null) {
      updateInfo = await widget.updateService!.checkForUpdate();
    }

    if (mounted) {
      setState(() {
        _shopName = name;
        _language = lang;
        _langOmEnabled = langOm;
        _langAmEnabled = langAm;
        _locationsEnabled = locEnabled;
        _locationsPromptDismissed = promptDismissed;
        _isOnline = isOnline;
        _lastBackupMs = lastBackup;
        _isBackupOverdue = isOverdue;
        if (updateInfo != null) {
          _updateInfo = updateInfo;
        }
      });
    }
  }

  Future<void> _shareBackupNow() async {
    try {
      final file = await _backupService.createBackupFile();
      await _backupService.shareBackup(file);
      await _loadShopInfo();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error creating backup: $e'), backgroundColor: Colors.red),
        );
      }
    }
  }

  String _formatDaysAgo(int timestampMs) {
    final diff = DateTime.now().difference(DateTime.fromMillisecondsSinceEpoch(timestampMs));
    if (diff.inDays <= 0) {
      if (diff.inHours <= 1) return _t(en: 'Just now', am: 'ቅድም ትንሽ ሰዓት', om: 'Amma dura');
      return _t(en: 'Today', am: 'ዛሬ', om: "Har'a");
    } else if (diff.inDays == 1) {
      return _t(en: 'Yesterday', am: 'ትናንት', om: 'Kaleessa');
    } else {
      return _t(en: '${diff.inDays} days ago', am: 'ከ ${diff.inDays} ቀናት በፊት', om: 'Guyyoota ${diff.inDays} dura');
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
            Row(
              children: [
                Flexible(
                  child: Text(
                    _shopName,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
                  ),
                ),
                if (_locationsEnabled) ...[
                  const SizedBox(width: 8),
                  LocationSelectorChip(
                    repository: widget.repository,
                    onLocationChanged: (_) => _notifyMutation(),
                  ),
                ],
              ],
            ),
            Row(
              children: [
                Text(
                  _t(en: 'POS & Inventory', am: 'የቀን መዝገብ', om: 'Galmee Guyyaa'),
                  style: TextStyle(fontSize: 11, color: Theme.of(context).colorScheme.onSurfaceVariant),
                ),
                const SizedBox(width: 8),
                Builder(builder: (ctx) {
                  final isDark = Theme.of(ctx).brightness == Brightness.dark;
                  final Color pillBg;
                  final Color pillBorder;
                  final Color pillFg;
                  if (_isOnline) {
                    final tokens = AppColors.successTokens(isDark);
                    pillBg = tokens.bg;
                    pillBorder = tokens.border;
                    pillFg = tokens.fg;
                  } else {
                    final tokens = AppColors.neutralTokens(isDark);
                    pillBg = tokens.bg;
                    pillBorder = tokens.border;
                    pillFg = tokens.fg;
                  }
                  return Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1.5),
                    decoration: BoxDecoration(
                      color: pillBg,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(
                        color: pillBorder,
                        width: 0.6,
                      ),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                          _isOnline ? Icons.cloud_done_rounded : Icons.offline_pin_rounded,
                          size: 11,
                          color: pillFg,
                        ),
                        const SizedBox(width: 3),
                        Text(
                          _isOnline ? _t(en: 'Online', am: 'ኦንላይን', om: 'Toora Irra') : _t(en: 'Offline', am: 'ስልክ ላይ', om: 'Bilbila Irra'),
                          style: TextStyle(
                            fontSize: 9.5,
                            fontWeight: FontWeight.bold,
                            color: pillFg,
                          ),
                        ),
                      ],
                    ),
                  );
                }),
              ],
            ),
          ],
        ),
        actions: [
          Builder(
            builder: (ctx) {
              final isDark = Theme.of(ctx).brightness == Brightness.dark;
              final Color langColor = isDark ? const Color(0xFF14B8A6) : const Color(0xFF0F766E);
              final activeLabel = _language == 'om'
                  ? 'ORO'
                  : (_language == 'am' ? 'አማ' : 'EN');
              return IconButton(
                tooltip: _t(en: 'Change Language', am: 'ቋንቋ ቀይር', om: 'Afaan Jijjiiri'),
                visualDensity: VisualDensity.compact,
                icon: CircleAvatar(
                  radius: 13,
                  backgroundColor: langColor.withValues(alpha: 0.15),
                  child: Text(
                    activeLabel,
                    style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: langColor),
                  ),
                ),
                onPressed: () async {
                  final enabledLangs = <String>[
                    if (_langAmEnabled) 'am',
                    if (_langOmEnabled) 'om',
                    'en',
                  ];
                  if (enabledLangs.isEmpty) enabledLangs.add('en');
                  final curIdx = enabledLangs.indexOf(_language);
                  final nextLang = enabledLangs[(curIdx + 1) % enabledLangs.length];
                  await widget.repository.setLanguage(nextLang);
                  if (context.mounted) {
                    InventoryApp.of(context)?.setLocale(nextLang);
                  }
                  await _loadShopInfo();
                },
              );
            },
          ),
          Builder(
            builder: (ctx) {
              final isDark = Theme.of(ctx).brightness == Brightness.dark;
              final Color themeColor = isDark ? const Color(0xFFFBBF24) : const Color(0xFF0F766E);
              return IconButton(
                tooltip: isDark
                    ? _t(en: 'Switch to Light Mode', am: 'ወደ ብርሃን ገጽታ ቀይር', om: 'Gara Bifa Ifaatti Jijjiiri')
                    : _t(en: 'Switch to Dark Mode', am: 'ወደ ጨለማ ገጽታ ቀይር', om: 'Gara Bifa Dukkanaatti Jijjiiri'),
                visualDensity: VisualDensity.compact,
                icon: CircleAvatar(
                  radius: 13,
                  backgroundColor: themeColor.withValues(alpha: 0.15),
                  child: Icon(
                    isDark ? Icons.light_mode_rounded : Icons.dark_mode_rounded,
                    size: 15,
                    color: themeColor,
                  ),
                ),
                onPressed: () async {
                  final nextMode = isDark ? 'light' : 'dark';
                  await widget.repository.setThemeMode(nextMode);
                  if (ctx.mounted) {
                    InventoryApp.of(ctx)?.setThemeMode(
                      isDark ? ThemeMode.light : ThemeMode.dark,
                    );
                  }
                },
              );
            },
          ),
          IconButton(
            icon: const Icon(Icons.analytics_outlined),
            tooltip: _t(en: 'Reports', am: 'ሪፖርቶች', om: 'Gabaasa'),
            visualDensity: VisualDensity.compact,
            onPressed: () {
              Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (ctx) => ReportsScreen(repository: widget.repository),
                ),
              );
            },
          ),
          IconButton(
            icon: const Icon(Icons.settings_outlined),
            tooltip: _t(en: 'Settings', am: 'ቅንብሮች', om: "Qindaa'ina"),
            visualDensity: VisualDensity.compact,
            onPressed: () async {
              await Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (ctx) => SettingsScreen(
                    apiClient: widget.apiClient,
                    authStorage: widget.authStorage,
                    syncEngine: widget.syncEngine,
                    repository: widget.repository,
                    db: widget.db,
                    licenseGate: widget.licenseGate,
                    installIdService: widget.installIdService,
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
                if (widget.licenseGate != null && widget.clockService != null) ...[
                  LicenseBanner(
                    licenseGate: widget.licenseGate!,
                    clockService: widget.clockService!,
                    isAm: isAm,
                    onVerifyOnline: () async {
                      await widget.syncEngine.syncNow();
                      await widget.licenseGate?.reevaluate();
                      if (mounted) setState(() {});
                    },
                    onOpenLicenseScreen: () {
                      if (widget.installIdService != null) {
                        Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (ctx) => LicenseScreen(
                              licenseGate: widget.licenseGate!,
                              installIdService: widget.installIdService!,
                              repository: widget.repository,
                              syncEngine: widget.syncEngine,
                            ),
                          ),
                        );
                      }
                    },
                  ),
                ],
                // Non-blocking App Update Banner
                if (_updateInfo != null && _updateInfo!.isUpdateAvailable && !_updateBannerDismissed) ...[
                  Card(
                    elevation: 1,
                    color: _updateInfo!.isSyncBlocked ? const Color(0xFFFEF2F2) : const Color(0xFFF0FDFA),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12),
                      side: BorderSide(
                        color: _updateInfo!.isSyncBlocked ? const Color(0xFFEF4444) : const Color(0xFF0F766E),
                        width: 1.2,
                      ),
                    ),
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Icon(
                                _updateInfo!.isSyncBlocked ? Icons.sync_disabled_rounded : Icons.system_update_rounded,
                                color: _updateInfo!.isSyncBlocked ? const Color(0xFFDC2626) : const Color(0xFF0F766E),
                                size: 22,
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  isAm
                                      ? 'አዲስ ስሪት አለ (v${_updateInfo!.latest})'
                                      : 'Update Available (v${_updateInfo!.latest})',
                                  style: TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 14,
                                    color: _updateInfo!.isSyncBlocked ? const Color(0xFF991B1B) : const Color(0xFF0F766E),
                                  ),
                                ),
                              ),
                              IconButton(
                                icon: const Icon(Icons.close_rounded, size: 18),
                                padding: EdgeInsets.zero,
                                constraints: const BoxConstraints(),
                                tooltip: _t(en: 'Dismiss', am: 'ዝጋ', om: 'Cufi'),
                                onPressed: () {
                                  setState(() {
                                    _updateBannerDismissed = true;
                                  });
                                },
                              ),
                            ],
                          ),
                          const SizedBox(height: 6),
                          Text(
                            _updateInfo!.isSyncBlocked
                                ? (isAm
                                    ? 'የመስመር ላይ ማመሳሰል እንዲቀጥል መተግበሪያውን ማዘመን ያስፈልጋል። ከመስመር ውጭ የሱቅ ስራዎችዎ ያለምንም ችግር መስራታቸውን ይቀጥላሉ።'
                                    : 'Online sync is paused until you update (v${_updateInfo!.minSupported} required). Local offline sales and inventory continue working normally.')
                                : ((isAm && _updateInfo!.notesAm.isNotEmpty)
                                    ? _updateInfo!.notesAm
                                    : (_updateInfo!.notesEn.isNotEmpty
                                        ? _updateInfo!.notesEn
                                        : (isAm
                                            ? 'አዳዲስ ገጽታዎች እና ማሻሻያዎች ተካተዋል።'
                                            : 'New features, improvements, and bug fixes are available.'))),
                            style: TextStyle(
                              fontSize: 12.5,
                              color: _updateInfo!.isSyncBlocked ? const Color(0xFF7F1D1D) : const Color(0xFF134E4A),
                              height: 1.3,
                            ),
                          ),
                          const SizedBox(height: 10),
                          SizedBox(
                            width: double.infinity,
                            child: FilledButton.icon(
                              style: FilledButton.styleFrom(
                                backgroundColor: _updateInfo!.isSyncBlocked
                                    ? const Color(0xFFDC2626)
                                    : const Color(0xFF0F766E),
                                minimumSize: const Size(double.infinity, 42),
                              ),
                              onPressed: () {
                                widget.updateService?.openDownloadPage(_updateInfo!.downloadUrl);
                              },
                              icon: const Icon(Icons.download_rounded, size: 18),
                              label: Text(
                                _t(en: 'Download Update Now', am: 'አሁኑኑ አዘምን', om: 'Amma Haaromsi'),
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 10),
                ],
                // Backup Nudge for offline-only owners
                if (!_isOnline) ...[
                  if (_isBackupOverdue) ...[
                    Card(
                      elevation: 1,
                      color: const Color(0xFFFEF3C7),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                        side: const BorderSide(color: Color(0xFFF59E0B), width: 1.2),
                      ),
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                const Icon(Icons.warning_amber_rounded, color: Color(0xFFB45309), size: 22),
                                const SizedBox(width: 8),
                                Expanded(
                                  child: Text(
                                    _t(en: 'Lost phone means lost data!', am: 'ስልክዎ ቢጠፋ መረጃዎ እንዳይጠፋ!', om: 'Bilbilli yoo bade daataan akka hin banne!'),
                                    style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 14,
                                      color: Color(0xFF92400E),
                                    ),
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 6),
                            Text(
                              _lastBackupMs == null
                                  ? (isAm
                                      ? 'እስካሁን ምንም ኮፒ አልተወሰደም። ስልክዎ ቢበላሽ ወይም ቢጠፋ የሱቅዎ መረጃ በሙሉ ይጠፋል።'
                                      : 'You have never backed up your data! If this phone is lost or damaged, all records will be gone.')
                                  : (isAm
                                      ? 'የመጨረሻ ኮፒ የተወሰደው ${_formatDaysAgo(_lastBackupMs!)} ነው። አሁኑኑ አዲስ ኮፒ ወደ ሌላ ቦታ ይላኩ።'
                                      : 'Last backup was ${_formatDaysAgo(_lastBackupMs!)}. Back up now to Telegram or cloud storage.'),
                              style: const TextStyle(fontSize: 12.5, color: Color(0xFF78350F), height: 1.3),
                            ),
                            const SizedBox(height: 10),
                            SizedBox(
                              width: double.infinity,
                              child: FilledButton.icon(
                                style: FilledButton.styleFrom(
                                  backgroundColor: const Color(0xFF0F766E),
                                  minimumSize: const Size(double.infinity, 44),
                                ),
                                onPressed: _shareBackupNow,
                                icon: const Icon(Icons.share_rounded, size: 18),
                                label: Text(
                                  _t(en: 'Back Up & Share Now', am: 'አሁኑኑ ኮፒ አድርገው ያጋሩ', om: 'Amma Koppii Taasisaa'),
                                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 10),
                  ] else if (_lastBackupMs != null) ...[
                    Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: Row(
                        children: [
                          const Icon(Icons.shield_outlined, size: 15, color: Color(0xFF0F766E)),
                          const SizedBox(width: 6),
                          Text(
                            isAm
                                ? 'መረጃዎ ተጠብቋል፡ የመጨረሻ ኮፒ ${_formatDaysAgo(_lastBackupMs!)}'
                                : 'Data safe: Last backed up ${_formatDaysAgo(_lastBackupMs!)}',
                            style: TextStyle(fontSize: 12, color: Theme.of(context).colorScheme.onSurfaceVariant),
                          ),
                          const Spacer(),
                          InkWell(
                            onTap: _shareBackupNow,
                            child: Text(
                              _t(en: 'Back up now', am: 'አዲስ ኮፒ ውሰድ', om: 'Amma koppii godhi'),
                              style: const TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                                color: Color(0xFF0F766E),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ],
                // Dismissible Home Card: Ask about warehouse & store for existing installs
                if (!_locationsEnabled && !_locationsPromptDismissed) ...[
                  Card(
                    elevation: 2,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    color: const Color(0xFF0F766E).withValues(alpha: 0.08),
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              const Icon(Icons.warehouse_rounded, color: Color(0xFF0F766E), size: 24),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  _t(en: 'Keep stock in more than one place?', am: 'ዕቃ ከአንድ ቦታ በላይ ያስቀምጣሉ?', om: 'Meeshaa iddoo tokkoo olitti kuustuu?'),
                                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 6),
                          Text(
                            isAm
                                ? 'ለሱቅ እና ለመጋዘን የተለያዩ ስቶኮችን መያዝ እና ማዘዋወር ይችላሉ።'
                                : 'Separate stock for store front and warehouse, with offline transfers.',
                            style: TextStyle(
                              fontSize: 12.5,
                              color: Theme.of(context).colorScheme.onSurfaceVariant,
                            ),
                          ),
                          const SizedBox(height: 10),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.end,
                            children: [
                              TextButton(
                                onPressed: () async {
                                  await widget.repository.setLocationsPromptDismissed(true);
                                  await _loadShopInfo();
                                },
                                child: Text(
                                  _t(en: 'Not now', am: 'አሁን አይደለም', om: 'Amma miti'),
                                  style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant),
                                ),
                              ),
                              const SizedBox(width: 8),
                              FilledButton(
                                style: FilledButton.styleFrom(
                                  backgroundColor: const Color(0xFF0F766E),
                                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                                ),
                                onPressed: () {
                                  showWarehouseSetupSheet(
                                    context,
                                    repository: widget.repository,
                                    onComplete: _loadShopInfo,
                                  );
                                },
                                child: Text(_t(en: 'Set Up', am: 'አዎ፣ ጀምር', om: 'Eeyyee, Qopheessi')),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                ],

                // Top KPI Metrics (Today's Sales & Low Stock Count)
                Row(
                  children: [
                    // Today's Sales Total
                    Expanded(
                      child: StreamBuilder<int>(
                        stream: widget.repository.watchTodaySalesTotalCents(),
                        builder: (context, snapshot) {
                          final cents = snapshot.data ?? 0;
                          final isDark = Theme.of(context).brightness == Brightness.dark;
                          final tokens = AppColors.successTokens(isDark);
                          return Card(
                            elevation: isDark ? 0 : 1,
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12),
                              side: BorderSide(color: isDark ? tokens.border : AppColors.outlineLight, width: 0.8),
                            ),
                            color: tokens.bg,
                            child: InkWell(
                              borderRadius: BorderRadius.circular(12),
                              onTap: () {
                                Navigator.of(context).push(
                                  MaterialPageRoute(
                                    builder: (ctx) => HistoryScreen(
                                      repository: widget.repository,
                                      onMutation: _notifyMutation,
                                    ),
                                  ),
                                );
                              },
                              child: Padding(
                                padding: const EdgeInsets.all(14),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        Icon(Icons.point_of_sale_rounded, size: 18, color: tokens.fg),
                                        const SizedBox(width: 6),
                                        Expanded(
                                          child: Text(
                                            _t(en: "Today's Sales", am: 'የዛሬ ሽያጭ', om: "Gurgurtaa Har'aa"),
                                            style: TextStyle(
                                              fontWeight: FontWeight.bold,
                                              fontSize: 12,
                                              color: tokens.fg,
                                            ),
                                            overflow: TextOverflow.ellipsis,
                                          ),
                                        ),
                                        Icon(Icons.chevron_right_rounded, size: 16, color: tokens.fg.withValues(alpha: 0.6)),
                                      ],
                                    ),
                                    const SizedBox(height: 8),
                                    Text(
                                      MoneyFormatter.formatCents(cents),
                                      style: TextStyle(
                                        fontWeight: FontWeight.bold,
                                        fontSize: 18,
                                        color: tokens.fg,
                                      ),
                                    ),
                                  ],
                                ),
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
                          final isDark = Theme.of(context).brightness == Brightness.dark;
                          final tokens = isWarning ? AppColors.dangerTokens(isDark) : AppColors.neutralTokens(isDark);
                          return Card(
                            elevation: isDark ? 0 : 1,
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12),
                              side: BorderSide(color: isDark ? tokens.border : AppColors.outlineLight, width: 0.8),
                            ),
                            color: tokens.bg,
                            child: InkWell(
                              borderRadius: BorderRadius.circular(12),
                              onTap: () {
                                Navigator.of(context).push(
                                  MaterialPageRoute(
                                    builder: (ctx) => ProductsScreen(
                                      repository: widget.repository,
                                      onMutation: _notifyMutation,
                                      initialFilterLowStock: true,
                                    ),
                                  ),
                                );
                              },
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
                                          color: tokens.fg,
                                        ),
                                        const SizedBox(width: 6),
                                        Expanded(
                                          child: Text(
                                            _t(en: 'Low Stock', am: 'ያነሱ ዕቃዎች', om: "Meeshaalee Hir'atan"),
                                            style: TextStyle(
                                              fontWeight: FontWeight.bold,
                                              fontSize: 12,
                                              color: tokens.fg,
                                            ),
                                          ),
                                        ),
                                        Icon(
                                          Icons.chevron_right,
                                          size: 16,
                                          color: tokens.fg.withOpacity(0.7),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 8),
                                    Text(
                                      isWarning
                                          ? _t(en: '$count items', am: '$count ዕቃዎች', om: 'Meeshaalee $count')
                                          : _t(en: 'No low level stocks', am: 'ዝቅተኛ ክምችት የለም', om: "Meeshaan hir'ate hin jiru"),
                                      style: TextStyle(
                                        fontWeight: FontWeight.bold,
                                        fontSize: isWarning ? 18 : 13,
                                        color: tokens.fg,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          );
                        },
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),

                // Cash in Drawer & Outstanding Dube
                Row(
                  children: [
                    // Cash in Drawer
                    Expanded(
                      child: FutureBuilder<int>(
                        future: widget.repository.getCashExpectedInDrawer(),
                        builder: (context, snapshot) {
                          final cents = snapshot.data ?? 0;
                          final isDark = Theme.of(context).brightness == Brightness.dark;
                          final tokens = AppColors.infoTokens(isDark);
                          return Card(
                            elevation: isDark ? 0 : 1,
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12),
                              side: BorderSide(color: isDark ? tokens.border : AppColors.outlineLight, width: 0.8),
                            ),
                            color: tokens.bg,
                            child: Padding(
                              padding: const EdgeInsets.all(12),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    children: [
                                      Icon(Icons.point_of_sale, size: 16, color: tokens.fg),
                                      const SizedBox(width: 6),
                                      Text(
                                        _t(en: 'Cash in Drawer', am: 'ጥሬ ገንዘብ በካዝና', om: 'Qarshii Kaazinaa'),
                                        style: TextStyle(
                                          fontWeight: FontWeight.bold,
                                          fontSize: 11.5,
                                          color: tokens.fg,
                                        ),
                                      ),
                                    ],
                                  ),
                                  const SizedBox(height: 6),
                                  Text(
                                    MoneyFormatter.formatCents(cents),
                                    style: TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 16,
                                      color: tokens.fg,
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

                    // Outstanding Dube
                    Expanded(
                      child: InkWell(
                        borderRadius: BorderRadius.circular(12),
                        onTap: () {
                          Navigator.of(context).push(
                            MaterialPageRoute(
                              builder: (ctx) => DubeScreen(
                                repository: widget.repository,
                                onMutation: _notifyMutation,
                              ),
                            ),
                          );
                        },
                        child: StreamBuilder<int>(
                          stream: widget.repository.watchOutstandingDubeTotal(),
                          builder: (context, snapshot) {
                            final cents = snapshot.data ?? 0;
                            final isDark = Theme.of(context).brightness == Brightness.dark;
                            final tokens = AppColors.warningTokens(isDark);
                            return Card(
                              elevation: isDark ? 0 : 1,
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(12),
                                side: BorderSide(color: isDark ? tokens.border : AppColors.outlineLight, width: 0.8),
                              ),
                              color: tokens.bg,
                              child: Padding(
                                padding: const EdgeInsets.all(12),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        Icon(Icons.edit_note, size: 16, color: tokens.fg),
                                        const SizedBox(width: 6),
                                        Text(
                                          _t(en: 'Outstanding Dube', am: 'ጠቅላላ ዱቤ', om: 'Liqii Waliigalaa'),
                                          style: TextStyle(
                                            fontWeight: FontWeight.bold,
                                            fontSize: 11.5,
                                            color: tokens.fg,
                                          ),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 6),
                                    Text(
                                      MoneyFormatter.formatCents(cents),
                                      style: TextStyle(
                                        fontWeight: FontWeight.bold,
                                        fontSize: 16,
                                        color: tokens.fg,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            );
                          },
                        ),
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
                    _t(en: '🛒 New Sale', am: '🛒 አዲስ ሽያጭ ጀምር', om: '🛒 Gurgurtaa Haaraa Eegali'),
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
                          _t(en: '➕ Stock In', am: '➕ ገቢ ዕቃ', om: '➕ Galii Meeshaa'),
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
                          _t(en: '📦 Products', am: '📦 ዕቃዎች', om: '📦 Meeshaalee'),
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),

                if (_locationsEnabled) ...[
                  Row(
                    children: [
                      Expanded(
                        child: FilledButton.tonalIcon(
                          style: FilledButton.styleFrom(
                            minimumSize: const Size.fromHeight(48),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                          ),
                          onPressed: () {
                            Navigator.of(context).push(
                              MaterialPageRoute(
                                builder: (ctx) => TransferScreen(
                                  repository: widget.repository,
                                  onMutation: _notifyMutation,
                                ),
                              ),
                            );
                          },
                          icon: const Icon(Icons.local_shipping_outlined, size: 20, color: Color(0xFF0F766E)),
                          label: Text(
                            _t(en: '🚚 Stock Transfer', am: '🚚 የዕቃ ዝውውር', om: '🚚 Dabarsa Meeshaa'),
                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13.5),
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
                                builder: (ctx) => AdjustScreen(
                                  repository: widget.repository,
                                  onMutation: _notifyMutation,
                                ),
                              ),
                            );
                          },
                          icon: const Icon(Icons.tune, size: 18),
                          label: Text(
                            _t(en: '🔧 Adjust', am: '🔧 ስቶክ ማስተካከል', om: '🔧 Sirreessuu'),
                            style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5),
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  OutlinedButton.icon(
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
                      _t(en: '📜 History & Undo', am: '📜 የእንቅስቃሴ ታሪክ', om: '📜 Seenaa Sochii'),
                      style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5),
                    ),
                  ),
                ] else ...[
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
                            _t(en: '🔧 Adjust', am: '🔧 ስቶክ ማስተካከል', om: '🔧 Sirreessuu'),
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
                            _t(en: '📜 History & Undo', am: '📜 የእንቅስቃሴ ታሪክ', om: '📜 Seenaa Sochii'),
                            style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
                const SizedBox(height: 10),

                // Instant Sale Button (Fast sale by price only)
                Builder(builder: (ctx) {
                  final isDark = Theme.of(ctx).brightness == Brightness.dark;
                  return FilledButton.icon(
                    style: FilledButton.styleFrom(
                      backgroundColor: isDark ? const Color(0xFFD97706) : Colors.amber.shade800,
                      foregroundColor: Colors.white,
                      minimumSize: const Size.fromHeight(50),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                      elevation: 1,
                    ),
                    onPressed: () {
                      showInstantSaleSheet(
                        context,
                        repository: widget.repository,
                        onComplete: _notifyMutation,
                      );
                    },
                    icon: const Icon(Icons.bolt_rounded, size: 24),
                    label: Text(
                      _t(en: '⚡ Instant Sale', am: '⚡ ቅጽበታዊ ሽያጭ', om: '⚡ Gurgurtaa Saffisaa'),
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                    ),
                  );
                }),
                const SizedBox(height: 10),

                // Extra Income & Expense Button
                FilledButton.tonalIcon(
                  style: FilledButton.styleFrom(
                    minimumSize: const Size.fromHeight(48),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                  onPressed: () {
                    Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (ctx) => IncomeExpenseScreen(
                          repository: widget.repository,
                          onMutation: _notifyMutation,
                        ),
                      ),
                    );
                  },
                  icon: const Icon(Icons.account_balance_wallet_outlined, size: 20),
                  label: Text(
                    _t(en: '💸 Income & Expense', am: '💸 ገቢ እና ወጪ (ተጨማሪ)', om: '💸 Galii fi Baasii'),
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                  ),
                ),
                const SizedBox(height: 20),

                // Recent Transactions Header
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      _t(en: 'Recent Transactions', am: 'የቅርብ ጊዜ እንቅስቃሴዎች', om: 'Sochiilee Dhihoo'),
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
                      child: Text(_t(en: 'View All', am: 'ሁሉንም እይ', om: 'Hunda Ilaali')),
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
                              style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant, fontSize: 13),
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

                          final isDark = Theme.of(context).brightness == Brightness.dark;
                          final qtyColor = isPositive
                              ? (isDark ? const Color(0xFF4ADE80) : Colors.green.shade800)
                              : (isDark ? const Color(0xFFF87171) : Colors.red.shade800);

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
                                color: qtyColor,
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
