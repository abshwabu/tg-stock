<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\License;
use App\Models\Plan;
use App\Models\Revocation;
use App\Services\CrockfordBase32;
use App\Services\HmacService;
use App\Services\KeyManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HmacApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret;
    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        app(KeyManagerService::class)->initializeRootKey(1);

        $this->secret = config('license.hmac_secret', 'testing-hmac-shared-secret-for-api-tests');

        $this->plan = Plan::create([
            'name' => 'Monthly Pro',
            'kind' => 'monthly',
            'duration_days' => 30,
            'seats' => 1,
            'features' => 1,
            'price_etb' => 500,
            'active' => true,
        ]);
    }

    protected function makeSignedHeaders(string $method, string $uri, string $body = '', ?int $customTime = null, ?string $customNonce = null, ?string $customSecret = null): array
    {
        $secret = $customSecret ?? $this->secret;
        $timestamp = $customTime ?? time();
        $nonce = $customNonce ?? bin2hex(random_bytes(16));

        $sig = HmacService::computeSignature($secret, $timestamp, $nonce, $method, $uri, $body);

        return [
            'Content-Type' => 'application/json',
            'X-TGStock-Timestamp' => (string)$timestamp,
            'X-TGStock-Nonce' => $nonce,
            'X-TGStock-Signature' => $sig,
        ];
    }

    public function test_valid_hmac_request_succeeds(): void
    {
        $code = CrockfordBase32::makeCode('D', random_bytes(8));
        $body = [
            'telegram_id' => 'user_test',
            'name' => 'Test User',
            'phone' => '0911223344',
            'plan_id' => $this->plan->id,
            'code' => $code,
            'amount' => 500,
            'method' => 'telebirr',
            'reference' => 'TB12345',
        ];
        $json = json_encode($body);

        $headers = $this->makeSignedHeaders('POST', '/api/v1/payment-requests', $json);
        $res = $this->postJson('/api/v1/payment-requests', $body, $headers);

        $res->assertStatus(201);
        $res->assertJsonFragment(['status' => 'success']);
    }

    public function test_rejects_invalid_hmac_signature(): void
    {
        $headers = $this->makeSignedHeaders('GET', '/api/v1/revocations', '', null, null, 'wrong-secret');
        $res = $this->getJson('/api/v1/revocations', $headers);

        $res->assertStatus(401);
        $res->assertJsonFragment(['error' => 'Unauthorized']);
    }

    public function test_rejects_timestamp_outside_5_minute_window(): void
    {
        // 10 minutes in the past
        $expiredTime = time() - 600;
        $headers = $this->makeSignedHeaders('GET', '/api/v1/revocations', '', $expiredTime);
        $res = $this->getJson('/api/v1/revocations', $headers);

        $res->assertStatus(401);
        $res->assertJsonFragment(['error' => 'Unauthorized']);
    }

    public function test_rejects_replay_attack_with_same_nonce(): void
    {
        $fixedNonce = 'fixed-test-nonce-1234567890';
        $now = time();

        $headers1 = $this->makeSignedHeaders('GET', '/api/v1/revocations', '', $now, $fixedNonce);
        $res1 = $this->getJson('/api/v1/revocations', $headers1);
        $res1->assertStatus(200);

        // Second request with the exact same nonce should be rejected as replay
        $headers2 = $this->makeSignedHeaders('GET', '/api/v1/revocations', '', $now, $fixedNonce);
        $res2 = $this->getJson('/api/v1/revocations', $headers2);
        $res2->assertStatus(401);
        $this->assertStringContainsString('Replay attack detected', $res2->json('message'));
    }

    public function test_get_license_by_code(): void
    {
        $bindBytes = random_bytes(8);
        $code = CrockfordBase32::makeCode('D', $bindBytes);

        $customer = Customer::create([
            'name' => 'API Customer',
            'phone' => '0911000000',
        ]);

        $license = License::create([
            'uuid' => (string)Str::uuid(),
            'customer_id' => $customer->id,
            'plan_id' => $this->plan->id,
            'type' => 'device',
            'bind_code' => $code,
            'bind_bytes_hex' => bin2hex($bindBytes),
            'status' => 'active',
            'current_exp_day' => 1080,
        ]);

        $uri = "/api/v1/licenses/by-code/{$code}";
        $headers = $this->makeSignedHeaders('GET', $uri);
        $res = $this->getJson($uri, $headers);

        $res->assertStatus(200);
        $res->assertJsonFragment([
            'uuid' => $license->uuid,
            'bind_code' => $code,
            'type' => 'device',
        ]);
    }

    public function test_get_revocations_returns_signed_revocation_list(): void
    {
        $customer = Customer::create(['name' => 'Revoked Cust', 'phone' => '0911000000']);
        $license = License::create([
            'uuid' => (string)Str::uuid(),
            'customer_id' => $customer->id,
            'plan_id' => $this->plan->id,
            'type' => 'device',
            'bind_code' => CrockfordBase32::makeCode('D', random_bytes(8)),
            'bind_bytes_hex' => bin2hex(random_bytes(8)),
            'status' => 'revoked',
            'current_exp_day' => 1000,
        ]);

        Revocation::create([
            'license_id' => $license->id,
            'license_id_u64_hex' => 'deadbeef00000001',
            'reason' => 'Refund processed',
        ]);

        $headers = $this->makeSignedHeaders('GET', '/api/v1/revocations');
        $res = $this->getJson('/api/v1/revocations', $headers);

        $res->assertStatus(200);
        $res->assertJsonStructure([
            'status',
            'revocation_list' => [
                'ver',
                'kid',
                'list_day',
                'count',
                'revoked_ids',
                'payload_hex',
                'signature_hex',
                'public_key_hex',
            ],
        ]);
        $this->assertContains('deadbeef00000001', $res->json('revocation_list.revoked_ids'));
    }
}
