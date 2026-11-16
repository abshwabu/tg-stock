<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'console:backup-db {--output= : Custom destination path}';
    protected $description = 'Create an encrypted backup of the console SQLite database';

    public function handle(): int
    {
        $dbPath = database_path('database.sqlite');
        if (!file_exists($dbPath)) {
            $this->error("Database file {$dbPath} does not exist.");
            return 1;
        }

        $rawDb = file_get_contents($dbPath);
        $key = hash('sha256', config('license.backup_encryption_key', 'tgstock-offline-backup-aes-key-v1-2026'), true);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = sodium_crypto_secretbox($rawDb, $nonce, $key);

        $payload = json_encode([
            'backup_version' => 1,
            'created_at' => now()->toIso8601String(),
            'nonce_hex' => bin2hex($nonce),
            'ciphertext_hex' => bin2hex($encrypted),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $targetDir = storage_path('app/backups');
        if (!is_dir($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        $fileName = 'console-db-' . date('Ymd-His') . '.enc.json';
        $outPath = $this->option('output') ?: $targetDir . '/' . $fileName;

        file_put_contents($outPath, $payload);

        AuditLog::log('cli_database_backup_created', 'System', $fileName, [
            'path' => $outPath,
            'size_bytes' => strlen($payload),
        ]);

        $this->info("Encrypted database backup successfully created: {$outPath}");
        return 0;
    }
}
