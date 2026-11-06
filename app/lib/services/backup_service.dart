import 'dart:io';

import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:intl/intl.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../database/app_database.dart';
import '../repositories/ledger_repository.dart';

class InvalidBackupException implements Exception {
  final String message;
  InvalidBackupException(this.message);

  @override
  String toString() => message;
}

class BackupService {
  final AppDatabase db;
  final LedgerRepository repository;
  final Directory? customBackupsDir;

  BackupService({
    required this.db,
    required this.repository,
    this.customBackupsDir,
  });

  /// Returns the target File of the main SQLite database.
  Future<File> getDatabaseFile() async {
    return AppDatabase.getDatabaseFile();
  }

  /// Returns the internal app directory dedicated to backups and snapshots.
  Future<Directory> getBackupsDirectory() async {
    if (customBackupsDir != null) {
      if (!await customBackupsDir!.exists()) {
        await customBackupsDir!.create(recursive: true);
      }
      return customBackupsDir!;
    }
    try {
      final docs = await getApplicationDocumentsDirectory().timeout(
        const Duration(milliseconds: 100),
      );
      final dir = Directory(p.join(docs.path, 'backups'));
      if (!await dir.exists()) {
        await dir.create(recursive: true);
      }
      return dir;
    } catch (_) {
      final sysTemp = Directory.systemTemp;
      final dir = Directory(p.join(sysTemp.path, 'inventory_backups'));
      if (!await dir.exists()) {
        await dir.create(recursive: true);
      }
      return dir;
    }
  }

  /// Creates a single-file backup or automatic weekly snapshot.
  Future<File> createBackupFile({bool isWeeklySnapshot = false}) async {
    // Checkpoint any open WAL logs before copying database file
    try {
      await db.customStatement('PRAGMA wal_checkpoint(FULL);');
    } catch (_) {}

    final dbFile = await getDatabaseFile();
    if (!await dbFile.exists()) {
      throw StateError('Database file does not exist.');
    }

    final backupsDir = await getBackupsDirectory();
    final shopName = await repository.getShopName();
    final safeShop = shopName.replaceAll(RegExp(r'[^\w\s]+'), '').trim().replaceAll(RegExp(r'\s+'), '_');
    final timestamp = DateFormat('yyyyMMdd_HHmmss').format(DateTime.now());

    final fileName = isWeeklySnapshot
        ? 'snapshot_$timestamp.sqlite'
        : 'backup_${safeShop.isEmpty ? "shop" : safeShop}_$timestamp.sqlite';

    final targetPath = p.join(backupsDir.path, fileName);
    final backupFile = await dbFile.copy(targetPath);
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;
    await repository.setLastBackupMs(nowMs);

    if (isWeeklySnapshot) {
      await _rotateSnapshots(backupsDir);
      await repository.setLastWeeklySnapshotMs(nowMs);
    }

    return backupFile;
  }

  /// Retains at most the last 4 weekly snapshots, removing older ones.
  Future<void> _rotateSnapshots(Directory backupsDir) async {
    try {
      final snapshots = backupsDir
          .listSync()
          .whereType<File>()
          .where((f) => p.basename(f.path).startsWith('snapshot_') && f.path.endsWith('.sqlite'))
          .toList();

      snapshots.sort((a, b) => b.lastModifiedSync().compareTo(a.lastModifiedSync()));

      if (snapshots.length > 4) {
        for (int i = 4; i < snapshots.length; i++) {
          try {
            await snapshots[i].delete();
          } catch (_) {}
        }
      }
    } catch (_) {}
  }

  /// Lists all stored weekly snapshot files in descending chronological order.
  Future<List<File>> listSnapshots() async {
    final backupsDir = await getBackupsDirectory();
    if (!await backupsDir.exists()) return [];

    final snapshots = backupsDir
        .listSync()
        .whereType<File>()
        .where((f) => p.basename(f.path).startsWith('snapshot_') && f.path.endsWith('.sqlite'))
        .toList();

    snapshots.sort((a, b) => b.lastModifiedSync().compareTo(a.lastModifiedSync()));
    return snapshots;
  }

  /// Lists all local backup files (manual and snapshots).
  Future<List<File>> listAllBackups() async {
    final backupsDir = await getBackupsDirectory();
    if (!await backupsDir.exists()) return [];

    final files = backupsDir
        .listSync()
        .whereType<File>()
        .where((f) => f.path.endsWith('.sqlite'))
        .toList();

    files.sort((a, b) => b.lastModifiedSync().compareTo(a.lastModifiedSync()));
    return files;
  }

  /// Validates a potential backup file before replacing active data.
  /// Checks SQLite header, schema integrity, and mandatory tables.
  Future<bool> validateBackupFile(File file) async {
    if (!await file.exists()) return false;
    final length = await file.length();
    if (length < 100) return false;

    // 1. Verify standard SQLite magic header
    final raf = await file.open(mode: FileMode.read);
    final headerBytes = await raf.read(16);
    await raf.close();

    final header = String.fromCharCodes(headerBytes);
    if (!header.startsWith('SQLite format 3')) {
      return false;
    }

    // 2. Open with Drift NativeDatabase and verify schema & data integrity
    AppDatabase? tempDb;
    try {
      driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;
      tempDb = AppDatabase(NativeDatabase(file));
      final tables = await tempDb.customSelect(
        "SELECT name FROM sqlite_master WHERE type='table' AND name IN ('products', 'movements');",
      ).get();
      final names = tables.map((r) => r.read<String>('name')).toSet();
      final hasTables = names.contains('products') && names.contains('movements');

      final integrity = await tempDb.customSelect("PRAGMA integrity_check;").get();
      final isOk = integrity.isNotEmpty && integrity.first.data.values.first == 'ok';

      await tempDb.close();
      return hasTables && isOk;
    } catch (_) {
      try {
        await tempDb?.close();
      } catch (_) {}
      return false;
    }
  }

  /// Restores a validated backup file, overwriting local database tables.
  Future<void> restoreBackup(File sourceFile) async {
    final isValid = await validateBackupFile(sourceFile);
    if (!isValid) {
      throw InvalidBackupException(
        'The selected file is not a valid or compatible Inventory SQLite database.',
      );
    }

    final dbFile = await getDatabaseFile();

    // Close the current active database connection before replacing the file
    await db.close();

    // Copy backup over current database
    await sourceFile.copy(dbFile.path);

    // Remove any stale WAL/SHM journal files
    final walFile = File('${dbFile.path}-wal');
    if (await walFile.exists()) {
      try {
        await walFile.delete();
      } catch (_) {}
    }

    final shmFile = File('${dbFile.path}-shm');
    if (await shmFile.exists()) {
      try {
        await shmFile.delete();
      } catch (_) {}
    }
  }

  /// Checks if 7 days have passed since the last snapshot and generates one if due.
  Future<bool> checkAndRunWeeklySnapshot() async {
    final lastMs = await repository.getLastWeeklySnapshotMs();
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;
    const sevenDaysMs = 7 * 24 * 60 * 60 * 1000;

    if (lastMs == null || (nowMs - lastMs) >= sevenDaysMs) {
      try {
        await createBackupFile(isWeeklySnapshot: true);
        return true;
      } catch (_) {}
    }
    return false;
  }

  /// Returns whether a backup is currently overdue according to the configured reminder days.
  Future<bool> isBackupOverdue() async {
    final reminderDays = await repository.getBackupReminderDays();
    if (reminderDays <= 0) return false;

    final lastMs = await repository.getLastBackupMs();
    if (lastMs == null) return true;

    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;
    final overdueMs = reminderDays * 24 * 60 * 60 * 1000;
    return (nowMs - lastMs) >= overdueMs;
  }

  /// Opens the system share sheet with the backup file.
  Future<void> shareBackup(File backupFile) async {
    await SharePlus.instance.share(
      ShareParams(
        files: [XFile(backupFile.path)],
        subject: 'Inventory Database Backup',
        text: 'Inventory SQLite database backup for ${p.basename(backupFile.path)}',
      ),
    );
  }
}
