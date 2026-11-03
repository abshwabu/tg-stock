import 'package:flutter/material.dart';

import 'database/app_database.dart';
import 'services/api_client.dart';
import 'services/auth_storage.dart';
import 'services/sync_engine.dart';
import 'ui/screens/home_screen.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();

  final db = AppDatabase();
  final authStorage = SecureAuthStorage();
  final apiClient = ApiClient();
  final syncEngine = SyncEngine(
    db: db,
    api: apiClient,
    storage: authStorage,
  );

  runApp(InventoryApp(
    db: db,
    apiClient: apiClient,
    authStorage: authStorage,
    syncEngine: syncEngine,
  ));
}

class InventoryApp extends StatelessWidget {
  final AppDatabase db;
  final ApiClient apiClient;
  final AuthStorage authStorage;
  final SyncEngine syncEngine;

  const InventoryApp({
    super.key,
    required this.db,
    required this.apiClient,
    required this.authStorage,
    required this.syncEngine,
  });

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Inventory Ethiopia',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xFF0F766E), // Teal Ethiopian aesthetic
        ),
      ),
      home: HomeScreen(
        db: db,
        apiClient: apiClient,
        authStorage: authStorage,
        syncEngine: syncEngine,
      ),
    );
  }
}
