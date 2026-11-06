<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AppVersionTest extends TestCase
{
    public function test_get_app_version_returns_configured_release_metadata(): void
    {
        Config::set('app_release', [
            'latest' => '1.2.0',
            'latest_code' => 12,
            'min_supported' => '1.0.0',
            'min_supported_code' => 1,
            'download_url' => 'https://stock.yenesuq.com/app/',
            'notes_en' => 'Performance update.',
            'notes_am' => 'የአፈጻጸም ማሻሻያ።',
        ]);

        $res = $this->getJson('/v1/app/version');

        $res->assertStatus(200)
            ->assertExactJson([
                'latest' => '1.2.0',
                'latest_code' => 12,
                'min_supported' => '1.0.0',
                'min_supported_code' => 1,
                'download_url' => 'https://stock.yenesuq.com/app/',
                'notes_en' => 'Performance update.',
                'notes_am' => 'የአፈጻጸም ማሻሻያ።',
            ]);
    }

    public function test_get_app_version_defaults_when_empty(): void
    {
        Config::set('app_release', []);

        $res = $this->getJson('/v1/app/version');

        $res->assertStatus(200)
            ->assertJson([
                'latest' => '1.0.0',
                'latest_code' => 1,
                'min_supported' => '1.0.0',
                'min_supported_code' => 1,
                'download_url' => 'https://stock.yenesuq.com/app/',
            ]);
    }
}
