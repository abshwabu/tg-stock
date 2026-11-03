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
        // 1. user_permissions table (overrides per user: allow or deny)
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('permission', 50);
            $table->string('effect', 10); // allow | deny
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->unique(['user_id', 'permission']);
        });

        // 2. user_locations table (location scope per user; owner = all)
        Schema::create('user_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('location_id')->index();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('location_id')
                ->references('id')
                ->on('locations')
                ->onDelete('cascade');

            $table->unique(['user_id', 'location_id']);
        });

        // 3. permission_log table (historical snapshots with effective timestamps)
        Schema::create('permission_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->bigInteger('effective_from_ms');
            $table->text('snapshot'); // JSON: {role, permissions, location_ids}
            $table->uuid('changed_by')->nullable()->index();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->index(['user_id', 'effective_from_ms']);
        });

        // 4. rejected_changes table (stores changes rejected by permission/location checks for owner review)
        Schema::create('rejected_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id')->index();
            $table->uuid('user_id')->index();
            $table->uuid('device_id')->index();
            $table->string('change_id', 50)->index();
            $table->string('entity', 20);
            $table->string('op', 20);
            $table->text('change_data'); // JSON payload
            $table->bigInteger('client_ts');
            $table->string('reason', 50); // forbidden_permission | forbidden_location
            $table->text('details')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | dismissed
            $table->uuid('reviewed_by')->nullable()->index();
            $table->bigInteger('reviewed_at_ms')->nullable();
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

            $table->index(['shop_id', 'status']);
        });

        // 5. Add perm_version to users table
        Schema::table('users', function (Blueprint $table) {
            $table->integer('perm_version')->default(1)->after('role');
        });

        // 6. Add revoked_sync_used to devices table
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('revoked_sync_used')->default(false)->after('revoked_at');
        });

        // 7. Migrate existing 'staff' users to 'salesperson' role
        DB::table('users')->where('role', 'staff')->update(['role' => 'salesperson']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('revoked_sync_used');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('perm_version');
        });

        Schema::dropIfExists('rejected_changes');
        Schema::dropIfExists('permission_log');
        Schema::dropIfExists('user_locations');
        Schema::dropIfExists('user_permissions');
    }
};
