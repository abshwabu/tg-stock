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
