<?php

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
        Schema::create('pairing_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code_hash', 64)->index();
            $table->string('shop_name')->nullable();
            $table->string('device_name');
            $table->string('status', 20)->default('pending'); // pending | confirmed | expired
            $table->string('device_token')->nullable(); // Plain token stored only until retrieved once
            $table->uuid('device_id')->nullable()->index();
            $table->uuid('shop_id')->nullable()->index();
            $table->uuid('user_id')->nullable()->index();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->foreign('shop_id')
                ->references('id')
                ->on('shops')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('device_id')
                ->references('id')
                ->on('devices')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pairing_codes');
    }
};
