<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use Throwable;

class BotCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bot:check
                            {--json : Output results as JSON}
                            {--record-heartbeat : Update the cron heartbeat timestamp}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify host environment, SQLite status, Telegram connectivity, and cron heartbeat';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('record-heartbeat')) {
            Cache::put('cron:heartbeat', now()->timestamp, now()->addHours(24));
            $this->info('Cron heartbeat timestamp updated.');
            return Command::SUCCESS;
        }

        $results = [
            'php_version' => $this->checkPhpVersion(),
            'extensions' => $this->checkRequiredExtensions(),
            'sqlite_writability' => $this->checkSqliteWritability(),
            'journal_mode' => $this->checkJournalMode(),
            'telegram_connectivity' => $this->checkTelegramConnectivity(),
            'cron_heartbeat' => $this->checkCronHeartbeat(),
        ];

        $hasFailures = false;
        foreach ($results as $check) {
            if ($check['status'] === 'fail') {
                $hasFailures = true;
                break;
            }
        }

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'success' => !$hasFailures,
                'checks' => $results,
                'timestamp' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT));

            return $hasFailures ? Command::FAILURE : Command::SUCCESS;
        }

        $this->renderTable($results);

        if ($hasFailures) {
            $this->error('Host environment check FAILED. Please resolve critical issues above.');
            return Command::FAILURE;
        }

        $this->info('All host environment checks passed successfully.');
        return Command::SUCCESS;
    }

    /**
     * Check PHP version >= 8.2.0.
     */
    protected function checkPhpVersion(): array
    {
        $minVersion = '8.2.0';
        $current = PHP_VERSION;
        $passed = version_compare($current, $minVersion, '>=');

        return [
            'name' => 'PHP Version',
            'status' => $passed ? 'pass' : 'fail',
            'message' => $passed
                ? "PHP {$current} (>= {$minVersion})"
                : "PHP {$current} is below required minimum {$minVersion}",
        ];
    }

    /**
     * Check required extensions: pdo_sqlite, mbstring, openssl, curl, json, ctype, tokenizer, xml.
     */
    protected function checkRequiredExtensions(): array
    {
        $required = [
            'pdo_sqlite',
            'mbstring',
            'openssl',
            'curl',
            'json',
            'ctype',
            'tokenizer',
            'xml',
        ];

        $missing = [];
        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        if (empty($missing)) {
            return [
                'name' => 'PHP Extensions',
                'status' => 'pass',
                'message' => 'All required extensions loaded (' . implode(', ', $required) . ')',
            ];
        }

        return [
            'name' => 'PHP Extensions',
            'status' => 'fail',
            'message' => 'Missing extensions: ' . implode(', ', $missing),
        ];
    }

    /**
     * Check SQLite storage directory and database writability.
     */
    protected function checkSqliteWritability(): array
    {
        try {
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'sqlite') {
                return [
                    'name' => 'SQLite Writability',
                    'status' => 'fail',
                    'message' => "Driver is [{$connection->getDriverName()}], expected [sqlite]",
                ];
            }

            /** @var PDO $pdo */
            $pdo = $connection->getPdo();
            $pdo->exec('CREATE TEMP TABLE _bot_check_writability (id INTEGER PRIMARY KEY, val TEXT);');
            $pdo->exec("INSERT INTO _bot_check_writability (val) VALUES ('check');");
            $pdo->exec('DROP TABLE _bot_check_writability;');

            $dbPath = config('database.connections.sqlite.database');
            return [
                'name' => 'SQLite Writability',
                'status' => 'pass',
                'message' => "Writable database at [{$dbPath}]",
            ];
        } catch (Throwable $e) {
            return [
                'name' => 'SQLite Writability',
                'status' => 'fail',
                'message' => 'Write test failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check active journal mode (WAL vs DELETE).
     */
    protected function checkJournalMode(): array
    {
        try {
            $connection = DB::connection();
            $result = $connection->select('PRAGMA journal_mode');
            $mode = strtolower(trim((string) ($result[0]->journal_mode ?? 'unknown')));

            if ($mode === 'wal') {
                return [
                    'name' => 'SQLite Journal Mode',
                    'status' => 'pass',
                    'message' => 'WAL mode active (optimal concurrency)',
                ];
            }

            if ($mode === 'delete' || $mode === 'memory') {
                return [
                    'name' => 'SQLite Journal Mode',
                    'status' => 'pass',
                    'message' => "{$mode} mode active",
                ];
            }

            return [
                'name' => 'SQLite Journal Mode',
                'status' => 'warn',
                'message' => "Non-standard journal mode active: [{$mode}]",
            ];
        } catch (Throwable $e) {
            return [
                'name' => 'SQLite Journal Mode',
                'status' => 'fail',
                'message' => 'Unable to read journal mode: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check outbound HTTPS connectivity to api.telegram.org.
     */
    protected function checkTelegramConnectivity(): array
    {
        try {
            $response = Http::timeout(5)->get('https://api.telegram.org');
            $statusCode = $response->status();

            if ($statusCode > 0) {
                return [
                    'name' => 'Telegram Connectivity',
                    'status' => 'pass',
                    'message' => "Outbound HTTPS to api.telegram.org reachable (HTTP {$statusCode})",
                ];
            }

            return [
                'name' => 'Telegram Connectivity',
                'status' => 'fail',
                'message' => 'Received status code 0 from api.telegram.org',
            ];
        } catch (Throwable $e) {
            return [
                'name' => 'Telegram Connectivity',
                'status' => 'fail',
                'message' => 'Outbound HTTPS failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check cron heartbeat timestamp.
     */
    protected function checkCronHeartbeat(): array
    {
        try {
            $heartbeat = Cache::get('cron:heartbeat');

            if ($heartbeat === null) {
                return [
                    'name' => 'Cron Heartbeat',
                    'status' => 'warn',
                    'message' => 'No heartbeat recorded yet. Ensure cron runs "schedule:run" every 5 minutes.',
                ];
            }

            $diffSeconds = now()->timestamp - (int) $heartbeat;

            // Heartbeat considered active if run within the last 15 minutes (900 seconds)
            if ($diffSeconds <= 900) {
                return [
                    'name' => 'Cron Heartbeat',
                    'status' => 'pass',
                    'message' => "Heartbeat active (last tick {$diffSeconds}s ago)",
                ];
            }

            return [
                'name' => 'Cron Heartbeat',
                'status' => 'warn',
                'message' => "Heartbeat overdue (last tick {$diffSeconds}s ago). Verify cron execution.",
            ];
        } catch (Throwable $e) {
            return [
                'name' => 'Cron Heartbeat',
                'status' => 'warn',
                'message' => 'Could not inspect cache heartbeat: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Render CLI table output.
     */
    protected function renderTable(array $results): void
    {
        $rows = [];
        foreach ($results as $item) {
            $statusLabel = match ($item['status']) {
                'pass' => '<info>[PASS]</info>',
                'fail' => '<error>[FAIL]</error>',
                'warn' => '<comment>[WARN]</comment>',
                default => '[INFO]',
            };

            $rows[] = [$item['name'], $statusLabel, $item['message']];
        }

        $this->table(['Check', 'Status', 'Details'], $rows);
    }
}
