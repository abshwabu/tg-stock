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
        // 1. Add non-stockable fields to products table
        Schema::table('products', function (Blueprint $table) {
            $table->string('kind', 20)->default('stockable')->after('unit');
            $table->boolean('ask_price')->default(false)->after('sell_price_cents');
            $table->json('materials')->nullable()->after('ask_price');
            $table->bigInteger('kind_changed_at_ms')->nullable()->after('materials');

            $table->index(['shop_id', 'kind']);
        });

        // 2. Add tracks_stock and source_movement_id to movements table
        Schema::table('movements', function (Blueprint $table) {
            $table->boolean('tracks_stock')->default(true)->after('type');
            $table->uuid('source_movement_id')->nullable()->after('sale_id')->index();

            $table->index(['shop_id', 'product_id', 'tracks_stock']);
        });

        // 3. Add block_sale_when_material_short to shops table
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('block_sale_when_material_short')->default(false)->after('require_payment_reference');
        });

        // 4. Backfill existing records to ensure consistent defaults
        DB::table('products')->whereNull('kind')->update([
            'kind' => 'stockable',
            'ask_price' => false,
        ]);

        DB::table('movements')->whereNull('tracks_stock')->update([
            'tracks_stock' => true,
        ]);

        DB::table('shops')->whereNull('block_sale_when_material_short')->update([
            'block_sale_when_material_short' => false,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('block_sale_when_material_short');
        });

        Schema::table('movements', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'product_id', 'tracks_stock']);
            $table->dropColumn(['tracks_stock', 'source_movement_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'kind']);
            $table->dropColumn(['kind', 'ask_price', 'materials', 'kind_changed_at_ms']);
        });
    }
};
