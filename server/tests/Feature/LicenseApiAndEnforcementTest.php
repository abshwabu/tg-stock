<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LicenseReminder;
use App\Models\Shop;
use App\Models\ShopLicense;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\CrockfordBase32;
use App\Services\DateEpochService;
use App\Services\HmacService;
use App\Services\LicenseEnforcer;
use App\Services\LicenseVerifier;
use App\Services\PairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LicenseApiAndEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected PairingService $pairing;
    protected LicenseEnforcer $enforcer;

    protected string $seed1;
    protected string $sk1;
    protected string $pk1;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->pairing = app(PairingService::class);
        $this->enforcer = app(LicenseEnforcer::class);

        // Derive deterministic Ed25519 root key 1 for signing test tokens
        $this->seed1 = hash('sha256', 'tgstock-offline-licensing-test-key-1', true);
        $kp = sodium_crypto_sign_seed_keypair($this->seed1);
        $this->sk1 = sodium_crypto_sign_secretkey($kp);
        $this->pk1 = sodium_crypto_sign_publickey($kp);
    }

    /**
     * Helper to forge a valid signed token for any shop or device.
     */
    protected function makeSignedToken(
        int $type = 2,
        string $bind = '',
        int $expDay = 0xFFFF,
        int $seats = 3,
        int $features = 0x001F,
        int $issuedDay = 1040,
        int $nbfDay = 1040,
        int $graceDays = 7,
        int $plan = 1,
        int $kid = 1,
        int $ver = 1,
        int $maxMajor = 0
    ): string {
        $licenseId = random_int(1, 999999999);
        $payload = pack(
            'CCCC',
            $ver,
            $kid,
            $type,
            $plan
        ) . pack('J', $licenseId) . pack(
            'a8nnnCCnC',
            str_pad($bind, 8, "\0"),
            $issuedDay,
            $nbfDay,
            $expDay,
            $graceDays,
            $seats,
            $features,
            $maxMajor
        );

        $sig = sodium_crypto_sign_detached("LIC1" . $payload, $this->sk1);
        $binary = $payload . $sig;
        $b32 = CrockfordBase32::encode($binary);
        $groups = str_split($b32, 5);

        return 'LIC1-' . implode('-', $groups);
    }

    /**
     * 1. Test owner device can upload shop-bound token and view license status.
     */
    public function test_owner_device_can_upload_valid_shop_bound_token_and_status_is_activated(): void
    {
        // Setup shop and owner device
        $pair = $this->pairing->startPairing('Mercato Wholesalers', 'Owner Tablet');
        $this->pairing->confirmPairing($pair['code'], 111111, 'Owner Kebede', 'am');
        $token = $this->pairing->getPairingStatus($pair['code'])['device_token'];

        $shop = Shop::where('name', 'Mercato Wholesalers')->firstOrFail();
        $shopBind = $shop->getBindBytes();

        $licenseToken = $this->makeSignedToken(
            type: 2,
            bind: $shopBind,
            expDay: 1400,
            seats: 4,
            features: 0x001F
        );

        // Upload token as owner device
        $res = $this->withToken($token)->postJson('/v1/license', [
            'token' => $licenseToken,
        ]);

        $res->assertStatus(200);
        $res->assertJson(['success' => true]);
        $this->assertSame('ACTIVE', $res->json('license.state'));
        $this->assertSame(4, $res->json('license.seats_total'));
        $this->assertNotEmpty($res->json('server_time'));

        // Query GET /v1/license
        $getRes = $this->withToken($token)->getJson('/v1/license');
        $getRes->assertStatus(200);
        $this->assertSame('ACTIVE', $getRes->json('state'));
        $this->assertSame(1, $getRes->json('seats_used'));
        $this->assertSame(4, $getRes->json('seats_total'));
        $this->assertNotEmpty($getRes->json('server_time'));
    }

    /**
     * 2. Test non-owner device cannot upload license token.
     */
    public function test_non_owner_device_cannot_upload_license_token(): void
    {
        $pair = $this->pairing->startPairing('Bole Branch', 'Cashier Phone');
        $this->pairing->confirmPairing($pair['code'], 222222, 'Owner', 'am');
        $token = $this->pairing->getPairingStatus($pair['code'])['device_token'];

        $shop = Shop::where('name', 'Bole Branch')->firstOrFail();

        // Create cashier staff user
        $cashierUser = User::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $shop->id,
            'telegram_id' => 222999,
            'name' => 'Cashier Almaz',
            'role' => 'cashier',
            'is_active' => true,
        ]);

        // Reassign device to cashier
        $device = Device::withoutGlobalScope(ShopScope::class)->where('token_hash', hash('sha256', $token))->firstOrFail();
        $device->user_id = $cashierUser->id;
        $device->save();

        $licenseToken = $this->makeSignedToken(type: 2, bind: $shop->getBindBytes());

        $res = $this->withToken($token)->postJson('/v1/license', [
            'token' => $licenseToken,
        ]);

        $res->assertStatus(403);
        $res->assertJson(['error' => 'Only owner devices are authorized to upload license tokens.']);
    }

    /**
     * 3. Test upload rejects wrong-shop, device-bound, and invalid tokens.
     */
    public function test_upload_rejects_wrong_shop_and_device_bound_tokens(): void
    {
        $pair = $this->pairing->startPairing('Shop A', 'Tablet');
        $this->pairing->confirmPairing($pair['code'], 333333, 'Owner A', 'am');
        $token = $this->pairing->getPairingStatus($pair['code'])['device_token'];

        // Token bound to a different shop bind
        $foreignBind = substr(hash('sha256', 'another-shop-uuid', true), 0, 8);
        $foreignToken = $this->makeSignedToken(type: 2, bind: $foreignBind);

        $res1 = $this->withToken($token)->postJson('/v1/license', ['token' => $foreignToken]);
        $res1->assertStatus(422);
        $this->assertSame('wrong_bind', $res1->json('reason'));

        // Device-bound token (type 1) presented to shop
        $deviceBoundToken = $this->makeSignedToken(type: 1, bind: $foreignBind);
        $res2 = $this->withToken($token)->postJson('/v1/license', ['token' => $deviceBoundToken]);
        $res2->assertStatus(422);
        $this->assertSame('wrong_bind', $res2->json('reason'));

        // Invalid signature
        $corruptToken = substr($foreignToken, 0, -5) . 'AAAAA';
        $res3 = $this->withToken($token)->postJson('/v1/license', ['token' => $corruptToken]);
        $res3->assertStatus(422);
        $this->assertSame('invalid_signature', $res3->json('reason'));
    }

    /**
     * 4. Test server_time is present in pairing status and sync responses.
     */
    public function test_server_time_is_present_in_pairing_status_and_sync_responses(): void
    {
        $pair = $this->pairing->startPairing('Time Test Shop', 'Terminal');

        $statusRes = $this->getJson("/v1/pair/status?code={$pair['code']}");
        $statusRes->assertStatus(200);
        $this->assertNotEmpty($statusRes->json('server_time'));

        $this->pairing->confirmPairing($pair['code'], 444444, 'Owner Time', 'en');
        $token = $this->pairing->getPairingStatus($pair['code'])['device_token'];

        $syncRes = $this->withToken($token)->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [],
        ]);

        $syncRes->assertStatus(200);
        $this->assertNotEmpty($syncRes->json('server_time'));
    }

    /**
     * 5. Test seat limit: refuse pairing beyond seats with reason seat_limit, and owner revoking a device frees seat.
     */
    public function test_seat_limit_refuses_pairing_beyond_allowed_seats_until_device_revoked(): void
    {
        // Enable unlicensed enforcement or set shop license with seats = 2
        $pair1 = $this->pairing->startPairing('Two Seat Shop', 'Device 1');
        $this->pairing->confirmPairing($pair1['code'], 555555, 'Owner TwoSeat', 'en');
        $token1 = $this->pairing->getPairingStatus($pair1['code'])['device_token'];

        $shop = Shop::where('name', 'Two Seat Shop')->firstOrFail();

        // Install license with seats = 2
        $tokenStr = $this->makeSignedToken(type: 2, bind: $shop->getBindBytes(), seats: 2);
        $this->withToken($token1)->postJson('/v1/license', ['token' => $tokenStr])->assertStatus(200);

        // Pair Device 2 (allowed: 2nd seat)
        $pair2 = $this->pairing->startPairing(null, 'Device 2');
        $confirm2 = $this->postJson('/v1/pair/confirm', [
            'code' => $pair2['code'],
            'telegram_id' => 555555,
            'telegram_name' => 'Owner TwoSeat',
        ]);
        $confirm2->assertStatus(200);

        // Now 2 active devices exist. Attempt to pair Device 3 (beyond seats = 2)
        $pair3 = $this->pairing->startPairing(null, 'Device 3');
        $confirm3 = $this->postJson('/v1/pair/confirm', [
            'code' => $pair3['code'],
            'telegram_id' => 555555,
            'telegram_name' => 'Owner TwoSeat',
        ]);

        // Refused with reason seat_limit
        $confirm3->assertStatus(422);
        $this->assertSame('seat_limit', $confirm3->json('reason'));

        // Owner revokes Device 1
        $d1 = Device::withoutGlobalScope(ShopScope::class)->where('token_hash', hash('sha256', $token1))->firstOrFail();
        $this->withToken($token1)->postJson("/v1/devices/{$d1->id}/revoke")->assertStatus(200);

        // Attempting to pair Device 3 now succeeds
        $confirm3Retry = $this->postJson('/v1/pair/confirm', [
            'code' => $pair3['code'],
            'telegram_id' => 555555,
            'telegram_name' => 'Owner TwoSeat',
        ]);
        $confirm3Retry->assertStatus(200);
        $confirm3Retry->assertJson(['success' => true]);
    }

    /**
     * 6. Test enforcement per state:
     *    - ACTIVE, EXPIRING, GRACE: sync pushes and writes succeed.
     *    - EXPIRED: pulls still work, sync push returns 403 license_expired, bot writes blocked with renewal prompt.
     */
    public function test_license_enforcement_per_state(): void
    {
        $pair = $this->pairing->startPairing('State Test Shop', 'POS Device');
        $this->pairing->confirmPairing($pair['code'], 666666, 'Owner State', 'en');
        $token = $this->pairing->getPairingStatus($pair['code'])['device_token'];
        $shop = Shop::where('name', 'State Test Shop')->firstOrFail();

        $today = DateEpochService::today();

        // A. ACTIVE license (exp_day = today + 30)
        $activeToken = $this->makeSignedToken(type: 2, bind: $shop->getBindBytes(), expDay: $today + 30);
        $this->withToken($token)->postJson('/v1/license', ['token' => $activeToken])->assertStatus(200);

        $prodId = (string) Str::uuid7();
        $pushRes = $this->withToken($token)->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $prodId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => now()->timestamp * 1000,
                    'data' => ['id' => $prodId, 'name' => 'Active Product', 'unit' => 'pcs'],
                ],
            ],
        ]);
        $pushRes->assertStatus(200);
        $this->assertContains($prodId, $pushRes->json('accepted'));

        // B. EXPIRED license (exp_day = today - 20, grace_days = 7 -> past grace)
        $expiredToken = $this->makeSignedToken(
            type: 2,
            bind: $shop->getBindBytes(),
            expDay: $today - 20,
            graceDays: 7
        );
        ShopLicense::where('shop_id', $shop->id)->update([
            'token' => $expiredToken,
            'status' => 'expired',
        ]);

        // Pull (empty changes) continues to work after grace
        $pullRes = $this->withToken($token)->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [],
        ]);
        $pullRes->assertStatus(200);

        // Push returns 403 license_expired
        $prodId2 = (string) Str::uuid7();
        $pushExpiredRes = $this->withToken($token)->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $prodId2,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => now()->timestamp * 1000,
                    'data' => ['id' => $prodId2, 'name' => 'Blocked Product', 'unit' => 'pcs'],
                ],
            ],
        ]);
        $pushExpiredRes->assertStatus(403);
        $this->assertSame('license_expired', $pushExpiredRes->json('error'));

        // Bot write attempt while expired
        $user = User::withoutGlobalScope(ShopScope::class)->where('shop_id', $shop->id)->where('role', 'owner')->firstOrFail();
        $router = app(\App\Services\Telegram\TelegramRouter::class);

        // Callback query to start sale while expired -> sends renewal notice
        $router->dispatch([
            'callback_query' => [
                'id' => 'cq-1234',
                'from' => ['id' => 666666],
                'message' => ['chat' => ['id' => 666666], 'message_id' => 99],
                'data' => 'menu:sale:0',
            ],
        ]);

        // Verified canWrite returns false
        $this->assertFalse($this->enforcer->canWrite($shop));
    }

    /**
     * 7. Test feature bitmask gating: missing online_sync bit blocks sync.
     */
    public function test_feature_bitmask_gating_blocks_unlicensed_features(): void
    {
        $pair = $this->pairing->startPairing('Feature Test Shop', 'Terminal');
        $this->pairing->confirmPairing($pair['code'], 777777, 'Owner Feature', 'en');
        $token = $this->pairing->getPairingStatus($pair['code'])['device_token'];
        $shop = Shop::where('name', 'Feature Test Shop')->firstOrFail();

        // Token with telegram_bot ONLY (features = 0x0002), missing online_sync (0x0001)
        $noSyncToken = $this->makeSignedToken(
            type: 2,
            bind: $shop->getBindBytes(),
            features: LicenseVerifier::FEATURE_TELEGRAM_BOT
        );
        ShopLicense::updateOrCreate(
            ['shop_id' => $shop->id],
            [
                'token' => $noSyncToken,
                'status' => 'active',
            ]
        );

        $prodId = (string) Str::uuid7();
        $res = $this->withToken($token)->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $prodId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => now()->timestamp * 1000,
                    'data' => ['id' => $prodId, 'name' => 'Item', 'unit' => 'pcs'],
                ],
            ],
        ]);

        $res->assertStatus(403);
        $this->assertSame('feature_not_licensed', $res->json('error'));
        $this->assertSame('online_sync', $res->json('feature'));
    }

    /**
     * 8. Test console webhook HMAC authentication, replay rejection, and event handling.
     */
    public function test_console_webhook_authentication_replay_protection_and_event_handling(): void
    {
        $pair = $this->pairing->startPairing('Webhook Shop', 'Phone');
        $this->pairing->confirmPairing($pair['code'], 888888, 'Owner Webhook', 'en');
        $shop = Shop::where('name', 'Webhook Shop')->firstOrFail();

        $newToken = $this->makeSignedToken(type: 2, bind: $shop->getBindBytes(), expDay: 1350);

        $secret = config('license.webhook_secret');
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));

        $payload = [
            'event' => 'token_issued',
            'timestamp' => $timestamp,
            'payload' => [
                'shop_id' => $shop->id,
                'token' => $newToken,
                'plan' => 1,
            ],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $uri = '/api/console/license-events';
        $sig = HmacService::computeSignature($secret, $timestamp, $nonce, 'POST', $uri, $body);

        // 1. Valid webhook dispatch -> 200
        $res = $this->withHeaders([
            'Content-Type' => 'application/json',
            'X-TGStock-Timestamp' => (string) $timestamp,
            'X-TGStock-Nonce' => $nonce,
            'X-TGStock-Signature' => $sig,
        ])->postJson($uri, $payload);

        $res->assertStatus(200);
        $res->assertJson(['success' => true]);

        // Verify token saved in shop_licenses
        $savedLicense = ShopLicense::where('shop_id', $shop->id)->first();
        $this->assertNotNull($savedLicense);
        $this->assertSame($newToken, $savedLicense->token);

        // 2. Replay with same nonce -> 401
        $resReplay = $this->withHeaders([
            'Content-Type' => 'application/json',
            'X-TGStock-Timestamp' => (string) $timestamp,
            'X-TGStock-Nonce' => $nonce,
            'X-TGStock-Signature' => $sig,
        ])->postJson($uri, $payload);

        $resReplay->assertStatus(401);
        $this->assertSame('Nonce replay detected.', $resReplay->json('error'));

        // 3. Stale timestamp (> 300 seconds) -> 401
        $staleNonce = bin2hex(random_bytes(16));
        $staleTime = time() - 360;
        $staleSig = HmacService::computeSignature($secret, $staleTime, $staleNonce, 'POST', $uri, $body);

        $resStale = $this->withHeaders([
            'Content-Type' => 'application/json',
            'X-TGStock-Timestamp' => (string) $staleTime,
            'X-TGStock-Nonce' => $staleNonce,
            'X-TGStock-Signature' => $staleSig,
        ])->postJson($uri, $payload);

        $resStale->assertStatus(401);
    }

    /**
     * 9. Test offline-only customer /activate and /trial flow.
     */
    public function test_offline_only_customer_activate_command_and_trial_flow(): void
    {
        $router = app(\App\Services\Telegram\TelegramRouter::class);

        // User without shop sends /activate <device_code>
        $router->dispatch([
            'message' => [
                'chat' => ['id' => 999901],
                'from' => ['id' => 999901, 'first_name' => 'Offline', 'last_name' => 'Customer'],
                'text' => '/activate D7ME-5Q0R-XFHD-QJS',
            ],
        ]);

        // Deep link /start code_D7ME-5Q0R-XFHD-QJS
        $router->dispatch([
            'message' => [
                'chat' => ['id' => 999902],
                'from' => ['id' => 999902, 'first_name' => 'Deep', 'last_name' => 'Linker'],
                'text' => '/start code_D7ME-5Q0R-XFHD-QJS',
            ],
        ]);

        // Trial request /trial D7ME-5Q0R-XFHD-QJS
        Http::fake([
            '*/api/v1/trials' => Http::response([
                'success' => true,
                'token' => 'LIC1-TRIAL-MOCK-TOKEN',
                'exp_day' => 1064,
            ], 200),
        ]);

        $router->dispatch([
            'message' => [
                'chat' => ['id' => 999903],
                'from' => ['id' => 999903, 'first_name' => 'Trial', 'last_name' => 'User'],
                'text' => '/trial D7ME-5Q0R-XFHD-QJS',
            ],
        ]);

        // Assert trial API was called
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/trials');
        });
    }

    /**
     * 10. Test expiry reminders idempotency across milestones.
     */
    public function test_license_expiry_reminders_idempotency_and_milestones(): void
    {
        $pair = $this->pairing->startPairing('Reminder Shop', 'Terminal');
        $this->pairing->confirmPairing($pair['code'], 999888, 'Owner Remind', 'en');
        $shop = Shop::where('name', 'Reminder Shop')->firstOrFail();

        // License expires on day 1070
        $token = $this->makeSignedToken(type: 2, bind: $shop->getBindBytes(), expDay: 1070, graceDays: 7);
        ShopLicense::updateOrCreate(
            ['shop_id' => $shop->id],
            [
                'token' => $token,
                'exp_day' => 1070,
                'grace_days' => 7,
                'status' => 'active',
            ]
        );

        // 1. Day 1063 (exactly 7 days before 1070) -> reminder sent
        $this->artisan('license:check-expirations', ['--day' => 1063])
            ->expectsOutputToContain('Sent 1 reminder(s)')
            ->assertSuccessful();

        // 2. Re-run on same day -> 0 reminders sent (idempotent)
        $this->artisan('license:check-expirations', ['--day' => 1063])
            ->expectsOutputToContain('Sent 0 reminder(s)')
            ->assertSuccessful();

        // 3. Day 1067 (3 days before 1070) -> reminder sent
        $this->artisan('license:check-expirations', ['--day' => 1067])
            ->expectsOutputToContain('Sent 1 reminder(s)')
            ->assertSuccessful();

        // 4. Day 1070 (expiry day) -> reminder sent
        $this->artisan('license:check-expirations', ['--day' => 1070])
            ->expectsOutputToContain('Sent 1 reminder(s)')
            ->assertSuccessful();

        // 5. Day 1077 (grace end: 1070 + 7) -> grace end reminder sent
        $this->artisan('license:check-expirations', ['--day' => 1077])
            ->expectsOutputToContain('Sent 1 reminder(s)')
            ->assertSuccessful();

        // Verify database records
        $this->assertDatabaseHas('license_reminders', ['shop_id' => $shop->id, 'milestone' => '7_days', 'day' => 1063]);
        $this->assertDatabaseHas('license_reminders', ['shop_id' => $shop->id, 'milestone' => '3_days', 'day' => 1067]);
        $this->assertDatabaseHas('license_reminders', ['shop_id' => $shop->id, 'milestone' => 'expiry_day', 'day' => 1070]);
        $this->assertDatabaseHas('license_reminders', ['shop_id' => $shop->id, 'milestone' => 'grace_end', 'day' => 1077]);
    }
}
