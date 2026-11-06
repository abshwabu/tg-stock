import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'database/app_database.dart';
import 'l10n/app_localizations.dart';
import 'repositories/ledger_repository.dart';
import 'services/api_client.dart';
import 'services/app_update_service.dart';
import 'services/auth_storage.dart';
import 'services/error_logger.dart';
import 'services/sync_engine.dart';
import 'ui/screens/first_run_screen.dart';
import 'ui/screens/home_screen.dart';
import 'ui/theme.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();

  // Global Error Handler without exposing raw stack traces to users
  FlutterError.onError = (details) {
    ErrorLogger.instance.log(details.exception, details.stack, context: 'FlutterError');
    FlutterError.presentError(details);
  };
  PlatformDispatcher.instance.onError = (error, stack) {
    ErrorLogger.instance.log(error, stack, context: 'PlatformDispatcher');
    return true;
  };

  final db = AppDatabase();
  final repository = LedgerRepository(db);
  final authStorage = SecureAuthStorage();
  final apiClient = ApiClient();
  final updateService = AppUpdateService(
    authStorage: authStorage,
    repository: repository,
  );
  final syncEngine = SyncEngine(
    db: db,
    api: apiClient,
    storage: authStorage,
    updateService: updateService,
  );

  runApp(InventoryApp(
    db: db,
    repository: repository,
    apiClient: apiClient,
    authStorage: authStorage,
    syncEngine: syncEngine,
    updateService: updateService,
  ));
}

class InventoryApp extends StatefulWidget {
  final AppDatabase db;
  final LedgerRepository repository;
  final ApiClient apiClient;
  final AuthStorage authStorage;
  final SyncEngine syncEngine;
  final AppUpdateService? updateService;

  const InventoryApp({
    super.key,
    required this.db,
    required this.repository,
    required this.apiClient,
    required this.authStorage,
    required this.syncEngine,
    this.updateService,
  });

  static InventoryAppState? of(BuildContext context) {
    return context.findAncestorStateOfType<InventoryAppState>();
  }

  @override
  State<InventoryApp> createState() => InventoryAppState();
}

class InventoryAppState extends State<InventoryApp> {
  bool _isLoading = true;
  bool _firstRunCompleted = true;
  bool _isLocked = false;
  String _localeStr = 'am';
  ThemeMode _themeMode = ThemeMode.system;
  String? _savedPin;

  final _pinInputController = TextEditingController();
  String? _pinError;

  void setThemeMode(ThemeMode mode) {
    setState(() {
      _themeMode = mode;
    });
  }

  void setLocale(String lang) {
    setState(() {
      _localeStr = lang;
    });
  }

  @override
  void initState() {
    super.initState();
    _checkAppEntry();
  }

  Future<void> _checkAppEntry() async {
    final firstRun = await widget.repository.isFirstRunCompleted();
    final lockEnabled = await widget.repository.isAppLockEnabled();
    final pin = await widget.repository.getAppLockPin();
    final lang = await widget.repository.getLanguage();
    final themeStr = await widget.repository.getThemeMode();

    final themeMode = switch (themeStr) {
      'light' => ThemeMode.light,
      'dark' => ThemeMode.dark,
      _ => ThemeMode.system,
    };

    if (mounted) {
      setState(() {
        _firstRunCompleted = firstRun;
        _isLocked = lockEnabled && (pin != null && pin.isNotEmpty);
        _savedPin = pin;
        _localeStr = lang;
        _themeMode = themeMode;
        _isLoading = false;
      });
    }
  }

  void _verifyPin() {
    if (_pinInputController.text == _savedPin) {
      setState(() {
        _isLocked = false;
      });
    } else {
      setState(() {
        _pinError = _localeStr == 'am' ? 'የተሳሳተ ኮድ! እንደገና ይሞክሩ' : 'Incorrect PIN! Try again';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: AppTheme.lightTheme,
        darkTheme: AppTheme.darkTheme,
        themeMode: _themeMode,
        home: const Scaffold(body: Center(child: CircularProgressIndicator())),
      );
    }

    Widget rootWidget;

    if (!_firstRunCompleted) {
      rootWidget = FirstRunScreen(
        repository: widget.repository,
        onComplete: () async {
          await _checkAppEntry();
        },
      );
    } else if (_isLocked) {
      rootWidget = Scaffold(
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(28),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(Icons.lock_rounded, size: 64, color: Color(0xFF0F766E)),
                const SizedBox(height: 16),
                Text(
                  _localeStr == 'am' ? 'መተግበሪያው ተቆልፏል' : 'App Locked',
                  style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 8),
                Text(
                  _localeStr == 'am' ? 'እባክዎ ባለ 4-አሃዝ ኮድዎን ያስገቡ' : 'Please enter your 4-digit PIN',
                  style: const TextStyle(color: Colors.grey),
                ),
                const SizedBox(height: 20),
                SizedBox(
                  width: 200,
                  child: TextField(
                    controller: _pinInputController,
                    keyboardType: TextInputType.number,
                    obscureText: true,
                    maxLength: 4,
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 24, letterSpacing: 8),
                    decoration: InputDecoration(
                      errorText: _pinError,
                      border: const OutlineInputBorder(),
                    ),
                    onSubmitted: (_) => _verifyPin(),
                  ),
                ),
                const SizedBox(height: 12),
                FilledButton(
                  onPressed: _verifyPin,
                  child: Text(_localeStr == 'am' ? 'ክፈት' : 'Unlock'),
                ),
              ],
            ),
          ),
        ),
      );
    } else {
      rootWidget = HomeScreen(
        db: widget.db,
        repository: widget.repository,
        apiClient: widget.apiClient,
        authStorage: widget.authStorage,
        syncEngine: widget.syncEngine,
        updateService: widget.updateService,
      );
    }

    final isAm = _localeStr == 'am';
    return MaterialApp(
      title: isAm ? 'ዕቃ ዝርዝር' : 'Inventory',
      debugShowCheckedModeBanner: false,
      locale: Locale(_localeStr),
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: const [
        Locale('am'),
        Locale('en'),
      ],
      theme: AppTheme.lightTheme,
      darkTheme: AppTheme.darkTheme,
      themeMode: _themeMode,
      home: rootWidget,
    );
  }
}
