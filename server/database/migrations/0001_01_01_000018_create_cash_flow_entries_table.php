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
        Schema::create('cash_flow_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('device_id')->nullable()->index();
            $table->string('type', 16)->index(); // 'income' or 'expense'
            $table->integer('amount_cents');
            $table->string('category', 64)->index();
            $table->string('payment_method', 32)->default('cash');
            $table->text('note')->nullable();
            $table->bigInteger('created_at_ms')->index();
            $table->bigInteger('updated_at_ms')->nullable();
            $table->bigInteger('deleted_at_ms')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')
                ->references('id')
                ->on('shops')
                ->onDelete('cascade');

            $table->index(['shop_id', 'type', 'created_at_ms']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_flow_entries');
    }
};
