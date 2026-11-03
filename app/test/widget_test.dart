import 'package:flutter_test/flutter_test.dart';

import 'package:app/database/app_database.dart';
import 'package:app/main.dart';
import 'package:app/repositories/ledger_repository.dart';
import 'package:app/services/api_client.dart';
import 'package:app/services/auth_storage.dart';
import 'package:app/services/sync_engine.dart';

void main() {
  testWidgets('InventoryApp smoke test', (WidgetTester tester) async {
    final db = AppDatabase.inMemory();
    final repository = LedgerRepository(db);
    final authStorage = InMemoryAuthStorage();
    final apiClient = ApiClient();
    final syncEngine = SyncEngine(
      db: db,
      api: apiClient,
      storage: authStorage,
    );

    await tester.pumpWidget(InventoryApp(
      db: db,
      repository: repository,
      apiClient: apiClient,
      authStorage: authStorage,
      syncEngine: syncEngine,
    ));

    await tester.pumpAndSettle();

    // Verify first run setup is presented
    expect(find.textContaining('Welcome to Inventory'), findsOneWidget);

    await db.close();
  });
}
