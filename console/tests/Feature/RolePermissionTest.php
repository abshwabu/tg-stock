<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Keypair;
use App\Models\License;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CrockfordBase32;
use App\Services\KeyManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $supportUser;
    protected License $sampleLicense;
    protected Payment $samplePayment;
    protected Keypair $activeKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activeKey = app(KeyManagerService::class)->initializeRootKey(1);

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@example.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
            'totp_enabled_at' => now(),
        ]);

        $this->supportUser = User::create([
            'name' => 'Support Staff',
            'email' => 'support@example.com',
            'password' => bcrypt('password'),
            'role' => 'support',
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
            'totp_enabled_at' => now(),
        ]);

        $customer = Customer::create([
            'name' => 'Customer A',
            'phone' => '0911000000',
        ]);

        $plan = Plan::create([
            'name' => 'Monthly',
            'kind' => 'monthly',
            'duration_days' => 30,
            'seats' => 1,
            'features' => 0,
            'price_etb' => 500,
            'active' => true,
        ]);

        $this->sampleLicense = License::create([
            'uuid' => (string)Str::uuid(),
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'type' => 'device',
            'bind_code' => CrockfordBase32::makeCode('D', random_bytes(8)),
            'bind_bytes_hex' => bin2hex(random_bytes(8)),
            'status' => 'active',
            'current_exp_day' => 1080,
        ]);

        $this->samplePayment = Payment::create([
            'customer_id' => $customer->id,
            'amount' => 500,
            'method' => 'telebirr',
            'status' => 'pending',
        ]);
    }

    public function test_support_cannot_rotate_keys(): void
    {
        $response = $this->actingAs($this->supportUser)
            ->withSession(['2fa_verified' => true])
            ->post('/admin/keys/rotate');

        $response->assertStatus(403);
    }

    public function test_support_cannot_export_keys(): void
    {
        $response = $this->actingAs($this->supportUser)
            ->withSession(['2fa_verified' => true])
            ->post("/admin/keys/{$this->activeKey->kid}/export", [
                'password' => 'password',
                'totp_code' => '123456',
                'export_passphrase' => 'SecretPass123',
            ]);

        $response->assertStatus(403);
    }

    public function test_support_cannot_revoke_licenses(): void
    {
        $response = $this->actingAs($this->supportUser)
            ->withSession(['2fa_verified' => true])
            ->post("/admin/licenses/{$this->sampleLicense->id}/revoke", [
                'reason' => 'Unauthorized attempt',
            ]);

        $response->assertStatus(403);
        $this->sampleLicense->refresh();
        $this->assertSame('active', $this->sampleLicense->status);
    }

    public function test_support_cannot_approve_or_reject_payments(): void
    {
        $resApprove = $this->actingAs($this->supportUser)
            ->withSession(['2fa_verified' => true])
            ->post("/admin/payments/{$this->samplePayment->id}/approve");

        $resApprove->assertStatus(403);

        $resReject = $this->actingAs($this->supportUser)
            ->withSession(['2fa_verified' => true])
            ->post("/admin/payments/{$this->samplePayment->id}/reject", ['reason' => 'test']);

        $resReject->assertStatus(403);

        $this->samplePayment->refresh();
        $this->assertSame('pending', $this->samplePayment->status);
    }

    public function test_super_admin_can_perform_restricted_actions(): void
    {
        // 1. Can rotate keys
        $resRotate = $this->actingAs($this->superAdmin)
            ->withSession(['2fa_verified' => true])
            ->post('/admin/keys/rotate');
        $resRotate->assertRedirect('/admin/keys');
        $this->assertDatabaseHas('keypairs', ['kid' => 2, 'is_active' => true]);

        // 2. Can approve payments
        $resApprove = $this->actingAs($this->superAdmin)
            ->withSession(['2fa_verified' => true])
            ->post("/admin/payments/{$this->samplePayment->id}/approve");
        $resApprove->assertRedirect();
        $this->samplePayment->refresh();
        $this->assertSame('approved', $this->samplePayment->status);

        // 3. Can revoke licenses
        $resRevoke = $this->actingAs($this->superAdmin)
            ->withSession(['2fa_verified' => true])
            ->post("/admin/licenses/{$this->sampleLicense->id}/revoke", [
                'reason' => 'Super admin revoked',
            ]);
        $resRevoke->assertRedirect();
        $this->sampleLicense->refresh();
        $this->assertSame('revoked', $this->sampleLicense->status);
    }
}
