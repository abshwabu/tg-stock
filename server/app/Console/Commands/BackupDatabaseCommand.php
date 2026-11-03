<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackupDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:run
                            {--no-telegram : Skip uploading encrypted backup to admin Telegram chat}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform zero-lock VACUUM snapshot, gzip compress, AES-256-GCM encrypt, and upload to Telegram';

    /**
     * Magic prefix to identify valid Inventory backups.
     */
    public const MAGIC_HEADER = 'INVBK1';

    public function handle(TelegramClient $telegram): int
    {
        $this->info('Starting database backup routine...');

        $backupsDir = storage_path('app/backups');
        if (!File::exists($backupsDir)) {
            File::makeDirectory($backupsDir, 0750, true);
        }

        // 1. Zero-lock SQLite atomic snapshot via VACUUM INTO
        $timestamp = date('Y-m-d_His');
        $tempSnapshotPath = $backupsDir . "/temp_snapshot_{$timestamp}.sqlite";

        if (File::exists($tempSnapshotPath)) {
            File::delete($tempSnapshotPath);
        }

        try {
            $this->line('Creating SQLite snapshot via VACUUM INTO...');
            DB::connection('sqlite')->statement("VACUUM INTO '{$tempSnapshotPath}'");
        } catch (Throwable $e) {
            $this->error('Failed to execute VACUUM INTO: ' . $e->getMessage());
            Log::error('Backup failed during VACUUM INTO: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // 2. Gzip compression (level 9 for highest ratio)
        $this->line('Compressing snapshot with gzip...');
        $rawSqlite = file_get_contents($tempSnapshotPath);
        File::delete($tempSnapshotPath);

        if ($rawSqlite === false) {
            $this->error('Unable to read temporary snapshot file.');
            return Command::FAILURE;
        }

        $gzipped = gzencode($rawSqlite, 9);
        if ($gzipped === false) {
            $this->error('Gzip compression failed.');
            return Command::FAILURE;
        }

        // 3. Encrypt with AES-256-GCM via OpenSSL
        $this->line('Encrypting backup with AES-256-GCM...');
        $rawKey = config('services.backup.encryption_key') ?? config('app.key');
        if (empty($rawKey)) {
            $this->error('Missing encryption key. Configure BACKUP_ENCRYPTION_KEY or APP_KEY.');
            return Command::FAILURE;
        }

        if (str_starts_with($rawKey, 'base64:')) {
            $rawKey = base64_decode(substr($rawKey, 7));
        }

        $derivedKey = hash('sha256', $rawKey, true); // 32 bytes for AES-256
        $iv = openssl_random_pseudo_bytes(12); // 96-bit IV
        $tag = '';

        $ciphertext = openssl_encrypt(
            $gzipped,
            'aes-256-gcm',
            $derivedKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            $this->error('OpenSSL encryption failed: ' . openssl_error_string());
            return Command::FAILURE;
        }

        // Binary packet: MAGIC(6B) + IV(12B) + TAG(16B) + CIPHERTEXT
        $encryptedPayload = self::MAGIC_HEADER . $iv . $tag . $ciphertext;
        $backupFilename = "backup_{$timestamp}.sqlite.gz.enc";
        $finalBackupPath = "{$backupsDir}/{$backupFilename}";

        file_put_contents($finalBackupPath, $encryptedPayload);
        $fileSizeKb = round(filesize($finalBackupPath) / 1024, 2);
        $this->info("Backup created successfully: {$backupFilename} ({$fileSizeKb} KB)");
        Log::info("Backup created: {$backupFilename} ({$fileSizeKb} KB)");

        // 4. Prune local backups older than 14 days
        $this->pruneOldBackups($backupsDir);

        // 5. Off-server copy to private Telegram admin chat
        if (!$this->option('no-telegram')) {
            $this->uploadToTelegram($telegram, $finalBackupPath, $backupFilename);
        }

        return Command::SUCCESS;
    }

    /**
     * Delete local backups older than 14 days.
     */
    protected function pruneOldBackups(string $backupsDir): void
    {
        $files = glob("{$backupsDir}/backup_*.sqlite.gz.enc");
        $fourteenDaysAgo = now()->subDays(14)->timestamp;
        $prunedCount = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $fourteenDaysAgo) {
                File::delete($file);
                $prunedCount++;
            }
        }

        if ($prunedCount > 0) {
            $this->line("Pruned {$prunedCount} backup(s) older than 14 days.");
        }
    }

    /**
     * Upload backup to Telegram admin chat, chunking if > 45MB.
     */
    protected function uploadToTelegram(TelegramClient $telegram, string $filePath, string $filename): void
    {
        $adminChatId = config('services.telegram.admin_chat_id');
        if (empty($adminChatId)) {
            $this->line('No TELEGRAM_ADMIN_CHAT_ID configured. Skipping off-server Telegram copy.');
            return;
        }

        $this->line("Dispatching encrypted off-server backup to Telegram chat {$adminChatId}...");
        $fileSize = filesize($filePath);
        $maxChunkSize = 45 * 1024 * 1024; // 45 MB (under 50 MB limit)

        try {
            if ($fileSize <= $maxChunkSize) {
                $contents = file_get_contents($filePath);
                $caption = "📦 *Off-Server SQLite Backup*\nFile: `{$filename}`\nDate: " . now()->toIso8601String() . "\nEncryption: AES-256-GCM";
                $telegram->sendDocument($adminChatId, $contents, $filename, $caption, ['parse_mode' => 'Markdown']);
                $this->info('Uploaded backup to Telegram successfully.');
            } else {
                $this->uploadInChunks($telegram, (int) $adminChatId, $filePath, $filename, $maxChunkSize);
            }
        } catch (Throwable $e) {
            $this->warn('Failed to upload backup to Telegram: ' . $e->getMessage());
            Log::warning('Telegram backup upload failed: ' . $e->getMessage());
        }
    }

    /**
     * Split and stream large backup file in chunks.
     */
    protected function uploadInChunks(TelegramClient $telegram, int $chatId, string $filePath, string $filename, int $chunkSize): void
    {
        $handle = fopen($filePath, 'rb');
        $part = 1;
        $totalParts = (int) ceil(filesize($filePath) / $chunkSize);

        while (!feof($handle)) {
            $chunkData = fread($handle, $chunkSize);
            if ($chunkData === false || strlen($chunkData) === 0) {
                break;
            }

            $chunkFilename = "{$filename}.part{$part}";
            $caption = "📦 *Backup Part {$part}/{$totalParts}*\nFile: `{$chunkFilename}`";
            $telegram->sendDocument($chatId, $chunkData, $chunkFilename, $caption, ['parse_mode' => 'Markdown']);
            $part++;
        }

        fclose($handle);
        $this->info("Uploaded {$totalParts} split chunks to Telegram.");
    }
}
