<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes & Scheduler
|--------------------------------------------------------------------------
|
| Scheduled tasks designed for shared hosting execution every 5 minutes.
|
*/

// Heartbeat recording for bot:check health verification
Schedule::call(function (): void {
    Cache::put('cron:heartbeat', now()->timestamp, now()->addHours(24));
})->everyMinute()->name('cron:heartbeat');

// Database queue processing in short-lived intervals (max 50 seconds to prevent shared host timeout)
Schedule::command('queue:work database --stop-when-empty --max-time=50 --tries=3')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Prune expired bot conversation states (>30m TTL) and old update dedupe logs (>24h)
Schedule::call(function (): void {
    \App\Models\BotState::where('expires_at', '<', now())->delete();
    \App\Models\BotUpdate::where('created_at', '<', now()->subHours(24))->delete();
})->everyFiveMinutes()->name('bot:prune-states');

// Daily evening summary to shop owners
Schedule::command('bot:daily-summary')
    ->everyFiveMinutes()
    ->name('bot:daily-summary');

// Nightly database backup: VACUUM INTO snapshot, gzip, AES-256-GCM encrypt, off-server Telegram copy
Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->name('backup:run')
    ->withoutOverlapping();

// Nightly SQLite integrity check logged for operational safety
Schedule::call(function (): void {
    try {
        $result = \Illuminate\Support\Facades\DB::select('PRAGMA integrity_check;');
        $status = $result[0]->integrity_check ?? 'unknown';
        if ($status === 'ok') {
            \Illuminate\Support\Facades\Log::info('Nightly SQLite PRAGMA integrity_check: OK');
        } else {
            \Illuminate\Support\Facades\Log::error('Nightly SQLite PRAGMA integrity_check FAILED: ' . json_encode($result));
        }
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Nightly SQLite integrity check error: ' . $e->getMessage());
    }
})->dailyAt('03:00')->name('sqlite:integrity-check');
