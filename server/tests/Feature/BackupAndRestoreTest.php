<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\BackupDatabaseCommand;
use App\Models\Shop;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupAndRestoreTest extends TestCase
{
    protected string $testDbPath;
    protected string $backupsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupsDir = storage_path('app/backups');
        if (!File::exists($this->backupsDir)) {
            File::makeDirectory($this->backupsDir, 0750, true);
        }

        // Clean out existing backups for clean test state
        $existingBackups = glob("{$this->backupsDir}/*");
        foreach ($existingBackups as $file) {
            if (is_file($file)) {
                File::delete($file);
            }
        }

        // Setup a dedicated SQLite test file
        $testDir = storage_path('framework/testing');
        if (!File::exists($testDir)) {
            File::makeDirectory($testDir, 0750, true);
        }
        $this->testDbPath = "{$testDir}/test_backup_" . uniqid() . ".sqlite";
        touch($this->testDbPath);

        Config::set('database.connections.sqlite.database', $this->testDbPath);
        Config::set('services.backup.encryption_key', 'test-secret-key-32-chars-long!');

        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        if (File::exists($this->testDbPath)) {
            File::delete($this->testDbPath);
        }
        if (File::exists("{$this->testDbPath}.pre-restore")) {
            File::delete("{$this->testDbPath}.pre-restore");
        }

        $testBackups = glob("{$this->backupsDir}/*");
        foreach ($testBackups as $file) {
            if (is_file($file)) {
                File::delete($file);
            }
        }

        parent::tearDown();
    }

    public function test_backup_creates_encrypted_file_with_magic_header(): void
    {
        // Insert sample shop into the test database
        Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Backup Test Shop',
            'currency' => 'ETB',
            'language' => 'en',
        ]);

        $this->artisan('backup:run', ['--no-telegram' => true])
            ->expectsOutputToContain('Backup created successfully')
            ->assertSuccessful();

        $backupFiles = glob("{$this->backupsDir}/backup_*.sqlite.gz.enc");
        $this->assertNotEmpty($backupFiles, 'Expected backup file was not created');

        $backupPath = $backupFiles[0];
        $content = file_get_contents($backupPath);

        // Header check
        $this->assertSame(BackupDatabaseCommand::MAGIC_HEADER, substr($content, 0, 6));
        $this->assertGreaterThan(34, strlen($content)); // Header + IV + Tag + Ciphertext
    }

    public function test_backup_uploads_to_telegram_when_configured(): void
    {
        Http::fake([
            'https://api.telegram.org*' => Http::response(['ok' => true], 200),
        ]);

        Config::set('services.telegram.bot_token', 'test_token');
        Config::set('services.telegram.admin_chat_id', '987654321');

        $this->artisan('backup:run')
            ->expectsOutputToContain('Uploaded backup to Telegram successfully.')
            ->assertSuccessful();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendDocument') &&
                   $request->isMultipart();
        });
    }

    public function test_backup_and_restore_round_trip(): void
    {
        $shopId = (string) Str::uuid7();
        Shop::create([
            'id' => $shopId,
            'name' => 'Original Shop Before Backup',
            'currency' => 'ETB',
            'language' => 'en',
        ]);

        // 1. Run backup
        $this->artisan('backup:run', ['--no-telegram' => true])->assertSuccessful();

        // 2. Modify database (delete original shop, insert another shop)
        Shop::query()->delete();
        $this->assertDatabaseMissing('shops', ['id' => $shopId]);

        Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Unwanted Altered Shop',
            'currency' => 'ETB',
            'language' => 'en',
        ]);

        // 3. Restore latest backup
        $this->artisan('backup:restore', ['--latest' => true, '--force' => true])
            ->expectsOutputToContain('Database restored successfully! Integrity verified.')
            ->assertSuccessful();

        // Reconnect to restored database
        DB::purge('sqlite');

        // 4. Verify original state restored
        $this->assertDatabaseHas('shops', ['id' => $shopId, 'name' => 'Original Shop Before Backup']);
        $this->assertDatabaseMissing('shops', ['name' => 'Unwanted Altered Shop']);

        // 5. Verify rollback copy was created
        $this->assertFileExists("{$this->testDbPath}.pre-restore");
    }

    public function test_restore_fails_with_tampered_or_invalid_file(): void
    {
        $fakeBackup = "{$this->backupsDir}/backup_tampered.sqlite.gz.enc";
        file_put_contents($fakeBackup, BackupDatabaseCommand::MAGIC_HEADER . str_repeat('X', 100));

        $this->artisan('backup:restore', ['file' => $fakeBackup, '--force' => true])
            ->expectsOutputToContain('Decryption failed! Authentication tag mismatch or incorrect key.')
            ->assertFailed();
    }
}
