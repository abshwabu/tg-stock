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
        Schema::create('movements', function (Blueprint $table) {
            $table->uuid('id')->primary(); // Client-generated UUIDv7
            $table->uuid('shop_id')->index();
            $table->uuid('product_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('device_id')->nullable()->index();
            $table->string('type', 20); // in | sale | adjust
            $table->bigInteger('qty_milli'); // Signed: + for restock/adjust-up, - for sale/adjust-down
            $table->bigInteger('unit_price_cents')->nullable()->default(0);
            $table->text('note')->nullable();
            $table->bigInteger('created_at_ms'); // UTC epoch milliseconds
            $table->bigInteger('server_seq')->index();
            $table->timestamps();

            $table->foreign('shop_id')
                ->references('id')
                ->on('shops')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->foreign('device_id')
                ->references('id')
                ->on('devices')
                ->onDelete('set null');

            // Composite index for fast ledger queries and historical playback
            $table->index(['shop_id', 'product_id', 'created_at_ms']);
            $table->index(['shop_id', 'server_seq']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movements');
    }
};
