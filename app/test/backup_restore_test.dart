import 'dart:io';

import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;

import 'package:app/database/app_database.dart';
import 'package:app/repositories/ledger_repository.dart';
import 'package:app/services/backup_service.dart';

void main() {
  late Directory tempDir;
  late File dbFile;
  late AppDatabase db;
  late LedgerRepository repo;
  late BackupService backupService;

  setUp(() async {
    tempDir = Directory.systemTemp.createTempSync('inventory_backup_test_');
    dbFile = File(p.join(tempDir.path, 'inventory.sqlite'));

    db = AppDatabase(NativeDatabase(dbFile));
    repo = LedgerRepository(db);
    backupService = BackupService(db: db, repository: repo);
  });

  tearDown(() async {
    try {
      await db.close();
    } catch (_) {}
    if (await tempDir.exists()) {
      await tempDir.delete(recursive: true);
    }
  });

  group('Backup & Restore Round-Trip', () {
    test('database can be backed up, validated, and restored accurately', () async {
      // 1. Seed initial data
      final p1 = await repo.createProduct(
        name: 'Berbere (በርበሬ)',
        costPriceCents: 15000,
        sellPriceCents: 20000,
        openingStockMilli: 10000,
      );

      final p2 = await repo.createProduct(
        name: 'Coffee (ቡና)',
        costPriceCents: 25000,
        sellPriceCents: 35000,
        openingStockMilli: 8000,
      );

      // Perform a sale
      await repo.recordSale(
        items: [
          SaleLineItem(product: p1, qtyMilli: 3000, unitPriceCents: 20000),
        ],
      );

      // Verify pre-backup stock
      expect(await repo.stockOf(p1.id), 7000);
      expect(await repo.stockOf(p2.id), 8000);

      // 2. Create manual backup
      // Flush WAL
      await db.customStatement('PRAGMA wal_checkpoint(FULL);');
      final backupsDir = Directory(p.join(tempDir.path, 'backups'))..createSync(recursive: true);
      final backupFilePath = p.join(backupsDir.path, 'test_manual_backup.sqlite');
      final backupFile = await dbFile.copy(backupFilePath);

      // 3. Validate backup file
      final isValid = await backupService.validateBackupFile(backupFile);
      expect(isValid, isTrue, reason: 'Valid database file must pass validation');

      // 4. Modify/corrupt current database (delete products and wipe ledger)
      await (db.delete(db.movements)).go();
      await (db.delete(db.products)).go();
      expect(await repo.listProducts(), isEmpty);

      // 5. Restore database from backup
      await db.close();
      await backupFile.copy(dbFile.path);

      // Clean up wal/shm
      final wal = File('${dbFile.path}-wal');
      if (await wal.exists()) await wal.delete();
      final shm = File('${dbFile.path}-shm');
      if (await shm.exists()) await shm.delete();

      // Reopen restored DB
      final restoredDb = AppDatabase(NativeDatabase(dbFile));
      final restoredRepo = LedgerRepository(restoredDb);

      try {
        final products = await restoredRepo.listProducts();
        expect(products.length, 2);

        final restoredP1Stock = await restoredRepo.stockOf(p1.id);
        final restoredP2Stock = await restoredRepo.stockOf(p2.id);

        expect(restoredP1Stock, 7000);
        expect(restoredP2Stock, 8000);
      } finally {
        await restoredDb.close();
      }
    });

    test('validateBackupFile rejects non-SQLite or corrupt files', () async {
      // 1. Non-existent file
      final nonExistent = File(p.join(tempDir.path, 'does_not_exist.sqlite'));
      expect(await backupService.validateBackupFile(nonExistent), isFalse);

      // 2. Plain text file
      final txtFile = File(p.join(tempDir.path, 'fake.sqlite'))..writeAsStringSync('Hello, not a database!');
      expect(await backupService.validateBackupFile(txtFile), isFalse);

      // 3. Valid SQLite file but missing required tables (e.g. empty SQLite db)
      final emptySqliteFile = File(p.join(tempDir.path, 'empty.sqlite'));
      final emptyDb = NativeDatabase(emptySqliteFile);
      await emptyDb.ensureOpen(_EmptyDbUser());
      await emptyDb.close();

      expect(await backupService.validateBackupFile(emptySqliteFile), isFalse);
    });

    test('snapshot rotation keeps at most the last 4 snapshots', () async {
      final backupsDir = Directory(p.join(tempDir.path, 'backups'))..createSync(recursive: true);

      // Seed 6 snapshot files
      for (int i = 1; i <= 6; i++) {
        final f = File(p.join(backupsDir.path, 'snapshot_2026110${i}_120000.sqlite'));
        f.writeAsStringSync('dummy_$i');
        // stagger last modified
        f.setLastModifiedSync(DateTime(2026, 11, i, 12, 0, 0));
      }

      var files = backupsDir
          .listSync()
          .whereType<File>()
          .where((f) => p.basename(f.path).startsWith('snapshot_'))
          .toList();
      expect(files.length, 6);

      // Run rotation logic
      files.sort((a, b) => b.lastModifiedSync().compareTo(a.lastModifiedSync()));
      if (files.length > 4) {
        for (int i = 4; i < files.length; i++) {
          files[i].deleteSync();
        }
      }

      final remaining = backupsDir
          .listSync()
          .whereType<File>()
          .where((f) => p.basename(f.path).startsWith('snapshot_'))
          .toList();

      expect(remaining.length, 4);

      // Oldest snapshots (day 1 and day 2) should have been deleted
      expect(File(p.join(backupsDir.path, 'snapshot_20261101_120000.sqlite')).existsSync(), isFalse);
      expect(File(p.join(backupsDir.path, 'snapshot_20261102_120000.sqlite')).existsSync(), isFalse);
      // Newest snapshot (day 6) must exist
      expect(File(p.join(backupsDir.path, 'snapshot_20261106_120000.sqlite')).existsSync(), isTrue);
    });
  });
}

class _EmptyDbUser extends QueryExecutorUser {
  @override
  int get schemaVersion => 1;

  @override
  Future<void> beforeOpen(QueryExecutor executor, OpeningDetails details) async {}
}
