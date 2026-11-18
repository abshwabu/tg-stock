import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:app/database/app_database.dart';
import 'package:app/repositories/ledger_repository.dart';
import 'package:app/services/api_client.dart';
import 'package:app/services/auth_storage.dart';
import 'package:app/services/backup_service.dart';
import 'package:app/services/demo_data_service.dart';
import 'package:app/services/error_logger.dart';
import 'package:app/services/sync_engine.dart';
import 'package:app/ui/screens/first_run_screen.dart';
import 'package:app/ui/screens/history_screen.dart';
import 'package:app/ui/screens/home_screen.dart';
import 'package:app/ui/screens/products_screen.dart';
import 'package:app/ui/screens/settings_screen.dart';
import 'package:app/ui/theme.dart';
import 'package:app/ui/widgets/empty_state_widget.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('ErrorLogger Ring Buffer & Sanitization', () {
    setUp(() {
      ErrorLogger.instance.clear();
    });

    test('Ring buffer strictly caps at 50 entries', () {
      final logger = ErrorLogger.instance;
      for (int i = 1; i <= 65; i++) {
        logger.log('Error entry $i', null);
      }

      final entries = logger.logs;
      expect(entries.length, equals(50));
      expect(entries.first.message, equals('Error entry 65'));
      expect(entries.last.message, equals('Error entry 16'));
    });

    test('Sanitizes phone numbers, UUIDs, and absolute paths', () {
      final logger = ErrorLogger.instance;
      logger.log('Customer with phone 0911223344 and +251912345678 failed payment', null);
      logger.log('Failed file at /data/user/0/com.inventory/app_flutter/test.sqlite', null);
      logger.log('Missing UUID 018d0000-1234-7000-8000-000000000001', null);

      final entries = logger.logs;
      expect(entries[2].message, contains('[REDACTED_PHONE]'));
      expect(entries[2].message, isNot(contains('0911223344')));
      expect(entries[2].message, isNot(contains('+251912345678')));

      expect(entries[0].message, contains('[ID]'));
    });

    test('Generates support report markdown', () {
      final logger = ErrorLogger.instance;
      logger.log('Initial startup failure: Database locked', null);
      final report = logger.formatSupportReport();

      expect(report, contains('=== Inventory App Diagnostics Report ==='));
      expect(report, contains('Initial startup failure'));
      expect(report, contains('Database locked'));
    });
  });

  group('DemoDataService Loading & Removal', () {
    late AppDatabase db;
    late LedgerRepository repo;
    late DemoDataService demoService;

    setUp(() {
      db = AppDatabase.inMemory();
      repo = LedgerRepository(db);
      demoService = DemoDataService(repository: repo);
    });

    tearDown(() async {
      await db.close();
    });

    test('Loads 5 realistic Ethiopian shop items and removes them in one tap', () async {
      expect(await demoService.isDemoProductsLoaded(), isFalse);

      await demoService.loadDemoProducts();
      expect(await demoService.isDemoProductsLoaded(), isTrue);

      final products = await repo.listProducts();
      expect(products.length, equals(5));

      final names = products.map((p) => p.product.name).toList();
      expect(names, contains('ጤፍ (ማኛ)'));
      expect(names, contains('ስኳር (1 ኪ.ግ)'));
      expect(names, contains('የቡና አገልግሎት'));

      // Remove demo items
      await demoService.removeDemoProducts();
      expect(await demoService.isDemoProductsLoaded(), isFalse);

      final remaining = await repo.listProducts();
      expect(remaining.isEmpty, isTrue);
    });
  });

  group('Backup Reminder Logic', () {
    late AppDatabase db;
    late LedgerRepository repo;
    late BackupService backupService;

    setUp(() {
      db = AppDatabase.inMemory();
      repo = LedgerRepository(db);
      backupService = BackupService(db: db, repository: repo);
    });

    tearDown(() async {
      await db.close();
    });

    test('Overdue when never backed up and shop has items', () async {
      await repo.createProduct(name: 'Salt (ጨው)', sellPriceCents: 1000);
      await repo.setBackupReminderDays(7);
      await repo.setLastBackupMs(null);

      final isOverdue = await backupService.isBackupOverdue();
      expect(isOverdue, isTrue);
    });

    test('Not overdue when backed up recently within reminder days', () async {
      await repo.createProduct(name: 'Salt (ጨው)', sellPriceCents: 1000);
      await repo.setBackupReminderDays(7);
      final twoDaysAgoMs = DateTime.now().subtract(const Duration(days: 2)).millisecondsSinceEpoch;
      await repo.setLastBackupMs(twoDaysAgoMs);

      final isOverdue = await backupService.isBackupOverdue();
      expect(isOverdue, isFalse);
    });

    test('Overdue when last backup exceeds reminder days', () async {
      await repo.createProduct(name: 'Salt (ጨው)', sellPriceCents: 1000);
      await repo.setBackupReminderDays(7);
      final tenDaysAgoMs = DateTime.now().subtract(const Duration(days: 10)).millisecondsSinceEpoch;
      await repo.setLastBackupMs(tenDaysAgoMs);

      final isOverdue = await backupService.isBackupOverdue();
      expect(isOverdue, isTrue);
    });

    test('Never overdue if reminder frequency is set to 0 (off)', () async {
      await repo.createProduct(name: 'Salt (ጨው)', sellPriceCents: 1000);
      await repo.setBackupReminderDays(0);
      await repo.setLastBackupMs(null);

      final isOverdue = await backupService.isBackupOverdue();
      expect(isOverdue, isFalse);
    });
  });

  group('First-Run Flow Widget Test', () {
    late AppDatabase db;
    late LedgerRepository repo;

    setUp(() {
      db = AppDatabase.inMemory();
      repo = LedgerRepository(db);
    });

    tearDown(() async {
      await db.close();
    });

    testWidgets('Completes first run flow through language, shop name, locations, and intro cards', (tester) async {
      bool completed = false;

      await tester.pumpWidget(
        MaterialApp(
          home: FirstRunScreen(
            repository: repo,
            onComplete: () {
              completed = true;
            },
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump();

      // Step 0: Language & Shop Name
      expect(find.textContaining('እንኳን ወደ'), findsOneWidget);
      expect(find.text('ቀጥል'), findsOneWidget);

      // Enter shop name
      final shopNameField = find.byType(TextFormField).first;
      await tester.enterText(shopNameField, 'አበበ ሱቅ');
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump();

      // Tap Next
      await tester.tap(find.text('ቀጥል'));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump();

      // Step 1: Locations Question
      expect(find.textContaining('ዕቃ ከአንድ ቦታ በላይ ያስቀምጣሉ?'), findsOneWidget);
      expect(find.textContaining('አይ፣ በአንድ ቦታ'), findsOneWidget);

      final oneShopOption = find.textContaining('አይ፣ በአንድ ቦታ');
      await tester.ensureVisible(oneShopOption);
      await tester.tap(oneShopOption);
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump();

      // Tap Next to Step 2
      final nextBtn1 = find.widgetWithText(FilledButton, 'ቀጥል');
      await tester.ensureVisible(nextBtn1);
      await tester.tap(nextBtn1);
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump();

      // Step 2: Intro cards
      expect(find.textContaining('ያለ ኢንተርኔት ይሰራል'), findsWidgets);
      expect(find.text('ዝለል'), findsOneWidget);

      // Tap Skip to complete
      await tester.tap(find.text('ዝለል'));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump();

      expect(completed, isTrue);
      expect(await repo.isFirstRunCompleted(), isTrue);
      expect(await repo.getShopName(), equals('አበበ ሱቅ'));
    });
  });

  group('Accessibility & Visual Polish at Text Scale 2.0', () {
    testWidgets('EmptyStateWidget renders cleanly at 200% text scale without overflow', (tester) async {
      tester.view.physicalSize = const Size(800, 1280);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() => tester.view.resetPhysicalSize());

      await tester.pumpWidget(
        MaterialApp(
          theme: ThemeData.light(),
          home: MediaQuery(
            data: const MediaQueryData(textScaler: TextScaler.linear(2.0)),
            child: Scaffold(
              body: EmptyStateWidget(
                icon: Icons.inventory_2_outlined,
                title: 'ምንም የተመዘገበ ዕቃ የለም',
                description: 'የመጀመሪያ ዕቃዎን በመመዝገብ ወይም የሙከራ ዕቃዎችን በመጫን ሽያጭ ይጀምሩ።',
                primaryButtonLabel: 'አዲስ ዕቃ መዝግብ',
                primaryButtonIcon: Icons.add,
                onPrimaryPressed: () {},
                secondaryButtonLabel: 'የሙከራ ዕቃዎች ጫን',
                secondaryButtonIcon: Icons.auto_awesome,
                onSecondaryPressed: () {},
              ),
            ),
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump();

      expect(find.text('ምንም የተመዘገበ ዕቃ የለም'), findsOneWidget);
      expect(find.text('አዲስ ዕቃ መዝግብ'), findsOneWidget);
      expect(find.text('የሙከራ ዕቃዎች ጫን'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('SettingsScreen renders all 8 sections cleanly under Dark theme and text scale 2.0', (tester) async {
      final db = AppDatabase.inMemory();
      final repo = LedgerRepository(db);
      final authStorage = InMemoryAuthStorage();
      final apiClient = ApiClient();
      final syncEngine = SyncEngine(db: db, api: apiClient, storage: authStorage);
      final tempDir = Directory.systemTemp.createTempSync('settings_test_backups');
      final backupService = BackupService(
        db: db,
        repository: repo,
        customBackupsDir: tempDir,
      );

      tester.view.physicalSize = const Size(800, 1600);
      tester.view.devicePixelRatio = 1.0;

      await tester.pumpWidget(
        MaterialApp(
          theme: ThemeData.dark(),
          home: MediaQuery(
            data: const MediaQueryData(textScaler: TextScaler.linear(2.0)),
            child: SettingsScreen(
              db: db,
              repository: repo,
              apiClient: apiClient,
              authStorage: authStorage,
              syncEngine: syncEngine,
              backupService: backupService,
            ),
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump();

      expect(find.text('1. የሱቅ መረጃ'), findsOneWidget);
      expect(find.text('2. ቋንቋ እና ገጽታ'), findsOneWidget);

      // Scroll to bottom section
      final aboutSection = find.text('8. ስለ መተግበሪያው');
      await tester.scrollUntilVisible(aboutSection, 300);
      expect(aboutSection, findsOneWidget);
      expect(tester.takeException(), isNull);

      await tester.pumpWidget(const SizedBox());
      await tester.pump(const Duration(seconds: 5));
      syncEngine.dispose();
      await db.close();
      if (tempDir.existsSync()) {
        try {
          tempDir.deleteSync(recursive: true);
        } catch (_) {}
      }
      tester.view.resetPhysicalSize();
    });

    testWidgets('HomeScreen theme toggle switches between light and dark modes', (tester) async {
      final db = AppDatabase.inMemory();
      final repository = LedgerRepository(db);
      final authStorage = InMemoryAuthStorage();
      final syncEngine = SyncEngine(
        db: db,
        api: ApiClient(),
        storage: authStorage,
      );

      await repository.setShopName('Test Shop');
      await repository.setLanguage('am');
      await repository.setThemeMode('light');

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          darkTheme: AppTheme.darkTheme,
          themeMode: ThemeMode.light,
          home: HomeScreen(
            db: db,
            repository: repository,
            apiClient: ApiClient(),
            authStorage: authStorage,
            syncEngine: syncEngine,
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump();

      // Theme toggle icon in light mode should be dark_mode_rounded
      expect(find.byIcon(Icons.dark_mode_rounded), findsOneWidget);
      expect(find.text('አማ'), findsOneWidget);

      // Tap theme toggle button
      await tester.tap(find.byIcon(Icons.dark_mode_rounded));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump();

      // Verify repository updated to 'dark'
      final savedTheme = await repository.getThemeMode();
      expect(savedTheme, equals('dark'));

      await tester.pumpWidget(const SizedBox());
      await tester.pump(const Duration(seconds: 5));
      syncEngine.dispose();
      await db.close();
    });

    testWidgets('HomeScreen low stock card displays "No low level stocks" when count is 0 and navigates to ProductsScreen on tap', (tester) async {
      final db = AppDatabase.inMemory();
      final repository = LedgerRepository(db);
      final authStorage = InMemoryAuthStorage();
      final syncEngine = SyncEngine(
        db: db,
        api: ApiClient(),
        storage: authStorage,
      );

      await repository.setShopName('Test Shop');
      await repository.setLanguage('en');

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          home: HomeScreen(
            db: db,
            repository: repository,
            apiClient: ApiClient(),
            authStorage: authStorage,
            syncEngine: syncEngine,
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump();

      // Verify "No low level stocks" is displayed on the Low Stock card
      expect(find.text('No low level stocks'), findsOneWidget);
      expect(find.text('Low Stock'), findsOneWidget);

      // Tap on the Low Stock card
      await tester.tap(find.text('No low level stocks'));
      await tester.pumpAndSettle();

      // ProductsScreen should now be opened with empty state "No low level stocks"
      expect(find.byType(ProductsScreen), findsOneWidget);
      expect(find.text('No low level stocks'), findsOneWidget);
      expect(find.text('All products currently have sufficient stock levels.'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
      await tester.pump(const Duration(seconds: 5));
      syncEngine.dispose();
      await db.close();
    });

    testWidgets("HomeScreen today's sales card navigates to HistoryScreen on tap", (tester) async {
      final db = AppDatabase.inMemory();
      final repository = LedgerRepository(db);
      final authStorage = InMemoryAuthStorage();
      final syncEngine = SyncEngine(
        db: db,
        api: ApiClient(),
        storage: authStorage,
      );

      await repository.setShopName('Test Shop');
      await repository.setLanguage('en');

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          home: HomeScreen(
            db: db,
            repository: repository,
            apiClient: ApiClient(),
            authStorage: authStorage,
            syncEngine: syncEngine,
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump();

      // Verify Today's Sales text is displayed
      expect(find.text("Today's Sales"), findsOneWidget);

      // Tap on Today's Sales card
      await tester.tap(find.text("Today's Sales"));
      await tester.pumpAndSettle();

      // HistoryScreen should now be opened
      expect(find.byType(HistoryScreen), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
      await tester.pump(const Duration(seconds: 5));
      syncEngine.dispose();
      await db.close();
    });

    testWidgets('ProductsScreen filters low stock items when initialFilterLowStock is true', (tester) async {
      final db = AppDatabase.inMemory();
      final repository = LedgerRepository(db);

      final p1 = await repository.createProduct(
        name: 'Item High Stock',
        sellPriceCents: 10000,
        costPriceCents: 8000,
        unit: 'pcs',
        kind: 'stockable',
        lowStockAtMilli: 5000,
      );
      await repository.recordStockIn(
        productId: p1.id,
        qtyMilli: 10000,
      );

      final p2 = await repository.createProduct(
        name: 'Item Low Stock',
        sellPriceCents: 15000,
        costPriceCents: 12000,
        unit: 'pcs',
        kind: 'stockable',
        lowStockAtMilli: 5000,
      );
      await repository.recordStockIn(
        productId: p2.id,
        qtyMilli: 2000,
      );

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          home: ProductsScreen(
            repository: repository,
            initialFilterLowStock: true,
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump();

      // Only the low stock product should be visible
      expect(find.text('Item Low Stock'), findsOneWidget);
      expect(find.text('Item High Stock'), findsNothing);

      // Tap Low Stock chip to toggle it off -> both products should show
      await tester.tap(find.text('Low Stock'));
      await tester.pumpAndSettle();

      expect(find.text('Item Low Stock'), findsOneWidget);
      expect(find.text('Item High Stock'), findsOneWidget);

      await db.close();
    });

    testWidgets('ProductsScreen shows duplicate name warning when adding a product with existing name', (tester) async {
      final db = AppDatabase.inMemory();
      final repository = LedgerRepository(db);

      await repository.createProduct(
        name: 'Coca Cola',
        sellPriceCents: 10000,
        costPriceCents: 7000,
        unit: 'pcs',
        kind: 'stockable',
      );

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          home: ProductsScreen(repository: repository),
        ),
      );
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump();

      // Open Add Item Dialog via FAB
      final fab = find.byType(FloatingActionButton);
      expect(fab, findsOneWidget);
      await tester.tap(fab);
      await tester.pumpAndSettle();

      // Dialog is open
      expect(find.text('Add Item'), findsOneWidget);

      // Find the Name text field
      final nameField = find.widgetWithText(TextField, 'Name *');
      expect(nameField, findsOneWidget);

      // Type duplicate name "coca cola" (lowercase)
      await tester.enterText(nameField, 'coca cola');
      await tester.pumpAndSettle();

      // Inline duplicate warning must appear
      expect(find.textContaining('already exists! This name is duplicated'), findsOneWidget);

      // Enter selling price
      final sellPriceField = find.widgetWithText(TextField, 'Sell Price ETB *');
      await tester.enterText(sellPriceField, '100');
      await tester.pumpAndSettle();

      // Tap Save button
      final saveBtn = find.widgetWithText(FilledButton, 'Save');
      await tester.tap(saveBtn);
      await tester.pumpAndSettle();

      // Duplicate Name Warning dialog should appear
      expect(find.text('Duplicate Name Warning'), findsOneWidget);
      expect(find.textContaining('A product named "Coca Cola" already exists.'), findsOneWidget);

      // Tap "Go Back"
      await tester.tap(find.text('Go Back'));
      await tester.pumpAndSettle();

      // Dialog is still open
      expect(find.text('Add Item'), findsOneWidget);

      // Change name to unique name "Fanta"
      await tester.enterText(nameField, 'Fanta');
      await tester.pumpAndSettle();

      // Duplicate warning should disappear
      expect(find.textContaining('This name is duplicated'), findsNothing);

      // Tap Save
      await tester.tap(find.widgetWithText(FilledButton, 'Save'));
      await tester.pumpAndSettle();

      // Dialog closed and new product listed
      expect(find.text('Add Item'), findsNothing);
      expect(find.text('Fanta'), findsOneWidget);

      await db.close();
    });
  });
}
