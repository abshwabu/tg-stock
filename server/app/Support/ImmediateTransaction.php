<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImmediateTransaction
{
    /**
     * Run a database write transaction using BEGIN IMMEDIATE.
     *
     * In SQLite, BEGIN IMMEDIATE acquires a RESERVED lock immediately,
     * preventing SQLITE_BUSY deadlocks caused by concurrent deferred readers
     * attempting to upgrade to writers.
     */
    public static function run(Closure $callback, int $attempts = 1): mixed
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            return $connection->transaction($callback, $attempts);
        }

        // If PHP >= 8.4 and SQLite transaction_mode is configured,
        // Laravel's transaction() natively executes BEGIN IMMEDIATE.
        if (version_compare(PHP_VERSION, '8.4.0', '>=')) {
            return $connection->transaction($callback, $attempts);
        }

        // Manual fallback for PHP < 8.4
        $pdo = $connection->getPdo();
        $pdo->exec('BEGIN IMMEDIATE TRANSACTION');

        try {
            $result = $callback();
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }
}
