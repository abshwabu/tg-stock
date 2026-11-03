<?php

namespace App\Providers;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use PDO;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            $this->configureSqliteConnection($event->connection);
        });
    }

    /**
     * Configure SQLite connection with required pragmas and WAL detection.
     */
    protected function configureSqliteConnection(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        /** @var PDO $pdo */
        $pdo = $connection->getPdo();

        // 1. Core pragmas for shared hosting stability
        $pdo->exec('PRAGMA busy_timeout = 5000;');
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');

        // 2. Test WAL filesystem support with fallback to DELETE
        $activeMode = 'delete';
        try {
            $stmt = $pdo->query('PRAGMA journal_mode = WAL;');
            $result = strtolower(trim((string) $stmt->fetchColumn()));

            if ($result === 'wal' || $result === 'memory') {
                $activeMode = $result;
            } else {
                // Filesystem does not support WAL or returned delete
                $pdo->exec('PRAGMA journal_mode = DELETE;');
                $activeMode = 'delete';
            }
        } catch (Throwable $e) {
            try {
                $pdo->exec('PRAGMA journal_mode = DELETE;');
            } catch (Throwable) {
                // Ignore fallback error
            }
            $activeMode = 'delete';
            Log::warning('SQLite WAL journal_mode not supported by filesystem: ' . $e->getMessage());
        }

        Log::info("SQLite connection initialized. Active journal_mode: [{$activeMode}].");
    }
}
