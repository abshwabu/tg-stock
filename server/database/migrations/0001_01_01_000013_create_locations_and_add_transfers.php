<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create locations table
        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id')->index();
            $table->string('name');
            $table->string('kind', 20)->default('store'); // store | warehouse | other
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->bigInteger('updated_at_ms');
            $table->bigInteger('deleted_at_ms')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')
                ->references('id')
                ->on('shops')
                ->onDelete('cascade');

            $table->index(['shop_id', 'is_active']);
            $table->index(['shop_id', 'is_default']);
        });

        // 2. Add locations_enabled and transfer_requires_receipt to shops table
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('locations_enabled')->default(false)->after('allow_negative_stock');
            $table->boolean('transfer_requires_receipt')->default(false)->after('locations_enabled');
        });

        // 3. Add location_id and transfer_id to movements table
        Schema::table('movements', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->after('product_id')->index();
            $table->uuid('transfer_id')->nullable()->after('location_id')->index();
            $table->index(['shop_id', 'location_id', 'product_id']);
        });

        // 4. Backfill: create one default "Main" location per shop and assign existing movements
        $shops = DB::table('shops')->get();
        $nowMs = (int) (microtime(true) * 1000);

        foreach ($shops as $shop) {
            $existingDefault = DB::table('locations')
                ->where('shop_id', $shop->id)
                ->where('is_default', 1)
                ->first();

            $locationId = $existingDefault ? $existingDefault->id : (string) Str::uuid7();

            if (!$existingDefault) {
                DB::table('locations')->insert([
                    'id' => $locationId,
                    'shop_id' => $shop->id,
                    'name' => 'Main',
                    'kind' => 'store',
                    'is_default' => 1,
                    'is_active' => 1,
                    'updated_at_ms' => $nowMs,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('movements')
                ->where('shop_id', $shop->id)
                ->whereNull('location_id')
                ->update(['location_id' => $locationId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'location_id', 'product_id']);
            $table->dropColumn(['location_id', 'transfer_id']);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['locations_enabled', 'transfer_requires_receipt']);
        });

        Schema::dropIfExists('locations');
    }
};
