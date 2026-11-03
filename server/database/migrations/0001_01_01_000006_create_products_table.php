<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id')->index();
            $table->string('name')->index();
            $table->string('sku')->nullable();
            $table->string('unit', 20)->default('pcs');
            $table->bigInteger('cost_price_cents')->default(0);
            $table->bigInteger('sell_price_cents')->default(0);
            $table->bigInteger('low_stock_at_milli')->nullable();
            $table->boolean('is_active')->default(true);
            $table->bigInteger('updated_at_ms');
            $table->bigInteger('deleted_at_ms')->nullable()->index();
            $table->timestamps();

            $table->foreign('shop_id')
                ->references('id')
                ->on('shops')
                ->onDelete('cascade');
        });

        // Partial unique index: (shop_id, sku) where sku IS NOT NULL
        DB::statement('CREATE UNIQUE INDEX products_shop_sku_unique ON products (shop_id, sku) WHERE sku IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
