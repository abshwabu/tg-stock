<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Admin Users table
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('support'); // 'super_admin' or 'support'
            $table->text('totp_secret')->nullable(); // encrypted Base32 TOTP secret
            $table->timestamp('totp_enabled_at')->nullable();
            $table->json('backup_codes')->nullable(); // hashed emergency backup codes
            $table->rememberToken();
            $table->timestamps();
        });

        // 2. Customers table
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone');
            $table->string('telegram_id')->nullable()->index();
            $table->string('shop_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 3. Plans table (Plans are data, never hardcoded)
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind'); // 'trial', 'monthly', 'yearly', 'lifetime', 'custom'
            $table->unsignedInteger('duration_days'); // e.g. 14, 30, 365, or 0 for lifetime
            $table->unsignedTinyInteger('seats')->default(1);
            $table->unsignedSmallInteger('features')->default(0); // bitmask: sync, bot, roles, multi-location, export
            $table->unsignedTinyInteger('grace_days')->default(7);
            $table->decimal('price_etb', 12, 2)->default(0.00);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // 4. Licenses table
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('type'); // 'device' or 'shop'
            $table->string('bind_code')->index(); // DXXX-XXXX-XXXX-XXX or SXXX-XXXX-XXXX-XXX
            $table->string('bind_bytes_hex', 16)->index(); // 8 bytes = 16 hex chars
            $table->string('status')->default('active')->index(); // 'active', 'suspended', 'revoked', 'cancelled'
            $table->unsignedSmallInteger('current_exp_day')->default(0); // days since epoch; 0xFFFF = lifetime
            $table->timestamps();
        });

        // 5. Payments table
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('license_id')->nullable()->constrained('licenses')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method'); // 'telebirr', 'cbe', 'bank', 'cash', 'other'
            $table->string('reference')->nullable()->index();
            $table->string('proof_path')->nullable(); // uploaded receipt image/screenshot
            $table->string('status')->default('pending')->index(); // 'pending', 'approved', 'rejected'
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 6. License Tokens history table
        Schema::create('license_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->text('token'); // Full formatted token: LIC1-XXXXX-...
            $table->unsignedTinyInteger('kid');
            $table->unsignedSmallInteger('issued_day');
            $table->unsignedSmallInteger('nbf_day');
            $table->unsignedSmallInteger('exp_day');
            $table->unsignedTinyInteger('grace_days');
            $table->unsignedTinyInteger('seats');
            $table->unsignedSmallInteger('features');
            $table->unsignedTinyInteger('max_major');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('raw_payload_hex', 64)->nullable();
            $table->timestamps();
        });

        // 7. Trials tracking (one per telegram_id, one per device bind)
        Schema::create('trials', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_id')->nullable()->unique();
            $table->string('bind_bytes_hex', 16)->unique();
            $table->string('bind_code');
            $table->foreignId('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->timestamps();
        });

        // 8. Reissues history table
        Schema::create('reissues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->string('old_bind_code');
            $table->string('old_bind_bytes_hex', 16);
            $table->string('new_bind_code');
            $table->string('new_bind_bytes_hex', 16);
            $table->text('reason');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 9. Revocations table
        Schema::create('revocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->string('license_id_u64_hex', 16)->unique();
            $table->text('reason');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 10. Keypairs table (Root Ed25519 keys, encrypted at rest)
        Schema::create('keypairs', function (Blueprint $table) {
            $table->unsignedTinyInteger('kid')->primary();
            $table->string('public_key_hex', 64);
            $table->text('encrypted_secret_key'); // JSON containing salt, nonce, ciphertext
            $table->string('fingerprint', 64);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        // 11. Settings table
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // 12. Audit Logs table
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('action'); // 'login', 'token_issued', 'key_rotated', 'payment_approved', etc.
            $table->string('resource_type')->nullable(); // 'License', 'Payment', 'Key', etc.
            $table->string('resource_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        // 13. API Nonces table (Replay attack protection)
        Schema::create('api_nonces', function (Blueprint $table) {
            $table->string('nonce', 64)->primary();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_nonces');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('keypairs');
        Schema::dropIfExists('revocations');
        Schema::dropIfExists('reissues');
        Schema::dropIfExists('trials');
        Schema::dropIfExists('license_tokens');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('users');
    }
};
