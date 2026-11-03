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

  Widget buildTestWidget({VoidCallback? onMutation}) {
    return MaterialApp(
      home: SaleScreen(
        repository: repo,
        onMutation: onMutation,
      ),
    );
  }

  testWidgets('Sale flow: select product, add to cart, and finish sale', (WidgetTester tester) async {
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

    // Verify initial empty state
    expect(find.text('Cart is empty / ቅርጫቱ ባዶ ነው'), findsOneWidget);
    expect(find.text('Finish Sale / ሽጥ'), findsOneWidget);

    // 3. Open product picker
    await tester.tap(find.text('Search & Select Product / ዕቃ ምረጥ'));
    await tester.pumpAndSettle();

    // Tap the product in the picker sheet
    expect(find.widgetWithText(ListTile, 'Berbere (በርበሬ)'), findsOneWidget);
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

    // 5. Verify item appears in the cart
    expect(find.text('Cart is empty / ቅርጫቱ ባዶ ነው'), findsNothing);
    expect(find.widgetWithText(ListTile, 'Berbere (በርበሬ)'), findsOneWidget);
    expect(find.textContaining('2 kg × 250.00 ETB'), findsOneWidget);
    expect(find.text('500.00 ETB'), findsWidgets); // line item total & grand total

    // 6. Finish Sale
    await tester.tap(find.text('Finish Sale / ሽጥ'));
    await tester.pumpAndSettle();

    // Verify success SnackBar
    expect(find.textContaining('Sale completed! Total: 500.00 ETB'), findsOneWidget);

    // Verify cart is cleared
    expect(find.text('Cart is empty / ቅርጫቱ ባዶ ነው'), findsOneWidget);

    // 7. Verify ledger & stock in the database
    final remainingStock = await repo.stockOf(product.id);
    expect(remainingStock, 8000); // 10000 - 2000 = 8000 (8.0 kg)
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

    // Select via 1-tap Recent items chip
    expect(find.widgetWithText(ActionChip, 'Cooking Oil (ዘይት)'), findsOneWidget);
    await tester.tap(find.widgetWithText(ActionChip, 'Cooking Oil (ዘይት)'));
    await tester.pumpAndSettle();

    // Select +5 units
    await tester.tap(find.text('+5'));
    await tester.pumpAndSettle();

    // Add to cart
    await tester.tap(find.textContaining('Add to Cart'));
    await tester.pumpAndSettle();

    // Try to finish sale
    await tester.tap(find.text('Finish Sale / ሽጥ'));
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

    // Select via 1-tap Recent items chip
    expect(find.widgetWithText(ActionChip, 'Salt (ጨው)'), findsOneWidget);
    await tester.tap(find.widgetWithText(ActionChip, 'Salt (ጨው)'));
    await tester.pumpAndSettle();

    await tester.tap(find.textContaining('Add to Cart'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(ListTile, 'Salt (ጨው)'), findsOneWidget);

    // Tap Clear in AppBar
    expect(find.text('Clear'), findsOneWidget);
    await tester.tap(find.text('Clear'));
    await tester.pumpAndSettle();

    // Cart is empty again
    expect(find.text('Cart is empty / ቅርጫቱ ባዶ ነው'), findsOneWidget);
  });
}
