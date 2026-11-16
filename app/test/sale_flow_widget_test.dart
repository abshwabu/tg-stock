import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:app/database/app_database.dart';
import 'package:app/repositories/ledger_repository.dart';
import 'package:app/ui/screens/sale_screen.dart';

void main() {
  late AppDatabase db;
  late LedgerRepository repo;

  setUp(() {
    db = AppDatabase.inMemory();
    repo = LedgerRepository(db);
  });

  tearDown(() async {
    await db.close();
  });

  Widget buildTestWidget({VoidCallback? onMutation, Locale locale = const Locale('en')}) {
    return MaterialApp(
      locale: locale,
      home: SaleScreen(
        repository: repo,
        onMutation: onMutation,
      ),
    );
  }

  testWidgets('Sale flow: select product from on-screen product list, add to cart, and finish sale', (WidgetTester tester) async {
    // 1. Setup product with initial stock
    final product = await repo.createProduct(
      name: 'Berbere (በርበሬ)',
      sellPriceCents: 25000, // 250.00 ETB
      openingStockMilli: 10000, // 10.0 kg
      unit: 'kg',
    );

    // 2. Pump widget
    await tester.pumpWidget(buildTestWidget());
    await tester.pumpAndSettle();

    // Verify products are listed on screen and initial total is zero
    expect(find.widgetWithText(ListTile, 'Berbere (በርበሬ)'), findsOneWidget);
    expect(find.text('Finish Sale'), findsOneWidget);
    expect(find.text('0.00 ETB'), findsOneWidget);

    // 3. Tap the product in the on-screen list
    await tester.tap(find.widgetWithText(ListTile, 'Berbere (በርበሬ)'));
    await tester.pumpAndSettle();

    // 4. In the quantity sheet, choose quantity +2
    expect(find.text('+2'), findsOneWidget);
    await tester.tap(find.text('+2'));
    await tester.pumpAndSettle();

    // Tap Add to Cart
    expect(find.textContaining('Add to Cart'), findsOneWidget);
    await tester.tap(find.textContaining('Add to Cart'));
    await tester.pumpAndSettle();

    // 5. Verify item appears in the cart indicator & bottom bar total
    expect(find.textContaining('🛒 2 kg'), findsOneWidget);
    expect(find.text('500.00 ETB'), findsOneWidget); // grand total

    // Open Cart sheet via View Cart
    expect(find.textContaining('View Cart'), findsOneWidget);
    await tester.tap(find.textContaining('View Cart'));
    await tester.pumpAndSettle();

    // Inside Cart sheet: verify line items and totals
    expect(find.textContaining('2 kg × 250.00 ETB'), findsOneWidget);
    expect(find.text('500.00 ETB'), findsWidgets);

    // Close Cart sheet by tapping Close Cart icon
    expect(find.byTooltip('Close Cart'), findsOneWidget);
    await tester.tap(find.byTooltip('Close Cart'));
    await tester.pumpAndSettle();

    // 6. Finish Sale opens Payment step (Cash preselected, 1-tap finish)
    await tester.tap(find.text('Finish Sale'));
    await tester.pumpAndSettle();

    await tester.tap(find.textContaining('Finish Sale (500.00 ETB)'));
    await tester.pumpAndSettle();

    // Verify success SnackBar
    expect(find.textContaining('Sale completed! Total: 500.00 ETB'), findsOneWidget);

    // Verify cart total is reset to 0
    expect(find.text('0.00 ETB'), findsOneWidget);

    // 7. Verify ledger & stock in the database
    final remainingStock = await repo.stockOf(product.id);
    expect(remainingStock, 8000); // 10000 - 2000 = 8000 (8.0 kg)
  });

  testWidgets('Sale screen: products listed by alphabet first, then by popularity via dropdown', (WidgetTester tester) async {
    // 1. Create 3 products: Banana, Apple, Carrot with initial stock
    final pBanana = await repo.createProduct(name: 'Banana (ሙዝ)', sellPriceCents: 5000, openingStockMilli: 100000);
    await repo.createProduct(name: 'Apple (አፕል)', sellPriceCents: 10000, openingStockMilli: 100000);
    final pCarrot = await repo.createProduct(name: 'Carrot (ካሮት)', sellPriceCents: 3000, openingStockMilli: 100000);

    // Record sales: Banana 5 sales, Carrot 1 sale, Apple 0 sales
    for (int i = 0; i < 5; i++) {
      await repo.recordSale(items: [SaleLineItem(product: pBanana, qtyMilli: 1000, unitPriceCents: 5000)]);
    }
    await repo.recordSale(items: [SaleLineItem(product: pCarrot, qtyMilli: 1000, unitPriceCents: 3000)]);

    // 2. Open SaleScreen
    await tester.pumpWidget(buildTestWidget());
    await tester.pumpAndSettle();

    // 3. By default (Alphabet sort): Apple -> Banana -> Carrot
    final tilesAlphabet = tester.widgetList<ListTile>(find.byType(ListTile)).toList();
    final namesAlphabet = tilesAlphabet
        .map((t) => ((t.title as Row).children.whereType<Expanded>().first.child as Text).data!)
        .toList();
    expect(namesAlphabet, ['Apple (አፕል)', 'Banana (ሙዝ)', 'Carrot (ካሮት)']);

    // 4. Switch to Popularity sort via Dropdown
    expect(find.byType(DropdownButton<ProductSortMode>), findsOneWidget);
    await tester.tap(find.byType(DropdownButton<ProductSortMode>));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Popularity').last);
    await tester.pumpAndSettle();

    // 5. In Popularity sort: Banana (5 sold) -> Carrot (1 sold) -> Apple (0 sold)
    final tilesPop = tester.widgetList<ListTile>(find.byType(ListTile)).toList();
    final namesPop = tilesPop
        .map((t) => ((t.title as Row).children.whereType<Expanded>().first.child as Text).data!)
        .toList();
    expect(namesPop, ['Banana (ሙዝ)', 'Carrot (ካሮት)', 'Apple (አፕል)']);

    // Verify popularity badges show sales count
    expect(find.text('5 sold'), findsOneWidget);
    expect(find.text('1 sold'), findsOneWidget);
  });

  testWidgets('Sale flow: blocks sale when stock is insufficient and negative stock disabled', (WidgetTester tester) async {
    await repo.setAllowNegativeStock(false);

    // Product with only 1.0 unit available
    final product = await repo.createProduct(
      name: 'Cooking Oil (ዘይት)',
      sellPriceCents: 90000,
      openingStockMilli: 1000, // 1.0 L
      unit: 'L',
    );

    await tester.pumpWidget(buildTestWidget());
    await tester.pumpAndSettle();

    // Select product from on-screen list
    expect(find.widgetWithText(ListTile, 'Cooking Oil (ዘይት)'), findsOneWidget);
    await tester.tap(find.widgetWithText(ListTile, 'Cooking Oil (ዘይት)'));
    await tester.pumpAndSettle();

    // Select +5 units
    await tester.tap(find.text('+5'));
    await tester.pumpAndSettle();

    // Add to cart
    await tester.tap(find.textContaining('Add to Cart'));
    await tester.pumpAndSettle();

    // Try to finish sale
    await tester.tap(find.text('Finish Sale'));
    await tester.pumpAndSettle();

    // Dialog must appear alerting insufficient stock
    expect(find.text('⚠️ Insufficient Stock'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(AlertDialog),
        matching: find.textContaining('Cooking Oil (ዘይት)'),
      ),
      findsOneWidget,
    );

    // Dismiss dialog
    await tester.tap(find.text('OK'));
    await tester.pumpAndSettle();

    // Stock must remain intact
    final stock = await repo.stockOf(product.id);
    expect(stock, 1000);
  });

  testWidgets('Sale flow: Clear button empties cart', (WidgetTester tester) async {
    await repo.createProduct(
      name: 'Salt (ጨው)',
      sellPriceCents: 2000,
      openingStockMilli: 10000,
    );

    await tester.pumpWidget(buildTestWidget());
    await tester.pumpAndSettle();

    // Select product from on-screen list
    expect(find.widgetWithText(ListTile, 'Salt (ጨው)'), findsOneWidget);
    await tester.tap(find.widgetWithText(ListTile, 'Salt (ጨው)'));
    await tester.pumpAndSettle();

    await tester.tap(find.textContaining('Add to Cart'));
    await tester.pumpAndSettle();

    expect(find.text('20.00 ETB'), findsOneWidget);

    // Tap Clear in AppBar
    expect(find.text('Clear'), findsOneWidget);
    await tester.tap(find.text('Clear'));
    await tester.pumpAndSettle();

    // Cart is empty again
    expect(find.text('0.00 ETB'), findsOneWidget);
    expect(find.text('Clear'), findsNothing);
  });
}
