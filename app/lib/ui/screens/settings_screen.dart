import 'dart:io';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:local_auth/local_auth.dart';
import 'package:path/path.dart' as p;
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../database/app_database.dart';
import '../../main.dart';
import '../../repositories/ledger_repository.dart';
import '../../services/api_client.dart';
import '../../services/auth_storage.dart';
import '../../services/backup_service.dart';
import '../../services/export_service.dart';
import '../../services/notification_service.dart';
import '../../services/sync_engine.dart';
import '../../utils/quantity_formatter.dart';
import '../widgets/warehouse_setup_sheet.dart';
import 'error_log_screen.dart';
import 'pairing_screen.dart';

class SettingsScreen extends StatefulWidget {
  final ApiClient apiClient;
  final AuthStorage authStorage;
  final SyncEngine syncEngine;
  final LedgerRepository repository;
  final AppDatabase db;
  final BackupService? backupService;
  final LocalAuthentication? localAuth;

  const SettingsScreen({
    super.key,
    required this.apiClient,
    required this.authStorage,
    required this.syncEngine,
    required this.repository,
    required this.db,
    this.backupService,
    this.localAuth,
  });

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  late final BackupService _backupService;
  late final ExportService _exportService;
  late final NotificationService _notificationService;

  bool _isLoading = false;

  // Cloud state
  bool _isOnline = false;
  String? _deviceName;
  String? _shopName;
  String _serverUrl = '';

  // Local preferences
  String _language = 'am';
  String _themeMode = 'system';
  String _currency = 'ETB';
  bool _useEthiopianCalendar = true;
  String _numberFormat = 'standard';
  int _defaultLowStockMilli = 5000;
  bool _allowNegativeStock = false;
  bool _blockSaleWhenMaterialShort = false;

  // Backup state
  int _backupReminderDays = 7;
  int? _lastBackupMs;

  // Notification state
  bool _notificationsEnabled = true;
  bool _lowStockNotifications = true;
  bool _dailySummaryEnabled = false;
  String _dailySummaryTime = '20:00';
  bool _dubeReminderEnabled = false;
  String _dubeReminderTime = '09:00';

  // Payment Methods & Dube
  List<String> _paymentMethodsEnabled = ['cash', 'bank', 'wallet', 'dube'];
  int _dubeDefaultDays = 14;
  bool _requireReference = false;

  // Locations state
  bool _locationsEnabled = false;
  bool _transferRequiresReceipt = false;
  Location? _activeLocation;
  List<Location> _locations = [];

  // Security state
  bool _appLockEnabled = false;
  bool _canUseBiometrics = false;

  // Backup files
  List<File> _localBackups = [];

  @override
  void initState() {
    super.initState();
    _backupService = widget.backupService ?? BackupService(db: widget.db, repository: widget.repository);
    _exportService = ExportService(repository: widget.repository);
    _notificationService = NotificationService(repository: widget.repository);
    _loadAllSettings();
  }

  Future<void> _loadAllSettings() async {
    setState(() => _isLoading = true);

    // Online info
    final online = await widget.authStorage.isOnlineEnabled();
    final device = await widget.authStorage.getDeviceName();
    final shop = await widget.repository.getShopName();
    final url = await widget.authStorage.getServerUrl();

    // Preferences
    final lang = await widget.repository.getLanguage();
    final themeMode = await widget.repository.getThemeMode();
    final curr = await widget.repository.getCurrencyLabel();
    final ethCal = await widget.repository.getUseEthiopianCalendar();
    final numFmt = await widget.repository.getNumberFormat();
    final lowStock = await widget.repository.getDefaultLowStockThresholdMilli();
    final allowNeg = await widget.repository.getAllowNegativeStock();
    final blockMatShort = await widget.repository.getBlockSaleWhenMaterialShort();

    // Backups
    List<File> backups = [];
    try {
      backups = await _backupService.listAllBackups().timeout(
        const Duration(milliseconds: 300),
        onTimeout: () => [],
      );
    } catch (_) {}
    final reminderDays = await widget.repository.getBackupReminderDays();
    final lastBackup = await widget.repository.getLastBackupMs();
    final notifs = await widget.repository.isNotificationsEnabled();
    final lowNotifs = await widget.repository.isLowStockNotificationsEnabled();
    final dailySummary = await widget.repository.isDailySummaryNotificationEnabled();
    final dailyTime = await widget.repository.getDailySummaryTime();

    // Security
    final lockEnabled = await widget.repository.isAppLockEnabled();
    final auth = widget.localAuth ?? LocalAuthentication();
    bool bioSupported = false;
    try {
      bioSupported = await auth.canCheckBiometrics.timeout(
        const Duration(milliseconds: 100),
        onTimeout: () => false,
      );
    } catch (_) {}

    // Locations
    final locEnabled = await widget.repository.getLocationsEnabled();
    final reqReceipt = await widget.repository.getTransferRequiresReceipt();
    final activeLoc = await widget.repository.getActiveLocation();
    final allLocs = await widget.repository.listLocations();

    // Payments & Dube
    final payMethods = await widget.repository.getPaymentMethodsEnabled();
    final dubeDays = await widget.repository.getDubeDefaultDays();
    final reqRef = await widget.repository.getRequirePaymentReference();
    final dubeTime = await widget.repository.getDubeReminderTime();

    if (mounted) {
      setState(() {
        _isOnline = online;
        _deviceName = device;
        _shopName = shop;
        _serverUrl = url;
        _language = lang;
        _themeMode = themeMode;
        _currency = curr;
        _useEthiopianCalendar = ethCal;
        _numberFormat = numFmt;
        _defaultLowStockMilli = lowStock;
        _allowNegativeStock = allowNeg;
        _blockSaleWhenMaterialShort = blockMatShort;
        _backupReminderDays = reminderDays;
        _lastBackupMs = lastBackup;
        _notificationsEnabled = notifs;
        _lowStockNotifications = lowNotifs;
        _dailySummaryEnabled = dailySummary;
        _dailySummaryTime = dailyTime;
        _paymentMethodsEnabled = payMethods;
        _dubeDefaultDays = dubeDays;
        _requireReference = reqRef;
        _dubeReminderTime = dubeTime;
        _locationsEnabled = locEnabled;
        _transferRequiresReceipt = reqReceipt;
        _activeLocation = activeLoc;
        _locations = allLocs;
        _appLockEnabled = lockEnabled;
        _canUseBiometrics = bioSupported;
        _localBackups = backups;
        _isLoading = false;
      });
    }
  }

  // --- Shop & Regional Dialogs ---

  Future<void> _toggleNotifications(bool val) async {
    final isAm = _language == 'am';
    if (val) {
      final proceed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          icon: const Icon(Icons.notifications_active_outlined, size: 40, color: Color(0xFF0F766E)),
          title: Text(isAm ? 'ማሳወቂያዎችን ያብሩ' : 'Enable Shop Alerts'),
          content: Text(
            isAm
                ? 'ዕቃዎች ሲያልቁ እና የደንበኞች የዱቤ መክፈያ ቀን ሲደርስ መተግበሪያው በስልክዎ ላይ ጠቃሚ ማሳሰቢያዎችን እንዲልክልዎ ይፈቅዳሉ?'
                : 'Inventory will send you local alerts when items run low on stock and when customer credit (Dube) payments become due.',
            style: const TextStyle(height: 1.4),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(false),
              child: Text(isAm ? 'አሁን አይደለም' : 'Not Now'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(ctx).pop(true),
              child: Text(isAm ? 'ፈቅጃለሁ (Enable)' : 'Enable'),
            ),
          ],
        ),
      );

      if (proceed != true) return;
      await _notificationService.requestPermission();
    }

    await widget.repository.setNotificationsEnabled(val);
    await _loadAllSettings();
  }

  Future<void> _openLegalUrl(String url, String title) async {
    final uri = Uri.parse(url);
    try {
      if (await canLaunchUrl(uri)) {
        await launchUrl(uri, mode: LaunchMode.externalApplication);
      } else {
        _showFallbackLegalDialog(title, url);
      }
    } catch (_) {
      _showFallbackLegalDialog(title, url);
    }
  }

  void _showFallbackLegalDialog(String title, String url) {
    final isAm = _language == 'am';
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: Text(
          isAm
              ? 'ይህን ገጽ በአሳሽዎ ውስጥ መክፈት ይችላሉ፡\n$url\n\nዕቃ ዝርዝር የተጠቃሚዎችን የግል ዳታ አይሰበስብም፤ ሁሉም ዳታ በስልክዎ ላይ ይቀመጣል።'
              : 'You can visit this page in your browser:\n$url\n\nInventory operates strictly offline-first and does not sell or distribute personal data.',
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

  void _shareApp() {
    final isAm = _language == 'am';
    SharePlus.instance.share(
      ShareParams(
        text: isAm
            ? 'ዕቃ ዝርዝር (Inventory) - ለኢትዮጵያ ሱቆች የተዘጋጀ ፈጣን የሽያጭ እና የዕቃ መቆጣጠሪያ መተግበሪያ! https://stock.yenesuq.com'
            : 'Inventory - Offline-first inventory & POS app for Ethiopian retail shops! https://stock.yenesuq.com',
        subject: 'Inventory App for Ethiopian Retailers',
      ),
    );
  }

  Future<void> _editShopName() async {
    final ctrl = TextEditingController(text: _shopName);
    final newName = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Shop Name / የሱቅ ስም'),
        content: TextField(
          controller: ctrl,
          decoration: const InputDecoration(labelText: 'Shop Name'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(ctrl.text.trim()),
            child: const Text('Save'),
          ),
        ],
      ),
    );

    if (newName != null && newName.isNotEmpty) {
      await widget.repository.setShopName(newName);
      await _loadAllSettings();
    }
  }

  Future<void> _editCurrency() async {
    final ctrl = TextEditingController(text: _currency);
    final newCurr = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Currency Label / የገንዘብ ምልክት'),
        content: TextField(
          controller: ctrl,
          decoration: const InputDecoration(labelText: 'Currency Label (e.g. ETB, ብር, USD)'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(ctrl.text.trim()),
            child: const Text('Save'),
          ),
        ],
      ),
    );

    if (newCurr != null && newCurr.isNotEmpty) {
      await widget.repository.setCurrencyLabel(newCurr);
      await _loadAllSettings();
    }
  }

  Future<void> _editDefaultLowStock() async {
    final ctrl = TextEditingController(text: QuantityFormatter.formatDisplay(_defaultLowStockMilli));
    final newLimit = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Default Low Stock Threshold / ነባሪ ዝቅተኛ ስቶክ'),
        content: TextField(
          controller: ctrl,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(labelText: 'Quantity threshold'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(ctrl.text.trim()),
            child: const Text('Save'),
          ),
        ],
      ),
    );

    if (newLimit != null && newLimit.isNotEmpty) {
      final milli = QuantityFormatter.parseQty(newLimit);
      if (milli > 0) {
        await widget.repository.setDefaultLowStockThresholdMilli(milli);
        await _loadAllSettings();
      }
    }
  }

  // --- Warehouse & Locations ---

  Future<void> _pickActiveDeviceLocation() async {
    final selected = await showModalBottomSheet<Location>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) {
        return SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Padding(
                padding: EdgeInsets.all(16),
                child: Text(
                  'የመሳሪያው የስራ ቦታ / Active Device Location',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                ),
              ),
              const Divider(height: 1),
              ..._locations.map((loc) {
                final isSelected = loc.id == _activeLocation?.id;
                return ListTile(
                  leading: Icon(
                    loc.kind == 'warehouse' ? Icons.warehouse_rounded : Icons.storefront_rounded,
                    color: isSelected ? const Color(0xFF0F766E) : Colors.grey,
                  ),
                  title: Text(loc.name, style: TextStyle(fontWeight: isSelected ? FontWeight.bold : FontWeight.normal)),
                  trailing: isSelected ? const Icon(Icons.check, color: Color(0xFF0F766E)) : null,
                  onTap: () => Navigator.of(ctx).pop(loc),
                );
              }),
            ],
          ),
        );
      },
    );

    if (selected != null) {
      await widget.repository.setActiveLocationId(selected.id);
      await _loadAllSettings();
    }
  }

  Future<void> _disableWarehouseAndStore() async {
    final check = await widget.repository.canDisableLocations();
    if (!check.canDisable) {
      if (mounted) {
        showDialog(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('⚠️ Cannot Turn Off Warehouse & Store'),
            content: Text(
              check.reason ??
                  'Stock exists in multiple locations. All stock must be transferred into one location before turning off warehouse & store.',
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

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Turn Off Warehouse & Store?'),
        content: const Text(
          'This will consolidate inventory into a single place and hide warehouse features.\n\nAre you sure you want to proceed?',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Turn Off / አጥፋ'),
          ),
        ],
      ),
    );

    if (confirmed == true) {
      await widget.repository.disableWarehouseAndStore();
      await _loadAllSettings();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Warehouse & Store turned off.')),
        );
      }
    }
  }

  // --- Notifications Configuration ---

  Future<void> _pickDailySummaryTime() async {
    final parts = _dailySummaryTime.split(':');
    final initialTime = TimeOfDay(
      hour: int.tryParse(parts[0]) ?? 20,
      minute: parts.length > 1 ? (int.tryParse(parts[1]) ?? 0) : 0,
    );

    final picked = await showTimePicker(
      context: context,
      initialTime: initialTime,
    );

    if (picked != null) {
      final formatted = '${picked.hour.toString().padLeft(2, '0')}:${picked.minute.toString().padLeft(2, '0')}';
      await widget.repository.setDailySummaryTime(formatted);
      if (_dailySummaryEnabled) {
        await _notificationService.scheduleDailySummary(enabled: true, timeStr: formatted);
      }
      await _loadAllSettings();
    }
  }

  // --- Security & PIN Lock ---

  Future<void> _toggleAppLock(bool enable) async {
    if (enable) {
      final pinCtrl = TextEditingController();
      final pin = await showDialog<String>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Set 4-Digit Security PIN'),
          content: TextField(
            controller: pinCtrl,
            keyboardType: TextInputType.number,
            maxLength: 4,
            obscureText: true,
            decoration: const InputDecoration(
              hintText: '4-digit PIN',
              border: OutlineInputBorder(),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Cancel')),
            FilledButton(
              onPressed: () {
                if (pinCtrl.text.trim().length == 4) {
                  Navigator.of(ctx).pop(pinCtrl.text.trim());
                }
              },
              child: const Text('Enable PIN'),
            ),
          ],
        ),
      );

      if (pin != null) {
        await widget.repository.setAppLockPin(pin);
        await widget.repository.setAppLockEnabled(true);
        await _loadAllSettings();
      }
    } else {
      await widget.repository.setAppLockEnabled(false);
      await _loadAllSettings();
    }
  }

  Future<void> _changePin() async {
    final pinCtrl = TextEditingController();
    final isAm = _language == 'am';
    final pin = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isAm ? 'አዲስ ባለ 4 አሃዝ ፒን (PIN) ያስገቡ' : 'Change 4-Digit Security PIN'),
        content: TextField(
          controller: pinCtrl,
          keyboardType: TextInputType.number,
          maxLength: 4,
          obscureText: true,
          decoration: InputDecoration(
            hintText: isAm ? 'ባለ 4 አሃዝ ፒን' : '4-digit PIN',
            border: const OutlineInputBorder(),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(), child: Text(isAm ? 'ይቅር' : 'Cancel')),
          FilledButton(
            onPressed: () {
              if (pinCtrl.text.trim().length == 4) {
                Navigator.of(ctx).pop(pinCtrl.text.trim());
              }
            },
            child: Text(isAm ? 'ቀይር' : 'Change PIN'),
          ),
        ],
      ),
    );

    if (pin != null) {
      await widget.repository.setAppLockPin(pin);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(isAm ? 'የደህንነት ፒን ተቀይሯል' : 'Security PIN changed successfully')),
        );
      }
    }
  }

  Future<void> _pickBackupReminderDays() async {
    final isAm = _language == 'am';
    final days = await showDialog<int>(
      context: context,
      builder: (ctx) => SimpleDialog(
        title: Text(isAm ? 'የባካፕ ማሳሰቢያ ድግግሞሽ' : 'Backup Reminder Frequency'),
        children: [
          SimpleDialogOption(
            onPressed: () => Navigator.of(ctx).pop(3),
            child: Text(isAm ? 'በየ 3 ቀኑ (3 days)' : 'Every 3 days'),
          ),
          SimpleDialogOption(
            onPressed: () => Navigator.of(ctx).pop(7),
            child: Text(isAm ? 'በየሳምንቱ / 7 ቀናት (Weekly)' : 'Weekly (7 days)'),
          ),
          SimpleDialogOption(
            onPressed: () => Navigator.of(ctx).pop(14),
            child: Text(isAm ? 'በየ 14 ቀኑ (14 days)' : 'Every 14 days'),
          ),
          SimpleDialogOption(
            onPressed: () => Navigator.of(ctx).pop(30),
            child: Text(isAm ? 'በየወሩ / 30 ቀናት (Monthly)' : 'Monthly (30 days)'),
          ),
          SimpleDialogOption(
            onPressed: () => Navigator.of(ctx).pop(0),
            child: Text(isAm ? 'ፈጽሞ አታስታውሰኝ (Never)' : 'Never (Off)'),
          ),
        ],
      ),
    );

    if (days != null) {
      await widget.repository.setBackupReminderDays(days);
      await _loadAllSettings();
    }
  }

  Future<void> _pickNumberFormat() async {
    final isAm = _language == 'am';
    final choice = await showDialog<String>(
      context: context,
      builder: (ctx) => SimpleDialog(
        title: Text(isAm ? 'የቁጥሮች አቀራረብ (Number Format)' : 'Number Format'),
        children: [
          SimpleDialogOption(
            onPressed: () => Navigator.of(ctx).pop('standard'),
            child: const Text('Standard (1,234.50)'),
          ),
          SimpleDialogOption(
            onPressed: () => Navigator.of(ctx).pop('space'),
            child: const Text('Space separator (1 234.50)'),
          ),
        ],
      ),
    );

    if (choice != null) {
      await widget.repository.setNumberFormat(choice);
      await _loadAllSettings();
    }
  }

  void _showSupportDialog() {
    final isAm = _language == 'am';
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.support_agent_rounded, size: 40, color: Color(0xFF0F766E)),
        title: Text(isAm ? 'እርዳታ እና ድጋፍ' : 'Support & Help'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              isAm
                  ? 'ጥያቄ ወይም እርዳታ ሲያስፈልግዎት በሚከተሉት አድራሻዎች ያግኙን፡'
                  : 'For questions, feedback, or support, reach out to our team:',
              style: const TextStyle(fontSize: 14),
            ),
            const SizedBox(height: 16),
            const ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: Icon(Icons.email_outlined, color: Color(0xFF0F766E)),
              title: Text('support@yenesuq.com'),
              subtitle: Text('Email Support'),
            ),
            const ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: Icon(Icons.send_rounded, color: Colors.blue),
              title: Text('@yenesuq_support'),
              subtitle: Text('Telegram Support Channel'),
            ),
          ],
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

  // --- CSV Exports ---

  Future<void> _exportProductsCsv() async {
    setState(() => _isLoading = true);
    try {
      final file = await _exportService.exportProductsCsv();
      await _exportService.shareFile(file, subject: 'Products Catalog Export');
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _exportSalesCsv() async {
    setState(() => _isLoading = true);
    try {
      final file = await _exportService.exportSalesCsv();
      await _exportService.shareFile(file, subject: 'Sales Ledger Export');
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _exportMovementsCsv() async {
    setState(() => _isLoading = true);
    try {
      final file = await _exportService.exportMovementsCsv();
      await _exportService.shareFile(file, subject: 'Movements History Export');
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  // --- Database Backup & Restore ---

  Future<void> _createAndShareBackup() async {
    setState(() => _isLoading = true);
    try {
      final file = await _backupService.createBackupFile();
      await _backupService.shareBackup(file);
      await _loadAllSettings();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Backup failed: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _promptRestoreBackup(File file) async {
    final valid = await _backupService.validateBackupFile(file);
    if (!valid) {
      if (mounted) {
        showDialog(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('❌ Invalid Backup File'),
            content: const Text(
              'The selected file is not a valid SQLite database or does not contain required Inventory tables. Restoration aborted.',
            ),
            actions: [
              FilledButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('OK')),
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
        title: const Text('⚠️ OVERWRITE DATABASE?'),
        content: Text(
          'Restoring this backup (${p.basename(file.path)}) will replace your current local catalog, sales, and movement records with the data from this backup.\n\nThis cannot be undone. Are you sure you wish to continue?',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancel / ሰርዝ')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red.shade900),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Yes, Overwrite & Restore'),
          ),
        ],
      ),
    );

    if (confirm == true) {
      setState(() => _isLoading = true);
      try {
        await _backupService.restoreBackup(file);
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              backgroundColor: Colors.green,
              content: Text('Database successfully restored! Please restart the app.'),
            ),
          );
        }
      } catch (e) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Restore failed: $e')),
          );
        }
      } finally {
        if (mounted) setState(() => _isLoading = false);
      }
    }
  }

  // --- Cloud & Sync Actions (Preserved) ---

  Future<void> _goOfflineAgain() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Go Offline Again? / ኦፍላይን ሁን?'),
        content: const Text(
          'Cloud sync will stop. All your products, stock counts, and sales remain completely safe on this phone.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.of(ctx).pop(true), child: const Text('Go Offline')),
        ],
      ),
    );

    if (confirm == true) {
      widget.syncEngine.stopPeriodicSync();
      await widget.authStorage.setOnlineEnabled(false);
      widget.syncEngine.stateNotifier.value = widget.syncEngine.state.copyWith(
        status: SyncStatus.offline,
      );
      await _loadAllSettings();
    }
  }

  Future<void> _deleteOnlineData() async {
    final step1 = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('⚠️ Delete Online Data?'),
        content: const Text(
          'This will permanently delete your online shop data on the server, including Telegram bot connection.\n\nLocal data on this phone is NOT affected.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancel')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Continue'),
          ),
        ],
      ),
    );

    if (step1 != true || !mounted) return;

    setState(() => _isLoading = true);
    try {
      final token = await widget.authStorage.getDeviceToken();
      final serverUrl = await widget.authStorage.getServerUrl();

      if (token != null) {
        await widget.apiClient.deleteShopOnline(serverUrl: serverUrl, deviceToken: token);
      }

      widget.syncEngine.stopPeriodicSync();
      await widget.authStorage.clearDeviceToken();
      await widget.authStorage.setOnlineEnabled(false);
      widget.syncEngine.stateNotifier.value = widget.syncEngine.state.copyWith(status: SyncStatus.offline);

      await _loadAllSettings();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Delete failed: $e')));
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isAm = _language == 'am';

    return Scaffold(
      appBar: AppBar(
        title: Text(isAm ? 'ቅንብሮች' : 'Settings'),
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                // ==========================================
                // 1. SHOP
                // ==========================================
                _buildSectionHeader(isAm ? '1. የሱቅ መረጃ (Shop)' : '1. Shop'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Column(
                    children: [
                      ListTile(
                        leading: const Icon(Icons.storefront_outlined),
                        title: Text(isAm ? 'የሱቅ ስም' : 'Shop Name'),
                        subtitle: Text(_shopName ?? 'Inventory'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: _editShopName,
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.monetization_on_outlined),
                        title: Text(isAm ? 'የገንዘብ ምልክት' : 'Currency Label'),
                        subtitle: Text(_currency),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: _editCurrency,
                      ),
                      const Divider(height: 1),
                      SwitchListTile(
                        secondary: const Icon(Icons.remove_circle_outline),
                        title: Text(isAm ? 'ከዜሮ በታች ሽያጭ ፍቀድ' : 'Allow Negative Stock'),
                        subtitle: Text(
                          isAm
                              ? 'ስቶክ ባዶ ቢሆንም ሽያጭ እንዲመዘገብ ይፈቅዳል'
                              : 'Permit sales even if inventory drops below zero',
                        ),
                        value: _allowNegativeStock,
                        onChanged: (val) async {
                          await widget.repository.setAllowNegativeStock(val);
                          await _loadAllSettings();
                        },
                      ),
                      const Divider(height: 1),
                      SwitchListTile(
                        secondary: const Icon(Icons.inventory_2_outlined),
                        title: Text(isAm ? 'ጥሬ ዕቃ ሲያጥር ሽያጭ ከልክል' : 'Block sale when materials short'),
                        subtitle: Text(
                          isAm
                              ? 'የተመረተ ዕቃ ጥሬ ዕቃ ከሌለው እንዳይሸጥ ያግዳል'
                              : 'Prevent selling consumables if raw material stock is insufficient',
                        ),
                        value: _blockSaleWhenMaterialShort,
                        onChanged: (val) async {
                          await widget.repository.setBlockSaleWhenMaterialShort(val);
                          await _loadAllSettings();
                        },
                      ),
                      const Divider(height: 1),
                      if (!_locationsEnabled) ...[
                        ListTile(
                          leading: const Icon(Icons.warehouse_outlined, color: Color(0xFF0F766E)),
                          title: Text(isAm ? 'መጋዘን እና ሱቅ ተጠቀም' : 'Use Warehouse & Store'),
                          subtitle: Text(
                            isAm
                                ? 'ስቶክን በሱቅ እና በመጋዘን መካከል መክፈል እና ማዛወር'
                                : 'Separate stock between store front and warehouse, with transfers',
                          ),
                          trailing: const Icon(Icons.chevron_right),
                          onTap: () => showWarehouseSetupSheet(
                            context,
                            repository: widget.repository,
                            onComplete: _loadAllSettings,
                          ),
                        ),
                      ] else ...[
                        ListTile(
                          leading: const Icon(Icons.storefront_outlined, color: Color(0xFF0F766E)),
                          title: Text(isAm ? 'የዚህ ስልክ ነባሪ የስራ ቦታ' : 'Active Device Location'),
                          subtitle: Text(_activeLocation?.name ?? 'Store'),
                          trailing: const Icon(Icons.chevron_right),
                          onTap: _pickActiveDeviceLocation,
                        ),
                        const Divider(height: 1),
                        SwitchListTile(
                          secondary: const Icon(Icons.receipt_long_outlined),
                          title: Text(isAm ? 'የዝውውር ደረሰኝ ማረጋገጫ' : 'Transfers Require Receipt'),
                          subtitle: Text(
                            isAm
                                ? 'ላኪው ሲልክ ተቀባዩ ቆጥሮ ካላረጋገጠ ስቶክ አይዛወርም'
                                : 'Sender dispatches; receiver must verify and confirm received quantity',
                          ),
                          value: _transferRequiresReceipt,
                          onChanged: (val) async {
                            await widget.repository.setTransferRequiresReceipt(val);
                            await _loadAllSettings();
                          },
                        ),
                        const Divider(height: 1),
                        ListTile(
                          leading: const Icon(Icons.power_settings_new_rounded, color: Colors.red),
                          title: Text(
                            isAm ? 'መጋዘን እና ሱቅ አጥፋ' : 'Turn off Warehouse & Store',
                            style: const TextStyle(color: Colors.red),
                          ),
                          subtitle: Text(
                            isAm
                                ? 'የሚፈቀደው ሁሉም ስቶክ ወደ አንድ ቦታ ሲጠቃለል ብቻ ነው'
                                : 'Allowed only if all stock sits in a single location',
                          ),
                          onTap: _disableWarehouseAndStore,
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // ==========================================
                // 2. LANGUAGE & DISPLAY
                // ==========================================
                _buildSectionHeader(isAm ? '2. ቋንቋ እና ገጽታ (Language & Display)' : '2. Language & Display'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Column(
                    children: [
                      ListTile(
                        leading: const Icon(Icons.language_outlined),
                        title: Text(isAm ? 'ቋንቋ (Language)' : 'Language'),
                        subtitle: Text(isAm ? 'አማርኛ (Amharic)' : 'English'),
                        trailing: SegmentedButton<String>(
                          segments: const [
                            ButtonSegment(value: 'am', label: Text('አማ')),
                            ButtonSegment(value: 'en', label: Text('EN')),
                          ],
                          selected: {_language},
                          onSelectionChanged: (set) async {
                            final val = set.first;
                            await widget.repository.setLanguage(val);
                            if (context.mounted) {
                              InventoryApp.of(context)?.setLocale(val);
                            }
                            await _loadAllSettings();
                          },
                        ),
                      ),
                      const Divider(height: 1),
                      Padding(
                        padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                const Icon(Icons.palette_outlined),
                                const SizedBox(width: 16),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        isAm ? 'የመተግበሪያ ገጽታ' : 'Theme / Appearance',
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                      ),
                                      Text(
                                        _themeMode == 'light'
                                            ? (isAm ? 'ብርሃን (Light)' : 'Light')
                                            : _themeMode == 'dark'
                                                ? (isAm ? 'ጨለማ (Dark)' : 'Dark')
                                                : (isAm ? 'የስልክ ስርዓት (System)' : 'System'),
                                        style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                                              color: Theme.of(context).colorScheme.onSurfaceVariant,
                                            ),
                                      ),
                                    ],
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 12),
                            SizedBox(
                              width: double.infinity,
                              child: SegmentedButton<String>(
                                segments: [
                                  ButtonSegment(value: 'system', icon: const Icon(Icons.brightness_auto, size: 16), label: Text(isAm ? 'ስርዓት' : 'Auto')),
                                  ButtonSegment(value: 'light', icon: const Icon(Icons.light_mode, size: 16), label: Text(isAm ? 'ብርሃን' : 'Light')),
                                  ButtonSegment(value: 'dark', icon: const Icon(Icons.dark_mode, size: 16), label: Text(isAm ? 'ጨለማ' : 'Dark')),
                                ],
                                selected: {_themeMode},
                                onSelectionChanged: (set) async {
                                  final val = set.first;
                                  await widget.repository.setThemeMode(val);
                                  final tm = switch (val) {
                                    'light' => ThemeMode.light,
                                    'dark' => ThemeMode.dark,
                                    _ => ThemeMode.system,
                                  };
                                  if (context.mounted) {
                                    InventoryApp.of(context)?.setThemeMode(tm);
                                  }
                                  await _loadAllSettings();
                                },
                              ),
                            ),
                          ],
                        ),
                      ),
                      const Divider(height: 1),
                      SwitchListTile(
                        secondary: const Icon(Icons.calendar_month_outlined),
                        title: Text(isAm ? 'የኢትዮጵያ ዘመን አቆጣጠር' : 'Ethiopian Calendar'),
                        subtitle: Text(
                          isAm
                              ? 'ቀኖችን በኢትዮጵያ ዘመን አቆጣጠር (ዓ.ም) አሳይ'
                              : 'Show dates using Ethiopian calendar (ዓ.ም)',
                        ),
                        value: _useEthiopianCalendar,
                        onChanged: (val) async {
                          await widget.repository.setUseEthiopianCalendar(val);
                          await _loadAllSettings();
                        },
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.pin_outlined),
                        title: Text(isAm ? 'የቁጥሮች አቀራረብ' : 'Number Format'),
                        subtitle: Text(_numberFormat == 'space' ? '1 234.50' : 'Standard (1,234.50)'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: _pickNumberFormat,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // ==========================================
                // 3. PAYMENTS
                // ==========================================
                _buildSectionHeader(isAm ? '3. ክፍያዎች (Payments)' : '3. Payments'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Column(
                    children: [
                      ListTile(
                        leading: const Icon(Icons.payment_outlined),
                        title: Text(isAm ? 'የተፈቀዱ የክፍያ ዘዴዎች' : 'Enabled Payment Methods'),
                        subtitle: Text(_paymentMethodsEnabled.map((m) => m.toUpperCase()).join(', ')),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: _showPaymentMethodsDialog,
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.calendar_today_outlined),
                        title: Text(isAm ? 'ነባሪ የዱቤ መክፈያ ቀናት' : 'Default Dube Days'),
                        subtitle: Text('$_dubeDefaultDays ${isAm ? 'ቀናት' : 'days'}'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: _pickDubeDefaultDays,
                      ),
                      const Divider(height: 1),
                      SwitchListTile(
                        secondary: const Icon(Icons.tag_outlined),
                        title: Text(isAm ? 'የግብይት መለያ ቁጥር ግዴታ አድርግ' : 'Require Payment Reference'),
                        subtitle: Text(
                          isAm
                              ? 'ለባንክ እና ዋሌት ክፍያዎች የደረሰኝ/ትራንዛክሽን ቁጥር መጠየቅ'
                              : 'Require transaction reference for Bank and Wallet payments',
                        ),
                        value: _requireReference,
                        onChanged: (val) async {
                          await widget.repository.setRequirePaymentReference(val);
                          await _loadAllSettings();
                        },
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.star_outline),
                        title: Text(isAm ? 'ተመራጭ ባንኮች እና ዋሌቶች' : 'Favorite Banks & Wallets'),
                        subtitle: Text(
                          isAm
                              ? 'በሽያጭ ወቅት በፍጥነት እንዲገኙ የተመረጡ አቅራቢዎች'
                              : 'Configure star favorites for quick selection during checkout',
                        ),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: _manageFavoriteProviders,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // ==========================================
                // 4. ALERTS
                // ==========================================
                _buildSectionHeader(isAm ? '4. ማሳሰቢያዎች (Alerts)' : '4. Alerts'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Column(
                    children: [
                      SwitchListTile(
                        secondary: const Icon(Icons.notifications_active_outlined),
                        title: Text(isAm ? 'ማሳወቂያዎችን ፍቀድ' : 'Enable Alerts'),
                        subtitle: Text(
                          isAm
                              ? 'በስልክዎ ላይ የሚመጡ የአካባቢ ማሳሰቢያዎች'
                              : 'Local push alerts for low stock and due credit',
                        ),
                        value: _notificationsEnabled,
                        onChanged: _toggleNotifications,
                      ),
                      if (_notificationsEnabled) ...[
                        const Divider(height: 1),
                        SwitchListTile(
                          secondary: const Icon(Icons.warning_amber_rounded, color: Colors.amber),
                          title: Text(isAm ? 'የዝቅተኛ ስቶክ ማንቂያ' : 'Low Stock Alerts'),
                          subtitle: Text(
                            isAm
                                ? 'ዕቃ ሲያልቅ ወዲያውኑ ማሳሰቢያ ይላካል'
                                : 'Notify when a sale reduces stock below threshold',
                          ),
                          value: _lowStockNotifications,
                          onChanged: (val) async {
                            await widget.repository.setLowStockNotificationsEnabled(val);
                            await _loadAllSettings();
                          },
                        ),
                        const Divider(height: 1),
                        ListTile(
                          leading: const Icon(Icons.low_priority_outlined),
                          title: Text(isAm ? 'ነባሪ ዝቅተኛ ስቶክ መጠን' : 'Default Low Stock Threshold'),
                          subtitle: Text('${QuantityFormatter.formatDisplay(_defaultLowStockMilli)} units'),
                          trailing: const Icon(Icons.chevron_right),
                          onTap: _editDefaultLowStock,
                        ),
                        const Divider(height: 1),
                        SwitchListTile(
                          secondary: const Icon(Icons.summarize_outlined),
                          title: Text(isAm ? 'የዕለት ሽያጭ ማጠቃለያ' : 'Daily Sales Summary'),
                          subtitle: Text(
                            isAm
                                ? 'በየቀኑ በ $_dailySummaryTime የሽያጭ ማጠቃለያ ማሳወቅ'
                                : 'Scheduled daily at $_dailySummaryTime',
                          ),
                          value: _dailySummaryEnabled,
                          onChanged: (val) async {
                            await widget.repository.setDailySummaryNotificationEnabled(val);
                            await _notificationService.scheduleDailySummary(
                              enabled: val,
                              timeStr: _dailySummaryTime,
                            );
                            await _loadAllSettings();
                          },
                        ),
                        if (_dailySummaryEnabled) ...[
                          const Divider(height: 1),
                          ListTile(
                            leading: const Icon(Icons.schedule),
                            title: Text(isAm ? 'የማጠቃለያው ሰዓት' : 'Daily Summary Time'),
                            subtitle: Text(_dailySummaryTime),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: _pickDailySummaryTime,
                          ),
                        ],
                        const Divider(height: 1),
                        SwitchListTile(
                          secondary: const Icon(Icons.alarm, color: Colors.deepOrange),
                          title: Text(isAm ? 'የደረሰ ዱቤ ማሳሰቢያ' : 'Dube Due Reminders'),
                          subtitle: Text(
                            isAm
                                ? 'በ $_dubeReminderTime ዛሬ የሚከፈሉ ዱቤዎችን ማስታወስ'
                                : 'Offline reminder at $_dubeReminderTime for customer credit due today',
                          ),
                          value: _dubeReminderEnabled,
                          onChanged: (val) async {
                            setState(() => _dubeReminderEnabled = val);
                            await _notificationService.scheduleDubeDueReminder(
                              enabled: val,
                              timeStr: _dubeReminderTime,
                            );
                          },
                        ),
                        if (_dubeReminderEnabled) ...[
                          const Divider(height: 1),
                          ListTile(
                            leading: const Icon(Icons.schedule),
                            title: Text(isAm ? 'የዱቤ ማሳሰቢያ ሰዓት' : 'Dube Reminder Time'),
                            subtitle: Text(_dubeReminderTime),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: _pickDubeReminderTime,
                          ),
                        ],
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // ==========================================
                // 5. BACKUP & DATA
                // ==========================================
                _buildSectionHeader(isAm ? '5. ባካፕ እና ዳታ (Backup & Data)' : '5. Backup & Data'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        // Educational nudge banner
                        Container(
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: const Color(0xFFF59E0B).withValues(alpha: 0.12),
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: const Color(0xFFF59E0B).withValues(alpha: 0.4)),
                          ),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Icon(Icons.info_outline, color: Color(0xFFB45309), size: 20),
                              const SizedBox(width: 10),
                              Expanded(
                                child: Text(
                                  isAm
                                      ? 'ስልክዎ ቢጠፋ ወይም ቢበላሽ ዳታዎ እንዳይጠፋ በየጊዜው ኮፒ (ባካፕ) በማንሳት ወደ ቴሌግራም ወይም ደመና (Drive) ያስቀምጡ።'
                                      : 'A lost phone means lost data unless backed up. Keep a copy in Telegram Saved Messages or Google Drive.',
                                  style: const TextStyle(fontSize: 12, height: 1.4, color: Color(0xFF92400E)),
                                ),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 16),

                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: const Icon(Icons.history_toggle_off, color: Color(0xFF0F766E)),
                          title: Text(isAm ? 'የመጨረሻው ባካፕ ቀን' : 'Last Backup Date'),
                          subtitle: Text(
                            _lastBackupMs == null
                                ? (isAm ? 'እስካሁን አልተወሰደም (Never)' : 'Never backed up')
                                : DateFormat('MMM d, yyyy HH:mm').format(DateTime.fromMillisecondsSinceEpoch(_lastBackupMs!)),
                            style: TextStyle(
                              fontWeight: FontWeight.bold,
                              color: _lastBackupMs == null ? Colors.red.shade700 : Colors.black87,
                            ),
                          ),
                        ),
                        const Divider(height: 1),

                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: const Icon(Icons.timer_outlined),
                          title: Text(isAm ? 'የባካፕ ማሳሰቢያ ድግግሞሽ' : 'Backup Reminder Frequency'),
                          subtitle: Text(
                            _backupReminderDays == 0
                                ? (isAm ? 'ጠፍቷል (Never)' : 'Never (Off)')
                                : (isAm ? 'በየ $_backupReminderDays ቀኑ' : 'Every $_backupReminderDays days'),
                          ),
                          trailing: const Icon(Icons.chevron_right),
                          onTap: _pickBackupReminderDays,
                        ),
                        const SizedBox(height: 16),

                        FilledButton.icon(
                          style: FilledButton.styleFrom(
                            backgroundColor: const Color(0xFF0F766E),
                            padding: const EdgeInsets.symmetric(vertical: 14),
                          ),
                          onPressed: _createAndShareBackup,
                          icon: const Icon(Icons.share_outlined),
                          label: Text(
                            isAm ? 'ባካፕ አዘጋጅተህ አጋራ (Telegram/Cloud)' : 'Create & Share Database Backup',
                          ),
                        ),

                        if (_localBackups.isNotEmpty) ...[
                          const SizedBox(height: 20),
                          const Divider(),
                          const SizedBox(height: 8),
                          Text(
                            isAm ? 'የቀደሙ ባካፖች (Local Backups):' : 'Local Backups & Snapshots:',
                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                          ),
                          const SizedBox(height: 8),
                          ListView.separated(
                            shrinkWrap: true,
                            physics: const NeverScrollableScrollPhysics(),
                            itemCount: _localBackups.length,
                            separatorBuilder: (_, __) => const Divider(height: 10),
                            itemBuilder: (ctx, index) {
                              final f = _localBackups[index];
                              final name = p.basename(f.path);
                              final modified = f.lastModifiedSync();
                              final fDate = DateFormat('MMM d, yyyy HH:mm').format(modified);

                              return ListTile(
                                dense: true,
                                contentPadding: EdgeInsets.zero,
                                leading: Icon(
                                  name.startsWith('snapshot_') ? Icons.auto_mode : Icons.save_outlined,
                                  color: Colors.blueGrey,
                                ),
                                title: Text(name, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                                subtitle: Text(fDate),
                                trailing: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    IconButton(
                                      icon: const Icon(Icons.share, size: 18),
                                      tooltip: 'Share Backup',
                                      onPressed: () => _backupService.shareBackup(f),
                                    ),
                                    IconButton(
                                      icon: const Icon(Icons.restore, size: 18, color: Colors.red),
                                      tooltip: 'Restore Backup',
                                      onPressed: () => _promptRestoreBackup(f),
                                    ),
                                  ],
                                ),
                              );
                            },
                          ),
                        ],

                        const SizedBox(height: 16),
                        const Divider(),
                        const SizedBox(height: 8),
                        Text(
                          isAm ? 'ዳታ ወደ CSV ላክ (Export Data):' : 'Export Data (CSV):',
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                        ),
                        const SizedBox(height: 8),

                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: const Icon(Icons.inventory_2_outlined, color: Color(0xFF0F766E)),
                          title: Text(isAm ? 'የዕቃዎች ዝርዝር (Products CSV)' : 'Export Products Catalog CSV'),
                          subtitle: Text(isAm ? 'ስቶክ፣ ዋጋ እና መደብ የያዘ' : 'Includes stock levels, costs, and selling prices'),
                          trailing: const Icon(Icons.download_rounded),
                          onTap: _exportProductsCsv,
                        ),
                        const Divider(height: 1),
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: const Icon(Icons.point_of_sale, color: Colors.green),
                          title: Text(isAm ? 'የሽያጭ መዝገብ (Sales CSV)' : 'Export Sales Ledger CSV'),
                          subtitle: Text(isAm ? 'ደንበኞች፣ የገንዘብ መጠን እና ቀን' : 'Customer transactions with quantities and revenue'),
                          trailing: const Icon(Icons.download_rounded),
                          onTap: _exportSalesCsv,
                        ),
                        const Divider(height: 1),
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: const Icon(Icons.history, color: Colors.blue),
                          title: Text(isAm ? 'ሙሉ እንቅስቃሴዎች (Movements CSV)' : 'Export All Movements CSV'),
                          subtitle: Text(isAm ? 'ሽያጭ፣ ግዢ፣ ማስተካከያ እና ዝውውር' : 'Full audit ledger (sales, intake, adjustments)'),
                          trailing: const Icon(Icons.download_rounded),
                          onTap: _exportMovementsCsv,
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 20),

                // ==========================================
                // 6. ONLINE & TEAM
                // ==========================================
                _buildSectionHeader(isAm ? '6. ኦንላይን እና ቡድን (Online & Team)' : '6. Online & Team'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Icon(
                              _isOnline ? Icons.cloud_done_rounded : Icons.cloud_off_rounded,
                              color: _isOnline ? Colors.green : Colors.grey,
                              size: 28,
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    _isOnline ? (isAm ? 'ኦንላይን ግንኙነት ይሰራል' : 'Online Sync Active') : (isAm ? 'ኦፍላይን ሁነታ (Offline)' : 'Offline Mode'),
                                    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                                  ),
                                  Text(
                                    _isOnline
                                        ? 'Device: $_deviceName${_shopName != null ? ' • Shop: $_shopName' : ''}'
                                        : (isAm ? 'ያለ ኢንተርኔት በስልክዎ ላይ ብቻ ይሰራል' : 'Working locally without internet connection'),
                                    style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                                  ),
                                  if (_serverUrl.isNotEmpty)
                                    Text(
                                      'Server: $_serverUrl',
                                      style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
                                    ),
                                ],
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 16),
                        if (!_isOnline) ...[
                          FilledButton.icon(
                            style: FilledButton.styleFrom(
                              backgroundColor: const Color(0xFF0F766E),
                              minimumSize: const Size.fromHeight(46),
                            ),
                            onPressed: () async {
                              await Navigator.of(context).push(
                                MaterialPageRoute(
                                  builder: (ctx) => PairingScreen(
                                    apiClient: widget.apiClient,
                                    authStorage: widget.authStorage,
                                    syncEngine: widget.syncEngine,
                                  ),
                                ),
                              );
                              await _loadAllSettings();
                            },
                            icon: const Icon(Icons.link_rounded),
                            label: Text(isAm ? 'ቴሌግራም አገናኝ / ኦንላይን ሁን' : 'Go Online / Connect Telegram'),
                          ),
                        ] else ...[
                          OutlinedButton.icon(
                            style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(46)),
                            onPressed: _goOfflineAgain,
                            icon: const Icon(Icons.cloud_off),
                            label: Text(isAm ? 'ወደ ኦፍላይን ተመለስ' : 'Go Offline Again (Keep local data)'),
                          ),
                          const SizedBox(height: 10),
                          FilledButton.icon(
                            style: FilledButton.styleFrom(
                              backgroundColor: Colors.red.shade800,
                              minimumSize: const Size.fromHeight(46),
                            ),
                            onPressed: _deleteOnlineData,
                            icon: const Icon(Icons.delete_forever),
                            label: Text(isAm ? 'የኦንላይን ሱቅ ከሰርቨር ሰርዝ' : 'Delete Online Shop on Server'),
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 20),

                // ==========================================
                // 7. SECURITY
                // ==========================================
                _buildSectionHeader(isAm ? '7. ደህንነት (Security)' : '7. Security'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Column(
                    children: [
                      SwitchListTile(
                        secondary: const Icon(Icons.lock_outline),
                        title: Text(isAm ? 'የመተግበሪያ መቆለፊያ (PIN/Biometric)' : 'PIN / Biometric Lock'),
                        subtitle: Text(
                          _appLockEnabled
                              ? (_canUseBiometrics
                                  ? (isAm ? 'በፒን እና የጣት አሻራ የተጠበቀ' : 'Secured with PIN & Biometrics')
                                  : (isAm ? 'በ 4-አሃዝ ፒን የተጠበቀ' : 'Secured with 4-Digit PIN'))
                              : (isAm ? 'መቆለፊያ አልተመረጠም' : 'No lock configured'),
                        ),
                        value: _appLockEnabled,
                        onChanged: (val) => _toggleAppLock(val),
                      ),
                      if (_appLockEnabled) ...[
                        const Divider(height: 1),
                        ListTile(
                          leading: const Icon(Icons.password_rounded),
                          title: Text(isAm ? 'የደህንነት ፒን ቀይር' : 'Change Security PIN'),
                          trailing: const Icon(Icons.chevron_right),
                          onTap: _changePin,
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // ==========================================
                // 8. ABOUT
                // ==========================================
                _buildSectionHeader(isAm ? '8. ስለ መተግበሪያው (About)' : '8. About'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Column(
                    children: [
                      ListTile(
                        leading: Container(
                          padding: const EdgeInsets.all(6),
                          decoration: BoxDecoration(
                            color: const Color(0xFF0F766E).withValues(alpha: 0.1),
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: const Icon(Icons.inventory_2, color: Color(0xFF0F766E), size: 24),
                        ),
                        title: Text(isAm ? 'ዕቃ ዝርዝር (Inventory)' : 'Inventory'),
                        subtitle: const Text('Version 1.0.0 (Build 1) • Offline-First'),
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.bug_report_outlined, color: Colors.blueGrey),
                        title: Text(isAm ? 'ስህተቶች እና መረጃ (Diagnostics)' : 'Diagnostics & Error Log'),
                        subtitle: Text(
                          isAm
                              ? 'የቅርብ 50 ስህተቶችን ይመልከቱ ወይም ሪፖርት ያጋሩ'
                              : 'View recent errors ring buffer and share support report',
                        ),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () {
                          Navigator.of(context).push(
                            MaterialPageRoute(builder: (ctx) => const ErrorLogScreen()),
                          );
                        },
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.article_outlined),
                        title: Text(isAm ? 'የክፍት ምንጭ ፈቃዶች' : 'Open Source Licenses'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () {
                          showLicensePage(
                            context: context,
                            applicationName: isAm ? 'ዕቃ ዝርዝር (Inventory)' : 'Inventory',
                            applicationVersion: '1.0.0+1',
                            applicationLegalese: 'MIT License • Designed for Ethiopian Retailers',
                          );
                        },
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.privacy_tip_outlined),
                        title: Text(isAm ? 'የግላዊነት መመሪያ' : 'Privacy Policy'),
                        trailing: const Icon(Icons.open_in_new, size: 18),
                        onTap: () => _openLegalUrl('https://stock.yenesuq.com/privacy', isAm ? 'የግላዊነት መመሪያ' : 'Privacy Policy'),
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.gavel_outlined),
                        title: Text(isAm ? 'የአጠቃቀም ደንቦች' : 'Terms of Service'),
                        trailing: const Icon(Icons.open_in_new, size: 18),
                        onTap: () => _openLegalUrl('https://stock.yenesuq.com/terms', isAm ? 'የአጠቃቀም ደንቦች' : 'Terms of Service'),
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.support_agent_outlined, color: Color(0xFF0F766E)),
                        title: Text(isAm ? 'እርዳታ እና ድጋፍ' : 'Support & Help'),
                        subtitle: const Text('support@yenesuq.com • @yenesuq_support'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: _showSupportDialog,
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.share_outlined, color: Color(0xFF0F766E)),
                        title: Text(isAm ? 'መተግበሪያውን አጋራ' : 'Share the App'),
                        subtitle: Text(
                          isAm
                              ? 'መተግበሪያውን ለሌሎች የሱቅ ባለቤቶች ያጋሩ'
                              : 'Recommend Inventory to fellow shop owners',
                        ),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: _shareApp,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 32),
              ],
            ),
    );
  }

  Widget _buildSectionHeader(String title) {
    return Padding(
      padding: const EdgeInsets.only(left: 4, bottom: 8),
      child: Text(
        title,
        style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: Colors.grey),
      ),
    );
  }

  Future<void> _showPaymentMethodsDialog() async {
    final available = ['cash', 'bank', 'wallet', 'dube'];
    final selected = List<String>.from(_paymentMethodsEnabled);

    final updated = await showDialog<List<String>>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) {
          return AlertDialog(
            title: const Text('Payment Methods / የክፍያ ዘዴዎች'),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              children: available.map((m) {
                final isChecked = selected.contains(m);
                final label = switch (m) {
                  'cash' => '💵 Cash (ጥሬ ገንዘብ)',
                  'bank' => '🏦 Bank (ባንክ)',
                  'wallet' => '📱 Wallet (ዋሌት)',
                  'dube' => '📝 Dube (ዱቤ)',
                  _ => m,
                };
                return CheckboxListTile(
                  title: Text(label),
                  value: isChecked,
                  onChanged: (val) {
                    if (val == true) {
                      selected.add(m);
                    } else if (selected.length > 1) {
                      selected.remove(m);
                    }
                    setDlgState(() {});
                  },
                );
              }).toList(),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Cancel')),
              FilledButton(
                onPressed: () => Navigator.of(ctx).pop(selected),
                child: const Text('Save'),
              ),
            ],
          );
        },
      ),
    );

    if (updated != null) {
      await widget.repository.setPaymentMethodsEnabled(updated);
      await _loadAllSettings();
    }
  }

  Future<void> _pickDubeDefaultDays() async {
    final ctrl = TextEditingController(text: _dubeDefaultDays.toString());
    final picked = await showDialog<int>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Default Dube Days / ነባሪ የዱቤ ቀናት'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: ctrl,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Days / ቀናት', border: OutlineInputBorder()),
            ),
            const SizedBox(height: 12),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceEvenly,
              children: [
                ActionChip(label: const Text('7 Days'), onPressed: () => Navigator.of(ctx).pop(7)),
                ActionChip(label: const Text('14 Days'), onPressed: () => Navigator.of(ctx).pop(14)),
                ActionChip(label: const Text('30 Days'), onPressed: () => Navigator.of(ctx).pop(30)),
              ],
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Cancel')),
          FilledButton(
            onPressed: () {
              final d = int.tryParse(ctrl.text);
              if (d != null && d > 0) Navigator.of(ctx).pop(d);
            },
            child: const Text('Save'),
          ),
        ],
      ),
    );

    if (picked != null) {
      await widget.repository.setDubeDefaultDays(picked);
      await _loadAllSettings();
    }
  }

  Future<void> _manageFavoriteProviders() async {
    final allBanks = await widget.repository.listPaymentProviders(kind: 'bank');
    final allWallets = await widget.repository.listPaymentProviders(kind: 'wallet');

    if (!mounted) return;

    await showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheetState) {
          return Container(
            constraints: BoxConstraints(maxHeight: MediaQuery.of(ctx).size.height * 0.8),
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text('Favorite Providers / ተመራጭ ባንኮች እና ዋሌቶች', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                const SizedBox(height: 6),
                const Text('Star your frequent banks and mobile wallets for quick access at checkout.', style: TextStyle(fontSize: 12, color: Colors.grey)),
                const Divider(height: 16),
                Expanded(
                  child: ListView(
                    children: [
                      const Text('Banks / ባንኮች', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.teal)),
                      ...allBanks.map((b) => CheckboxListTile(
                        dense: true,
                        secondary: Icon(b.favorite ? Icons.star : Icons.star_border, color: b.favorite ? Colors.amber : Colors.grey),
                        title: Text(b.name ?? b.providerKey),
                        value: b.favorite,
                        onChanged: (val) async {
                          await widget.repository.setPaymentProviderFavorite(b.providerKey, val ?? false);
                          final updated = await widget.repository.listPaymentProviders(kind: 'bank');
                          setSheetState(() {
                            allBanks.clear();
                            allBanks.addAll(updated);
                          });
                        },
                      )),
                      const SizedBox(height: 12),
                      const Text('Wallets / ዋሌቶች', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.teal)),
                      ...allWallets.map((w) => CheckboxListTile(
                        dense: true,
                        secondary: Icon(w.favorite ? Icons.star : Icons.star_border, color: w.favorite ? Colors.amber : Colors.grey),
                        title: Text(w.name ?? w.providerKey),
                        value: w.favorite,
                        onChanged: (val) async {
                          await widget.repository.setPaymentProviderFavorite(w.providerKey, val ?? false);
                          final updated = await widget.repository.listPaymentProviders(kind: 'wallet');
                          setSheetState(() {
                            allWallets.clear();
                            allWallets.addAll(updated);
                          });
                        },
                      )),
                    ],
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  Future<void> _pickDubeReminderTime() async {
    final parts = _dubeReminderTime.split(':');
    final initial = TimeOfDay(
      hour: int.tryParse(parts.first) ?? 9,
      minute: parts.length > 1 ? (int.tryParse(parts[1]) ?? 0) : 0,
    );

    final picked = await showTimePicker(context: context, initialTime: initial);
    if (picked != null) {
      final formatted = '${picked.hour.toString().padLeft(2, '0')}:${picked.minute.toString().padLeft(2, '0')}';
      await widget.repository.setDubeReminderTime(formatted);
      await _notificationService.scheduleDubeDueReminder(enabled: _dubeReminderEnabled, timeStr: formatted);
      await _loadAllSettings();
    }
  }
}
