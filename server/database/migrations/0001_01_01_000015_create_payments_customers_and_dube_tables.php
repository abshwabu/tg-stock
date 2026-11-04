<?php

declare(strict_types=1);

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
        // 1. Create customers table
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id')->index();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->text('note')->nullable();
            $table->bigInteger('created_at_ms');
            $table->bigInteger('updated_at_ms');
            $table->bigInteger('deleted_at_ms')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')
                ->references('id')
                ->on('shops')
                ->onDelete('cascade');

            $table->index(['shop_id', 'phone']);
            $table->index(['shop_id', 'updated_at_ms']);
        });

        // 2. Create sales table
        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id')->index();
            $table->uuid('location_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('device_id')->nullable()->index();
            $table->uuid('customer_id')->nullable()->index();
            $table->integer('total_cents');
            $table->string('due_date', 10)->nullable(); // YYYY-MM-DD
            $table->text('note')->nullable();
            $table->boolean('legacy')->default(false);
            $table->bigInteger('voided_at_ms')->nullable();
            $table->bigInteger('created_at_ms')->index();
            $table->bigInteger('updated_at_ms');
            $table->timestamps();

            $table->foreign('shop_id')
                ->references('id')
                ->on('shops')
                ->onDelete('cascade');

            $table->foreign('location_id')
                ->references('id')
                ->on('locations')
                ->onDelete('restrict');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->foreign('device_id')
                ->references('id')
                ->on('devices')
                ->onDelete('set null');

            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->onDelete('set null');

            $table->index(['shop_id', 'created_at_ms']);
            $table->index(['shop_id', 'customer_id']);
            $table->index(['shop_id', 'location_id']);
        });

        // 3. Add sale_id to movements table
        Schema::table('movements', function (Blueprint $table) {
            $table->uuid('sale_id')->nullable()->after('product_id')->index();

            $table->foreign('sale_id')
                ->references('id')
                ->on('sales')
                ->onDelete('set null');
        });

        // 4. Create payments table
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id')->index();
            $table->uuid('sale_id')->nullable()->index();
            $table->uuid('customer_id')->nullable()->index();
            $table->uuid('location_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('device_id')->nullable()->index();
            $table->string('method', 20); // cash | bank | wallet
            $table->string('provider_key')->nullable();
            $table->integer('amount_cents'); // Signed: negative = reversal
            $table->string('reference')->nullable();
            $table->bigInteger('received_at_ms');
            $table->bigInteger('created_at_ms');
            $table->bigInteger('server_seq')->index();
            $table->timestamps();

            $table->foreign('shop_id')
                ->references('id')
                ->on('shops')
                ->onDelete('cascade');

            $table->foreign('sale_id')
                ->references('id')
                ->on('sales')
                ->onDelete('set null');

            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->onDelete('set null');

            $table->foreign('location_id')
                ->references('id')
                ->on('locations')
                ->onDelete('restrict');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->foreign('device_id')
                ->references('id')
                ->on('devices')
                ->onDelete('set null');

            $table->index(['shop_id', 'server_seq']);
            $table->index(['shop_id', 'sale_id']);
            $table->index(['shop_id', 'customer_id']);
            $table->index(['shop_id', 'created_at_ms']);
        });

        // 5. Create payment_providers table
        Schema::create('payment_providers', function (Blueprint $table) {
            $table->uuid('shop_id');
            $table->string('provider_key');
            $table->string('kind', 20); // bank | wallet
            $table->string('name')->nullable(); // custom provider name
            $table->boolean('enabled')->default(true);
            $table->boolean('favorite')->default(false);
            $table->bigInteger('updated_at_ms');
            $table->timestamps();

            $table->primary(['shop_id', 'provider_key']);

            $table->foreign('shop_id')
                ->references('id')
                ->on('shops')
                ->onDelete('cascade');

            $table->index(['shop_id', 'kind', 'enabled']);
        });

        // 6. Add payment_methods_enabled, dube_default_days, require_payment_reference, and dube_reminder_last_sent_date to shops table
        Schema::table('shops', function (Blueprint $table) {
            $table->json('payment_methods_enabled')->nullable()->after('transfer_requires_receipt');
            $table->integer('dube_default_days')->default(14)->after('payment_methods_enabled');
            $table->boolean('require_payment_reference')->default(false)->after('dube_default_days');
            $table->string('dube_reminder_last_sent_date', 10)->nullable()->after('daily_summary_last_sent_date');
        });

        // Set default payment_methods_enabled for existing shops
        DB::table('shops')->update([
            'payment_methods_enabled' => json_encode(['cash', 'bank', 'wallet', 'dube']),
            'dube_default_days' => 14,
            'require_payment_reference' => false,
        ]);

        // 7. Legacy backfill: group existing sale movements by (user_id, location_id, same minute) into sales rows with legacy=true
        $this->backfillLegacySales();
    }

    /**
     * Backfill legacy sales from unassigned sale movements.
     */
    protected function backfillLegacySales(): void
    {
        $movements = DB::table('movements')
            ->where('type', 'sale')
            ->whereNull('sale_id')
            ->orderBy('created_at_ms', 'asc')
            ->get();

        if ($movements->isEmpty()) {
            return;
        }

        // Group by shop_id, user_id, location_id, and minute bucket
        $groups = [];
        foreach ($movements as $m) {
            $minute = (int) floor($m->created_at_ms / 60000);
            $userKey = $m->user_id ?? 'none';
            $locKey = $m->location_id ?? 'none';
            $groupKey = "{$m->shop_id}_{$userKey}_{$locKey}_{$minute}";
            $groups[$groupKey][] = $m;
        }

        $now = now();
        foreach ($groups as $group) {
            $first = $group[0];
            $totalCents = 0;
            $minCreatedAtMs = $first->created_at_ms;
            $maxCreatedAtMs = $first->created_at_ms;
            $movementIds = [];

            // Fallback location if null
            $locationId = $first->location_id;
            if (empty($locationId)) {
                $defaultLoc = DB::table('locations')->where('shop_id', $first->shop_id)->where('is_default', 1)->first();
                $locationId = $defaultLoc?->id;
            }

            foreach ($group as $item) {
                $movementIds[] = $item->id;
                $lineTotal = (int) round((abs($item->qty_milli) * ($item->unit_price_cents ?? 0)) / 1000);
                $totalCents += $lineTotal;
                if ($item->created_at_ms < $minCreatedAtMs) {
                    $minCreatedAtMs = $item->created_at_ms;
                }
                if ($item->created_at_ms > $maxCreatedAtMs) {
                    $maxCreatedAtMs = $item->created_at_ms;
                }
            }

            if (empty($locationId)) {
                // If still no location, skip or find any location for shop
                $anyLoc = DB::table('locations')->where('shop_id', $first->shop_id)->first();
                $locationId = $anyLoc?->id;
            }

            if (empty($locationId)) {
                continue;
            }

            $saleId = (string) Str::uuid7();

            DB::table('sales')->insert([
                'id' => $saleId,
                'shop_id' => $first->shop_id,
                'location_id' => $locationId,
                'user_id' => $first->user_id,
                'device_id' => $first->device_id,
                'customer_id' => null,
                'total_cents' => $totalCents,
                'due_date' => null,
                'note' => 'Legacy sale backfill',
                'legacy' => true,
                'voided_at_ms' => null,
                'created_at_ms' => $minCreatedAtMs,
                'updated_at_ms' => $maxCreatedAtMs,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('movements')->whereIn('id', $movementIds)->update([
                'sale_id' => $saleId,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['payment_methods_enabled', 'dube_default_days', 'require_payment_reference', 'dube_reminder_last_sent_date']);
        });

        Schema::table('movements', function (Blueprint $table) {
            $table->dropForeign(['sale_id']);
            $table->dropColumn('sale_id');
        });

        Schema::dropIfExists('payment_providers');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('customers');
    }
};
