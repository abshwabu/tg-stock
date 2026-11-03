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
