<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\License;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CrockfordBase32;
use App\Services\KeyManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Customer $customer;
    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        app(KeyManagerService::class)->initializeRootKey(1);

        $this->admin = User::create([
            'name' => 'Audit Admin',
            'email' => 'audit_admin@example.com',
            'password' => bcrypt('password123'),
            'role' => 'super_admin',
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
            'totp_enabled_at' => now(),
        ]);

        $this->customer = Customer::create([
            'name' => 'Audit Customer',
            'phone' => '0911000000',
        ]);

        $this->plan = Plan::create([
            'name' => 'Audit Plan',
            'kind' => 'monthly',
            'duration_days' => 30,
            'seats' => 1,
            'features' => 1,
            'price_etb' => 500,
            'active' => true,
        ]);
    }

    public function test_audit_logs_record_sensitive_operations(): void
    {
        // 1. Token Issuance
        $devCode = CrockfordBase32::makeCode('D', random_bytes(8));
        $this->actingAs($this->admin)
            ->withSession(['2fa_verified' => true])
            ->post('/admin/licenses/generate', [
                'customer_selection' => 'existing',
                'customer_id' => $this->customer->id,
                'plan_id' => $this->plan->id,
                'bind_code' => $devCode,
                'start_date' => '2026-11-16',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'license_issued',
            'resource_type' => 'License',
        ]);

        $license = License::first();
        $this->assertNotNull($license);

        // 2. Renewal
        $this->actingAs($this->admin)
            ->withSession(['2fa_verified' => true])
            ->post("/admin/licenses/{$license->id}/renew");

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'license_renewed',
            'resource_type' => 'License',
        ]);

        // 3. Reissue
        $newDevCode = CrockfordBase32::makeCode('D', random_bytes(8));
        $this->actingAs($this->admin)
            ->withSession(['2fa_verified' => true])
            ->post("/admin/licenses/{$license->id}/reissue", [
                'new_bind_code' => $newDevCode,
                'reason' => 'Lost hardware',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'license_reissued',
            'resource_type' => 'License',
        ]);

        // 4. Revocation
        $this->actingAs($this->admin)
            ->withSession(['2fa_verified' => true])
            ->post("/admin/licenses/{$license->id}/revoke", [
                'reason' => 'Audit test revoke',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'license_revoked',
            'resource_type' => 'License',
        ]);

        // 5. Payment Approval
        $payment = Payment::create([
            'customer_id' => $this->customer->id,
            'amount' => 500,
            'method' => 'telebirr',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->withSession(['2fa_verified' => true])
            ->post("/admin/payments/{$payment->id}/approve");

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_approved',
            'resource_type' => 'Payment',
        ]);

        // 6. Key Rotation
        $this->actingAs($this->admin)
            ->withSession(['2fa_verified' => true])
            ->post('/admin/keys/rotate');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'key_rotated',
            'resource_type' => 'Keypair',
        ]);
    }
}
