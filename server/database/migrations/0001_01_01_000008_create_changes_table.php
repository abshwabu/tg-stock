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
        Schema::create('changes', function (Blueprint $table) {
            $table->bigIncrements('seq'); // High-water mark cursor
            $table->uuid('shop_id')->index();
            $table->uuid('device_id')->nullable()->index();
            $table->string('entity', 30); // movement | product | device
            $table->string('entity_id'); // UUID or identifier of mutated entity
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('shop_id')
                ->references('id')
                ->on('shops')
                ->onDelete('cascade');

            // Composite index for fast incremental sync queries: WHERE shop_id = ? AND seq > ?
            $table->index(['shop_id', 'seq']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('changes');
    }
};
