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
        // Table for deduplicating incoming Telegram update_ids
        Schema::create('bot_updates', function (Blueprint $table) {
            $table->unsignedBigInteger('update_id')->primary();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // Table for user conversation state (30-minute TTL)
        Schema::create('bot_states', function (Blueprint $table) {
            $table->bigInteger('chat_id')->primary();
            $table->string('flow', 60)->index();
            $table->string('step', 60);
            $table->json('data')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_states');
        Schema::dropIfExists('bot_updates');
    }
};
