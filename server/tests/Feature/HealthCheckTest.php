<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_returns_200_when_healthy(): void
    {
        // Set fresh cron heartbeat (5 minutes ago)
        Cache::put('cron:heartbeat', now()->subMinutes(5)->timestamp, now()->addHours(1));

        $response = $this->getJson('/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'database' => 'writable',
                'cron' => 'fresh',
            ])
            ->assertJsonStructure([
                'status',
                'database',
                'cron',
                'cron_last_heartbeat_seconds_ago',
                'checked_at',
            ]);
    }

    public function test_health_check_returns_503_when_cron_is_stale(): void
    {
        Http::fake([
            'https://api.telegram.org*' => Http::response(['ok' => true], 200),
        ]);

        Config::set('services.telegram.bot_token', 'test_bot_token');
        Config::set('services.telegram.admin_chat_id', '123456789');

        // Set stale cron heartbeat (20 minutes ago > 15 min threshold)
        Cache::put('cron:heartbeat', now()->subMinutes(20)->timestamp, now()->addHours(1));

        $response = $this->getJson('/health');

        $response->assertStatus(503)
            ->assertJson([
                'status' => 'unhealthy',
                'database' => 'writable',
                'cron' => 'stale',
            ]);

        // Verify Telegram alert was triggered
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage') &&
                   str_contains($request['text'], 'SYSTEM HEALTH ALERT');
        });
    }

    public function test_health_check_returns_503_when_cron_never_ran(): void
    {
        Http::fake([
            'https://api.telegram.org*' => Http::response(['ok' => true], 200),
        ]);

        Config::set('services.telegram.bot_token', 'test_bot_token');
        Config::set('services.telegram.admin_chat_id', '123456789');

        Cache::forget('cron:heartbeat');

        $response = $this->getJson('/health');

        $response->assertStatus(503)
            ->assertJson([
                'status' => 'unhealthy',
                'cron' => 'stale',
                'cron_last_heartbeat_seconds_ago' => null,
            ]);
    }

    public function test_health_check_debounces_telegram_alerts(): void
    {
        Http::fake([
            'https://api.telegram.org*' => Http::response(['ok' => true], 200),
        ]);

        Config::set('services.telegram.bot_token', 'test_bot_token');
        Config::set('services.telegram.admin_chat_id', '123456789');
        Cache::forget('cron:heartbeat');

        // First call sends alert
        $this->getJson('/health')->assertStatus(503);
        Http::assertSentCount(1);

        // Second immediate call still returns 503 but does NOT send another alert (debounced for 30m)
        $this->getJson('/health')->assertStatus(503);
        Http::assertSentCount(1);
    }
}
