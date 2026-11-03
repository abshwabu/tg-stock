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
        // 1. Add settings and summary tracking to shops
        Schema::table('shops', function (Blueprint $table) {
            $table->string('daily_summary_time', 10)->default('20:00')->after('allow_negative_stock');
            $table->string('daily_summary_last_sent_date', 12)->nullable()->after('daily_summary_time');
            $table->boolean('staff_can_adjust')->default(false)->after('daily_summary_last_sent_date');
            $table->boolean('use_ethiopian_calendar')->default(false)->after('staff_can_adjust');
        });

        // 2. Add alert debounce tracking to products
        Schema::table('products', function (Blueprint $table) {
            $table->bigInteger('low_stock_alerted_at_ms')->nullable()->after('deleted_at_ms');
        });

        // 3. Team invites table
        Schema::create('team_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id')->index();
            $table->uuid('inviter_user_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->string('role', 20)->default('staff');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->bigInteger('used_by_telegram_id')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
            $table->foreign('inviter_user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // 4. Activity logs table
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('action', 60)->index();
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('team_invites');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('low_stock_alerted_at_ms');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'daily_summary_time',
                'daily_summary_last_sent_date',
                'staff_can_adjust',
                'use_ethiopian_calendar',
            ]);
        });
    }
};
