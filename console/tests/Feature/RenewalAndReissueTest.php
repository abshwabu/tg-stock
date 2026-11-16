<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\License;
use App\Models\LicenseToken;
use App\Models\Plan;
use App\Models\Reissue;
use App\Models\User;
use App\Services\CrockfordBase32;
use App\Services\DateEpochService;
use App\Services\KeyManagerService;
use App\Services\TokenSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RenewalAndReissueTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Customer $customer;
    protected Plan $monthlyPlan;
    protected TokenSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        app(KeyManagerService::class)->initializeRootKey(1);
        $this->signer = app(TokenSigner::class);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
            'totp_enabled_at' => now(),
        ]);

        $this->customer = Customer::create([
            'name' => 'Test Customer',
            'phone' => '0911000000',
        ]);

        $this->monthlyPlan = Plan::create([
            'name' => 'Monthly Plan',
            'kind' => 'monthly',
            'duration_days' => 30,
            'seats' => 1,
            'features' => Plan::FEATURE_ONLINE_SYNC,
            'grace_days' => 7,
            'price_etb' => 500,
            'active' => true,
        ]);
    }

    public function test_renewal_extends_from_max_of_current_expiry_and_today(): void
    {
        $today = DateEpochService::todayDay();
        $bindBytes = random_bytes(8);
        $devCode = CrockfordBase32::makeCode('D', $bindBytes);

        // Case A: Token expires in the future (today + 10 days)
        // Renewal should extend to (today + 10) + 30 days
        $futureExp = $today + 10;
        $licenseFuture = License::create([
            'uuid' => (string)Str::uuid(),
            'customer_id' => $this->customer->id,
            'plan_id' => $this->monthlyPlan->id,
            'type' => 'device',
            'bind_code' => $devCode,
            'bind_bytes_hex' => bin2hex($bindBytes),
            'status' => 'active',
            'current_exp_day' => $futureExp,
        ]);

        $response = $this->actingAs($this->admin)
            ->withSession(['2fa_verified' => true])
            ->post("/admin/licenses/{$licenseFuture->id}/renew");

        $response->assertRedirect();
        $licenseFuture->refresh();
        $this->assertSame($futureExp + 30, $licenseFuture->current_exp_day);

        // Case B: Token expired in the past (today - 20 days)
        // Renewal should extend from max(past, today) = today + 30 days
        $pastExp = $today - 20;
        $licensePast = License::create([
            'uuid' => (string)Str::uuid(),
            'customer_id' => $this->customer->id,
            'plan_id' => $this->monthlyPlan->id,
            'type' => 'device',
            'bind_code' => $devCode,
            'bind_bytes_hex' => bin2hex($bindBytes),
            'status' => 'active',
            'current_exp_day' => $pastExp,
        ]);

        $response2 = $this->actingAs($this->admin)
            ->withSession(['2fa_verified' => true])
            ->post("/admin/licenses/{$licensePast->id}/renew");

        $response2->assertRedirect();
        $licensePast->refresh();
        $this->assertSame($today + 30, $licensePast->current_exp_day);
    }

    public function test_reissue_for_new_device_code_and_tracks_limits(): void
    {
        $today = DateEpochService::todayDay();
        $bindBytes1 = random_bytes(8);
        $devCode1 = CrockfordBase32::makeCode('D', $bindBytes1);

        $license = License::create([
            'uuid' => (string)Str::uuid(),
            'customer_id' => $this->customer->id,
            'plan_id' => $this->monthlyPlan->id,
            'type' => 'device',
            'bind_code' => $devCode1,
            'bind_bytes_hex' => bin2hex($bindBytes1),
            'status' => 'active',
            'current_exp_day' => $today + 20,
        ]);

        // Reissue 1
        $bindBytes2 = random_bytes(8);
        $devCode2 = CrockfordBase32::makeCode('D', $bindBytes2);

        $res1 = $this->actingAs($this->admin)
            ->withSession(['2fa_verified' => true])
            ->post("/admin/licenses/{$license->id}/reissue", [
                'new_bind_code' => $devCode2,
                'reason' => 'First phone replacement',
            ]);

        $res1->assertRedirect();
        $license->refresh();
        $this->assertSame($devCode2, $license->bind_code);
        $this->assertDatabaseHas('reissues', [
            'license_id' => $license->id,
            'old_bind_code' => $devCode1,
            'new_bind_code' => $devCode2,
            'reason' => 'First phone replacement',
        ]);

        // Reissue 2
        $bindBytes3 = random_bytes(8);
        $devCode3 = CrockfordBase32::makeCode('D', $bindBytes3);

        $this->actingAs($this->admin)
            ->withSession(['2fa_verified' => true])
            ->post("/admin/licenses/{$license->id}/reissue", [
                'new_bind_code' => $devCode3,
                'reason' => 'Second replacement',
            ]);

        $reissueCountYear = $license->reissues()->where('created_at', '>=', now()->subYear())->count();
        $this->assertSame(2, $reissueCountYear);

        // Viewing license detail shows warning flag when reissues >= 2
        $detailRes = $this->actingAs($this->admin)
            ->withSession(['2fa_verified' => true])
            ->get("/admin/licenses/{$license->id}");

        $detailRes->assertOk();
        $detailRes->assertSee('Frequent Device Reissue Warning');
    }
}
