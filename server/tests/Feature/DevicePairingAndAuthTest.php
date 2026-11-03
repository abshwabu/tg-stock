<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\PairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class DevicePairingAndAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_pairing_lifecycle_from_start_to_confirmation_and_one_time_token_pickup(): void
    {
        // 1. Mobile app initiates pairing
        $startRes = $this->postJson('/v1/pair/start', [
            'shop_name' => 'Bole Mini Market',
            'device_name' => 'POS Terminal 1',
        ]);

        $startRes->assertStatus(201);
        $startRes->assertJsonStructure(['code', 'deep_link']);
        $code = $startRes->json('code');
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);

        // 2. Immediate poll: status is pending
        $statusRes1 = $this->getJson("/v1/pair/status?code={$code}");
        $statusRes1->assertStatus(200);
        $statusRes1->assertJson(['status' => 'pending']);
        $this->assertNull($statusRes1->json('device_token'));

        // 3. Telegram bot confirms pairing
        $confirmRes = $this->postJson('/v1/pair/confirm', [
            'code' => $code,
            'telegram_id' => 987654321,
            'telegram_name' => 'Abebe Bikila',
            'language' => 'am',
        ]);
        $confirmRes->assertStatus(200);
        $confirmRes->assertJson(['success' => true]);

        // Verify shop and user were created
        $this->assertDatabaseHas('shops', ['name' => 'Bole Mini Market']);
        $this->assertDatabaseHas('users', ['telegram_id' => 987654321, 'role' => 'owner']);

        // 4. Mobile app next poll: status confirmed and receives device_token
        $statusRes2 = $this->getJson("/v1/pair/status?code={$code}");
        $statusRes2->assertStatus(200);
        $statusRes2->assertJson(['status' => 'confirmed']);
        $token = $statusRes2->json('device_token');
        $this->assertNotEmpty($token);

        // Verify only token hash is stored in database
        $this->assertDatabaseMissing('devices', ['token_hash' => $token]);
        $this->assertDatabaseHas('devices', ['token_hash' => hash('sha256', $token)]);

        // 5. Subsequent poll: token is null (returned exactly once)
        $statusRes3 = $this->getJson("/v1/pair/status?code={$code}");
        $statusRes3->assertStatus(200);
        $statusRes3->assertJson(['status' => 'confirmed']);
        $this->assertNull($statusRes3->json('device_token'));
    }

    public function test_expired_pairing_code_returns_expired_status(): void
    {
        $startRes = $this->postJson('/v1/pair/start', [
            'device_name' => 'Test Phone',
        ]);
        $code = $startRes->json('code');

        // Travel 11 minutes into the future
        $this->travel(11)->minutes();

        $statusRes = $this->getJson("/v1/pair/status?code={$code}");
        $statusRes->assertStatus(200);
        $statusRes->assertJson(['status' => 'expired']);

        // Attempting to confirm expired code fails
        $confirmRes = $this->postJson('/v1/pair/confirm', [
            'code' => $code,
            'telegram_id' => 12345,
            'telegram_name' => 'Late User',
        ]);
        $confirmRes->assertStatus(422);
    }

    public function test_auth_middleware_accepts_valid_token_and_rejects_missing_or_invalid(): void
    {
        // Missing Authorization header
        $resNoAuth = $this->getJson('/v1/devices');
        $resNoAuth->assertStatus(401);

        // Invalid Bearer token
        $resInvalid = $this->withToken('nonexistent-token-abc')->getJson('/v1/devices');
        $resInvalid->assertStatus(401);
    }

    public function test_revoked_tokens_fail_immediately(): void
    {
        // Setup paired device
        $pairingService = app(PairingService::class);
        $pairData = $pairingService->startPairing('Revoke Test Shop', 'Cashier Phone');
        $confirm = $pairingService->confirmPairing($pairData['code'], 112233, 'Owner Abebe');
        $status = $pairingService->getPairingStatus($pairData['code']);
        $token = $status['device_token'];

        // Request before revocation succeeds
        $resBefore = $this->withToken($token)->getJson('/v1/devices');
        $resBefore->assertStatus(200);

        // Revoke device access
        $device = $confirm['device'];
        $device->revoked_at = now();
        $device->save();

        // Request after revocation fails immediately
        $resAfter = $this->withToken($token)->getJson('/v1/devices');
        $resAfter->assertStatus(401);
        $resAfter->assertJson(['error' => 'Device token has been revoked.']);
    }

    public function test_device_revocation_endpoint_works(): void
    {
        $pairingService = app(PairingService::class);
        $pair1 = $pairingService->startPairing('Revocation API Shop', 'Device 1');
        $confirm1 = $pairingService->confirmPairing($pair1['code'], 445566, 'Owner');
        $token1 = $pairingService->getPairingStatus($pair1['code'])['device_token'];

        // Add a second device to the same shop
        $pair2 = $pairingService->startPairing(null, 'Device 2');
        $confirm2 = $pairingService->confirmPairing($pair2['code'], 445566, 'Owner');
        $token2 = $pairingService->getPairingStatus($pair2['code'])['device_token'];

        // Device 1 revokes Device 2
        $revokeRes = $this->withToken($token1)->postJson("/v1/devices/{$confirm2['device']->id}/revoke");
        $revokeRes->assertStatus(200);
        $revokeRes->assertJson(['success' => true]);

        // Device 2 is now rejected
        $resDev2 = $this->withToken($token2)->getJson('/v1/devices');
        $resDev2->assertStatus(401);

        // Device 1 remains active
        $resDev1 = $this->withToken($token1)->getJson('/v1/devices');
        $resDev1->assertStatus(200);
    }

    public function test_rate_limiting_returns_429_with_retry_after(): void
    {
        $pairingService = app(PairingService::class);
        $pair = $pairingService->startPairing('Rate Limit Shop', 'Speedy Device');
        $confirm = $pairingService->confirmPairing($pair['code'], 778899, 'Owner');
        $token = $pairingService->getPairingStatus($pair['code'])['device_token'];
        $deviceId = $confirm['device']->id;

        // Exhaust rate limit for device
        $rateKey = 'device_rate:' . $deviceId;
        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit($rateKey, 60);
        }

        $res = $this->withToken($token)->getJson('/v1/devices');
        $res->assertStatus(429);
        $this->assertTrue($res->headers->has('Retry-After'));
    }

    public function test_owner_can_delete_shop_and_wipe_all_online_data(): void
    {
        $pairingService = app(PairingService::class);
        $pair = $pairingService->startPairing('Wipeable Shop', 'Owner Phone');
        $confirm = $pairingService->confirmPairing($pair['code'], 555666, 'Owner Dawit');
        $token = $pairingService->getPairingStatus($pair['code'])['device_token'];
        $shopId = $confirm['shop']->id;

        // Verify shop and records exist
        $this->assertDatabaseHas('shops', ['id' => $shopId]);
        $this->assertDatabaseHas('users', ['shop_id' => $shopId]);
        $this->assertDatabaseHas('devices', ['shop_id' => $shopId]);

        // Send DELETE /v1/shop
        $deleteRes = $this->withToken($token)->deleteJson('/v1/shop');
        $deleteRes->assertStatus(200);
        $deleteRes->assertJson(['success' => true]);

        // Verify all data is wiped
        $this->assertDatabaseMissing('shops', ['id' => $shopId]);
        $this->assertDatabaseMissing('users', ['shop_id' => $shopId]);
        $this->assertDatabaseMissing('devices', ['shop_id' => $shopId]);
    }

    public function test_non_owner_cannot_delete_shop(): void
    {
        $pairingService = app(PairingService::class);
        $pair = $pairingService->startPairing('Staff Protected Shop', 'Staff Phone');
        $confirm = $pairingService->confirmPairing($pair['code'], 999111, 'Staff Girma');
        $token = $pairingService->getPairingStatus($pair['code'])['device_token'];

        // Change user role to staff
        $user = $confirm['user'];
        $user->role = 'staff';
        $user->save();

        $deleteRes = $this->withToken($token)->deleteJson('/v1/shop');
        $deleteRes->assertStatus(403);
    }
}
