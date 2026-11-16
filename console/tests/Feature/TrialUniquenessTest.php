<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Trial;
use App\Services\CrockfordBase32;
use App\Services\HmacService;
use App\Services\KeyManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(KeyManagerService::class)->initializeRootKey(1);

        Plan::create([
            'name' => '14-Day Trial',
            'kind' => 'trial',
            'duration_days' => 14,
            'seats' => 1,
            'features' => 1,
            'grace_days' => 3,
            'price_etb' => 0,
            'active' => true,
        ]);
    }

    protected function makeSignedHeaders(string $method, string $uri, array $body): array
    {
        $secret = config('license.hmac_secret', 'testing-hmac-shared-secret-for-api-tests');
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $jsonBody = json_encode($body);

        $sig = HmacService::computeSignature($secret, $timestamp, $nonce, $method, $uri, $jsonBody);

        return [
            'Content-Type' => 'application/json',
            'X-TGStock-Timestamp' => (string)$timestamp,
            'X-TGStock-Nonce' => $nonce,
            'X-TGStock-Signature' => $sig,
        ];
    }

    public function test_trial_uniqueness_by_telegram_id_and_device_code(): void
    {
        $code1 = CrockfordBase32::makeCode('D', random_bytes(8));
        $code2 = CrockfordBase32::makeCode('D', random_bytes(8));

        $payload1 = [
            'telegram_id' => 'user_1001',
            'name' => 'User One',
            'phone' => '0911000001',
            'code' => $code1,
        ];

        // 1. First claim succeeds
        $headers1 = $this->makeSignedHeaders('POST', '/api/v1/trials', $payload1);
        $res1 = $this->postJson('/api/v1/trials', $payload1, $headers1);
        $res1->assertStatus(201);
        $res1->assertJsonFragment(['status' => 'success']);

        // 2. Claim with duplicate Telegram ID and different code fails
        $payloadDupTelegram = [
            'telegram_id' => 'user_1001', // duplicate
            'name' => 'User One Duplicate',
            'phone' => '0911000002',
            'code' => $code2, // different
        ];
        $headersDupTelegram = $this->makeSignedHeaders('POST', '/api/v1/trials', $payloadDupTelegram);
        $res2 = $this->postJson('/api/v1/trials', $payloadDupTelegram, $headersDupTelegram);
        $res2->assertStatus(422);
        $res2->assertJsonFragment(['error' => 'Trial Limit Exceeded']);

        // 3. Claim with different Telegram ID and duplicate code fails
        $payloadDupCode = [
            'telegram_id' => 'user_2002', // different
            'name' => 'User Two',
            'phone' => '0911000003',
            'code' => $code1, // duplicate code1!
        ];
        $headersDupCode = $this->makeSignedHeaders('POST', '/api/v1/trials', $payloadDupCode);
        $res3 = $this->postJson('/api/v1/trials', $payloadDupCode, $headersDupCode);
        $res3->assertStatus(422);
        $res3->assertJsonFragment(['error' => 'Trial Limit Exceeded']);
    }
}
