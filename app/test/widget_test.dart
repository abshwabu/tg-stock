import 'package:flutter_test/flutter_test.dart';

import 'package:app/database/app_database.dart';
import 'package:app/main.dart';
import 'package:app/services/api_client.dart';
import 'package:app/services/auth_storage.dart';
import 'package:app/services/sync_engine.dart';

void main() {
  testWidgets('InventoryApp smoke test', (WidgetTester tester) async {
    final db = AppDatabase.inMemory();
    final authStorage = InMemoryAuthStorage();
    final apiClient = ApiClient();
    final syncEngine = SyncEngine(
      db: db,
      api: apiClient,
      storage: authStorage,
    );

    await tester.pumpWidget(InventoryApp(
      db: db,
      apiClient: apiClient,
      authStorage: authStorage,
      syncEngine: syncEngine,
    ));

    await tester.pumpAndSettle();

    expect(find.text('Inventory / እቃ ዝርዝር'), findsOneWidget);
    expect(find.text('Offline / ኦፍላይን'), findsOneWidget);

    await db.close();
  });
}
