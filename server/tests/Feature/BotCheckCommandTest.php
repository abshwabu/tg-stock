<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bot_check_runs_successfully(): void
    {
        // Mock external Telegram HTTP request
        Http::fake([
            'https://api.telegram.org*' => Http::response(['ok' => true], 200),
        ]);

        // Stamp cron heartbeat
        Cache::put('cron:heartbeat', now()->timestamp, now()->addHours(1));

        $this->artisan('bot:check')
            ->expectsOutputToContain('PHP Version')
            ->expectsOutputToContain('PHP Extensions')
            ->expectsOutputToContain('SQLite Writability')
            ->expectsOutputToContain('SQLite Journal Mode')
            ->expectsOutputToContain('Telegram Connectivity')
            ->expectsOutputToContain('Cron Heartbeat')
            ->assertSuccessful();
    }

    public function test_bot_check_outputs_valid_json(): void
    {
        Http::fake([
            'https://api.telegram.org*' => Http::response(['ok' => true], 200),
        ]);

        Cache::put('cron:heartbeat', now()->timestamp, now()->addHours(1));

        $this->artisan('bot:check', ['--json' => true])
            ->assertSuccessful();
    }
}
