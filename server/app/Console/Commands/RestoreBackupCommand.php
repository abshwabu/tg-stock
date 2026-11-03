<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

class RestoreBackupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:restore
                            {file? : Path to specific backup file}
                            {--latest : Automatically pick the newest backup file}
                            {--force : Force restore without interactive confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Decrypt, decompress, validate, and restore an encrypted SQLite database backup';

    public function handle(): int
    {
        $backupPath = $this->resolveBackupFile();
        if ($backupPath === null) {
            return Command::FAILURE;
        }

        $this->warn("Selected backup file: {$backupPath}");

        if (!$this->option('force') && !$this->confirm('WARNING: Restoring will overwrite the current active database. Continue?')) {
            $this->info('Restoration aborted.');
            return Command::SUCCESS;
        }

        $this->line('Reading and decrypting backup...');
        $content = file_get_contents($backupPath);
        if ($content === false || strlen($content) < 34) {
            $this->error('Invalid or empty backup file.');
            return Command::FAILURE;
        }

        // Verify Magic Header
        $magic = substr($content, 0, 6);
        if ($magic !== BackupDatabaseCommand::MAGIC_HEADER) {
            $this->error('File does not match the Inventory backup format.');
            return Command::FAILURE;
        }

        $iv = substr($content, 6, 12);
        $tag = substr($content, 18, 16);
        $ciphertext = substr($content, 34);

        $rawKey = config('services.backup.encryption_key') ?? config('app.key');
        if (empty($rawKey)) {
            $this->error('Missing decryption key. Check BACKUP_ENCRYPTION_KEY or APP_KEY.');
            return Command::FAILURE;
        }

        if (str_starts_with($rawKey, 'base64:')) {
            $rawKey = base64_decode(substr($rawKey, 7));
        }

        $derivedKey = hash('sha256', $rawKey, true);

        $gzipped = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $derivedKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($gzipped === false) {
            $this->error('Decryption failed! Authentication tag mismatch or incorrect key.');
            return Command::FAILURE;
        }

        $this->line('Decompressing SQLite snapshot...');
        $rawSqlite = gzdecode($gzipped);
        if ($rawSqlite === false) {
            $this->error('Gzip decompression failed. Data may be corrupted.');
            return Command::FAILURE;
        }

        // Write to temporary candidate file
        $tempRestorePath = storage_path('app/backups/temp_restore_verify.sqlite');
        file_put_contents($tempRestorePath, $rawSqlite);

        // Validate integrity of candidate file before touching active database
        $this->line('Validating candidate database integrity and schema...');
        if (!$this->validateSqliteFile($tempRestorePath)) {
            File::delete($tempRestorePath);
            $this->error('Candidate database failed integrity or schema check. Restoration aborted.');
            return Command::FAILURE;
        }

        // Replace active database
        $activeDbPath = config('database.connections.sqlite.database');
        $this->line("Purging active database connection and restoring to: {$activeDbPath}");

        try {
            DB::purge('sqlite');

            // Save pre-restore rollback copy
            if (File::exists($activeDbPath)) {
                File::copy($activeDbPath, "{$activeDbPath}.pre-restore");
            }

            // Copy candidate over active DB
            File::copy($tempRestorePath, $activeDbPath);
            File::delete($tempRestorePath);

            // Clean up any stale journal / WAL files
            if (File::exists("{$activeDbPath}-wal")) {
                File::delete("{$activeDbPath}-wal");
            }
            if (File::exists("{$activeDbPath}-shm")) {
                File::delete("{$activeDbPath}-shm");
            }

            $this->info('Database restored successfully! Integrity verified.');
            Log::info("Database successfully restored from: {$backupPath}");
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Restoration failed: ' . $e->getMessage());
            Log::error('Database restore error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Resolves the backup file path from arguments or auto-picks latest.
     */
    protected function resolveBackupFile(): ?string
    {
        $filePath = $this->argument('file');

        if (!empty($filePath)) {
            if (!File::exists($filePath)) {
                $this->error("Specified file not found: {$filePath}");
                return null;
            }
            return $filePath;
        }

        $backupsDir = storage_path('app/backups');
        $files = glob("{$backupsDir}/backup_*.sqlite.gz.enc");

        if (empty($files)) {
            $this->error("No backups found in: {$backupsDir}");
            return null;
        }

        // Sort descending by modified timestamp
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    /**
     * Validates SQLite integrity check and ensures essential tables exist.
     */
    protected function validateSqliteFile(string $path): bool
    {
        try {
            $pdo = new PDO("sqlite:{$path}");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 1. PRAGMA integrity_check
            $stmt = $pdo->query('PRAGMA integrity_check;');
            $result = $stmt->fetchColumn();
            if ($result !== 'ok') {
                $this->error("PRAGMA integrity_check returned: {$result}");
                return false;
            }

            // 2. Required tables check
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('shops', 'products', 'movements');");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!in_array('shops', $tables, true) || !in_array('products', $tables, true) || !in_array('movements', $tables, true)) {
                $this->error('Missing required tables (shops, products, movements) in candidate database.');
                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->error('Validation exception: ' . $e->getMessage());
            return false;
        }
    }
}
