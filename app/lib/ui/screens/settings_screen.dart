import 'dart:io';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:local_auth/local_auth.dart';
import 'package:path/path.dart' as p;

import '../../database/app_database.dart';
import '../../repositories/ledger_repository.dart';
import '../../services/api_client.dart';
import '../../services/auth_storage.dart';
import '../../services/backup_service.dart';
import '../../services/export_service.dart';
import '../../services/notification_service.dart';
import '../../services/sync_engine.dart';
import '../../utils/quantity_formatter.dart';
import '../widgets/warehouse_setup_sheet.dart';
import 'pairing_screen.dart';

class SettingsScreen extends StatefulWidget {
  final ApiClient apiClient;
  final AuthStorage authStorage;
  final SyncEngine syncEngine;
  final LedgerRepository repository;
  final AppDatabase db;

  const SettingsScreen({
    super.key,
    required this.apiClient,
    required this.authStorage,
    required this.syncEngine,
    required this.repository,
    required this.db,
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
  String _currency = 'ETB';
  int _defaultLowStockMilli = 5000;
  bool _allowNegativeStock = false;
  bool _blockSaleWhenMaterialShort = false;

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
    _backupService = BackupService(db: widget.db, repository: widget.repository);
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
    final curr = await widget.repository.getCurrencyLabel();
    final lowStock = await widget.repository.getDefaultLowStockThresholdMilli();
    final allowNeg = await widget.repository.getAllowNegativeStock();
    final blockMatShort = await widget.repository.getBlockSaleWhenMaterialShort();

    // Notifications
    final notifs = await widget.repository.isNotificationsEnabled();
    final lowNotifs = await widget.repository.isLowStockNotificationsEnabled();
    final dailySummary = await widget.repository.isDailySummaryNotificationEnabled();
    final dailyTime = await widget.repository.getDailySummaryTime();

    // Security
    final lockEnabled = await widget.repository.isAppLockEnabled();
    final auth = LocalAuthentication();
    bool bioSupported = false;
    try {
      bioSupported = await auth.canCheckBiometrics || await auth.isDeviceSupported();
    } catch (_) {}

    // Locations
    final locEnabled = await widget.repository.getLocationsEnabled();
    final reqReceipt = await widget.repository.getTransferRequiresReceipt();
    final activeLoc = await widget.repository.getActiveLocation();
    final allLocs = await widget.repository.listLocations();

    // Backups
    final backups = await _backupService.listAllBackups();

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
        _currency = curr;
        _defaultLowStockMilli = lowStock;
        _allowNegativeStock = allowNeg;
        _blockSaleWhenMaterialShort = blockMatShort;
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
        title: const Text('Settings / ቅንብሮች'),
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                // Section 1: Shop & Language Preferences
                _buildSectionHeader(isAm ? 'የሱቅ መረጃ እና ቋንቋ' : 'Shop & Language'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Column(
                    children: [
                      ListTile(
                        leading: const Icon(Icons.storefront_outlined),
                        title: const Text('Shop Name / የሱቅ ስም'),
                        subtitle: Text(_shopName ?? 'Inventory'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: _editShopName,
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.language_outlined),
                        title: const Text('Language / ቋንቋ'),
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
                            await _loadAllSettings();
                          },
                        ),
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.monetization_on_outlined),
                        title: const Text('Currency Label / የገንዘብ ምልክት'),
                        subtitle: Text(_currency),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: _editCurrency,
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.warning_amber_outlined),
                        title: const Text('Default Low Stock Threshold'),
                        subtitle: Text('${QuantityFormatter.formatDisplay(_defaultLowStockMilli)} units'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: _editDefaultLowStock,
                      ),
                      const Divider(height: 1),
                      SwitchListTile(
                        secondary: const Icon(Icons.remove_circle_outline),
                        title: const Text('Allow Negative Stock / ከዜሮ በታች ሽያጭ'),
                        subtitle: const Text('Permit sales even if inventory drops below zero'),
                        value: _allowNegativeStock,
                        onChanged: (val) async {
                          await widget.repository.setAllowNegativeStock(val);
                          await _loadAllSettings();
                        },
                      ),
                      const Divider(height: 1),
                      SwitchListTile(
                        secondary: const Icon(Icons.inventory_2_outlined),
                        title: const Text('Block sale when raw materials are short / ጥሬ ዕቃ ሲያጥር ሽያጭ ከልክል'),
                        subtitle: const Text('Prevent selling consumables if raw material stock is insufficient'),
                        value: _blockSaleWhenMaterialShort,
                        onChanged: (val) async {
                          await widget.repository.setBlockSaleWhenMaterialShort(val);
                          await _loadAllSettings();
                        },
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // Section: Warehouse & Locations
                _buildSectionHeader(isAm ? 'መጋዘን እና ስቶክ ቦታዎች' : 'Warehouse & Locations'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Column(
                    children: [
                      if (!_locationsEnabled) ...[
                        ListTile(
                          leading: const Icon(Icons.warehouse_outlined, color: Color(0xFF0F766E)),
                          title: const Text('Use warehouse and store / መጋዘን እና ሱቅ ተጠቀም'),
                          subtitle: const Text('Separate stock between store front and warehouse, with transfers.'),
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
                          title: const Text('Active Device Location / የዚህ ስልክ ነባሪ ቦታ'),
                          subtitle: Text(_activeLocation?.name ?? 'Store'),
                          trailing: const Icon(Icons.chevron_right),
                          onTap: _pickActiveDeviceLocation,
                        ),
                        const Divider(height: 1),
                        SwitchListTile(
                          secondary: const Icon(Icons.receipt_long_outlined),
                          title: const Text('Transfers Require Receipt / የዝውውር ደረሰኝ ማረጋገጫ'),
                          subtitle: const Text('Sender dispatches; receiver must verify and confirm received quantity.'),
                          value: _transferRequiresReceipt,
                          onChanged: (val) async {
                            await widget.repository.setTransferRequiresReceipt(val);
                            await _loadAllSettings();
                          },
                        ),
                        const Divider(height: 1),
                        ListTile(
                          leading: const Icon(Icons.power_settings_new_rounded, color: Colors.red),
                          title: const Text(
                            'Turn off warehouse and store / መጋዘን እና ሱቅ አጥፋ',
                            style: TextStyle(color: Colors.red),
                          ),
                          subtitle: const Text('Allowed only if all stock sits in a single location.'),
                          onTap: _disableWarehouseAndStore,
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // Section: Payment Methods & Dube
                _buildSectionHeader(isAm ? 'የክፍያ ዘዴዎች እና ዱቤ' : 'Payment Methods & Dube'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Column(
                    children: [
                      ListTile(
                        leading: const Icon(Icons.payment),
                        title: const Text('Enabled Payment Methods / የተፈቀዱ የክፍያ ዘዴዎች'),
                        subtitle: Text(_paymentMethodsEnabled.map((m) => m.toUpperCase()).join(', ')),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: _showPaymentMethodsDialog,
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.calendar_month),
                        title: const Text('Default Dube Days / ነባሪ የዱቤ ቀናት'),
                        subtitle: Text('$_dubeDefaultDays days'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: _pickDubeDefaultDays,
                      ),
                      const Divider(height: 1),
                      SwitchListTile(
                        secondary: const Icon(Icons.pin_outlined),
                        title: const Text('Require Payment Reference'),
                        subtitle: const Text('Require transaction/reference number for Bank & Wallet'),
                        value: _requireReference,
                        onChanged: (val) async {
                          await widget.repository.setRequirePaymentReference(val);
                          await _loadAllSettings();
                        },
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.star_outline),
                        title: const Text('Favorite Banks & Wallets / ተመራጭ ባንኮች'),
                        subtitle: const Text('Configure star favorites for quick selection'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: _manageFavoriteProviders,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // Section 2: Notifications
                _buildSectionHeader(isAm ? 'ማሳወቂያዎች' : 'Notifications'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Column(
                    children: [
                      SwitchListTile(
                        secondary: const Icon(Icons.notifications_active_outlined),
                        title: const Text('Enable Notifications / ማሳወቂያዎች'),
                        value: _notificationsEnabled,
                        onChanged: (val) async {
                          await widget.repository.setNotificationsEnabled(val);
                          await _loadAllSettings();
                        },
                      ),
                      if (_notificationsEnabled) ...[
                        const Divider(height: 1),
                        SwitchListTile(
                          secondary: const Icon(Icons.warning_amber_rounded, color: Colors.amber),
                          title: const Text('Low Stock Alerts'),
                          subtitle: const Text('Notify when a sale reduces stock below limit'),
                          value: _lowStockNotifications,
                          onChanged: (val) async {
                            await widget.repository.setLowStockNotificationsEnabled(val);
                            await _loadAllSettings();
                          },
                        ),
                        const Divider(height: 1),
                        SwitchListTile(
                          secondary: const Icon(Icons.summarize_outlined),
                          title: const Text('Daily Sales Summary'),
                          subtitle: Text('Scheduled daily at $_dailySummaryTime'),
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
                            title: const Text('Daily Summary Time'),
                            subtitle: Text(_dailySummaryTime),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: _pickDailySummaryTime,
                          ),
                        ],
                        const Divider(height: 1),
                        SwitchListTile(
                          secondary: const Icon(Icons.alarm, color: Colors.deepOrange),
                          title: const Text('Dube Due Reminders'),
                          subtitle: Text('Offline reminder at $_dubeReminderTime for dube due today'),
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
                            title: const Text('Dube Reminder Time'),
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

                // Section 3: App Security (PIN / Biometrics)
                _buildSectionHeader(isAm ? 'ደህንነት' : 'App Security'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Column(
                    children: [
                      SwitchListTile(
                        secondary: const Icon(Icons.lock_outline),
                        title: const Text('PIN / Biometric Lock'),
                        subtitle: Text(
                          _appLockEnabled
                              ? (_canUseBiometrics ? 'Secured with PIN & Biometrics' : 'Secured with 4-Digit PIN')
                              : 'No lock configured',
                        ),
                        value: _appLockEnabled,
                        onChanged: (val) => _toggleAppLock(val),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // Section 4: Data Export (CSV UTF-8 with BOM)
                _buildSectionHeader(isAm ? 'ዳታ ወደ CSV ላክ' : 'Export Data (CSV)'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Column(
                    children: [
                      ListTile(
                        leading: const Icon(Icons.inventory_2_outlined, color: Color(0xFF0F766E)),
                        title: const Text('Export Products Catalog CSV'),
                        subtitle: const Text('Includes stock levels, costs, and selling prices (UTF-8 BOM)'),
                        trailing: const Icon(Icons.share),
                        onTap: _exportProductsCsv,
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.point_of_sale, color: Colors.green),
                        title: const Text('Export Sales Ledger CSV'),
                        subtitle: const Text('Customer transactions with quantities and revenue'),
                        trailing: const Icon(Icons.share),
                        onTap: _exportSalesCsv,
                      ),
                      const Divider(height: 1),
                      ListTile(
                        leading: const Icon(Icons.history, color: Colors.blue),
                        title: const Text('Export All Movements CSV'),
                        subtitle: const Text('Full audit ledger (sales, intake, adjustments)'),
                        trailing: const Icon(Icons.share),
                        onTap: _exportMovementsCsv,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // Section 5: Database Backup & Restore
                _buildSectionHeader(isAm ? 'ኮፒ እና መመለሻ (Backup / Restore)' : 'Database Backup & Restore'),
                Card(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        FilledButton.icon(
                          style: FilledButton.styleFrom(
                            backgroundColor: const Color(0xFF0F766E),
                            padding: const EdgeInsets.symmetric(vertical: 14),
                          ),
                          onPressed: _createAndShareBackup,
                          icon: const Icon(Icons.backup_outlined),
                          label: const Text('Create & Share Database Backup'),
                        ),
                        const SizedBox(height: 12),
                        const Text(
                          'Creates a single .sqlite file containing all products and movements. You can share it to Telegram, Google Drive, or transfer to a new phone.',
                          style: TextStyle(fontSize: 12, color: Colors.grey),
                        ),
                        if (_localBackups.isNotEmpty) ...[
                          const SizedBox(height: 16),
                          const Divider(),
                          const SizedBox(height: 8),
                          const Text(
                            'Local Backups & Weekly Snapshots (Last 4):',
                            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
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
                                      onPressed: () => _backupService.shareBackup(f),
                                    ),
                                    IconButton(
                                      icon: const Icon(Icons.restore, size: 18, color: Colors.red),
                                      onPressed: () => _promptRestoreBackup(f),
                                    ),
                                  ],
                                ),
                              );
                            },
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 20),

                // Section 6: Cloud Sync & Telegram Integration
                _buildSectionHeader(isAm ? 'የኦንላይን ግንኙነት እና ቴሌግራም' : 'Online Sync & Telegram'),
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
                                    _isOnline ? 'Online Sync Active' : 'Offline Mode (ኦፍላይን)',
                                    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                                  ),
                                  Text(
                                    _isOnline
                                        ? 'Device: $_deviceName${_shopName != null ? ' • Shop: $_shopName' : ''}'
                                        : 'Working locally without internet connection',
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
                            label: const Text('Go Online / Connect Telegram'),
                          ),
                        ] else ...[
                          OutlinedButton.icon(
                            style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(46)),
                            onPressed: _goOfflineAgain,
                            icon: const Icon(Icons.cloud_off),
                            label: const Text('Go Offline Again (Keep local data)'),
                          ),
                          const SizedBox(height: 10),
                          FilledButton.icon(
                            style: FilledButton.styleFrom(
                              backgroundColor: Colors.red.shade800,
                              minimumSize: const Size.fromHeight(46),
                            ),
                            onPressed: _deleteOnlineData,
                            icon: const Icon(Icons.delete_forever),
                            label: const Text('Delete Online Shop on Server'),
                          ),
                        ],
                      ],
                    ),
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
