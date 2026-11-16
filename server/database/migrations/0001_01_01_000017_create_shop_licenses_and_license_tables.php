<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Shop Licenses Table (one license per shop)
        Schema::create('shop_licenses', function (Blueprint $table) {
            $table->uuid('shop_id')->primary();
            $table->text('token')->nullable();
            $table->string('license_id', 32)->nullable()->index();
            $table->unsignedTinyInteger('plan')->nullable(); // 0 trial, 1 monthly, 2 yearly, 3 lifetime, 4 custom
            $table->unsignedSmallInteger('exp_day')->nullable(); // 0xFFFF = never
            $table->unsignedTinyInteger('grace_days')->default(7);
            $table->unsignedTinyInteger('seats')->default(1);
            $table->unsignedSmallInteger('features')->default(0); // 16-bit bitmask
            $table->string('status', 32)->default('active')->index(); // active, expiring, grace, expired, suspended, revoked
            $table->timestamps();

            $table->foreign('shop_id')
                ->references('id')
                ->on('shops')
                ->onDelete('cascade');
        });

        // 2. License Reminders (idempotency tracking for owner expiry alerts)
        Schema::create('license_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id')->index();
            $table->string('license_id', 32)->index();
            $table->string('milestone', 32); // 7_days, 3_days, 1_day, expiry_day, grace_end
            $table->unsignedSmallInteger('day');
            $table->timestamp('sent_at')->useCurrent();

            $table->unique(['license_id', 'milestone', 'day']);
        });

        // 3. API Nonces (replay protection for incoming webhooks and API calls)
        Schema::create('api_nonces', function (Blueprint $table) {
            $table->string('nonce', 64)->primary();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_nonces');
        Schema::dropIfExists('license_reminders');
        Schema::dropIfExists('shop_licenses');
    }
};
