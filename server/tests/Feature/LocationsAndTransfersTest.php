<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Context\CurrentShopContext;
use App\Exceptions\CrossShopViolationException;
use App\Exceptions\InsufficientStockException;
use App\Models\Device;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class LocationsAndTransfersTest extends TestCase
{
    use RefreshDatabase;

    protected LedgerService $ledger;
    protected SyncService $sync;
    protected Shop $shopA;
    protected Shop $shopB;
    protected Location $mainLocationA;
    protected Location $warehouseA;
    protected Location $mainLocationB;
    protected Product $productA;
    protected Product $productB;
    protected User $ownerA;
    protected User $staffA;
    protected Device $ownerDeviceA;
    protected Device $staffDeviceA;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['ok' => true], 200)]);
        CurrentShopContext::clear();

        $this->ledger = new LedgerService();
        $this->sync = new SyncService($this->ledger);

        // Create Shop A
        $this->shopA = Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Bole Grocery',
            'currency' => 'ETB',
            'language' => 'am',
            'locations_enabled' => true,
            'transfer_requires_receipt' => false,
            'allow_negative_stock' => false,
        ]);
        $this->mainLocationA = $this->shopA->getDefaultLocation();

        // Create secondary Warehouse location in Shop A
        CurrentShopContext::runWith($this->shopA, function (): void {
            $this->warehouseA = Location::create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shopA->id,
                'name' => 'Kaliti Warehouse',
                'kind' => 'warehouse',
                'is_default' => false,
                'is_active' => true,
                'updated_at_ms' => (int) (microtime(true) * 1000),
            ]);
        });

        // Create Shop B
        $this->shopB = Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Hawassa Mart',
            'currency' => 'ETB',
            'language' => 'am',
            'locations_enabled' => false,
            'allow_negative_stock' => false,
        ]);
        $this->mainLocationB = $this->shopB->getDefaultLocation();

        // Create Users & Devices
        CurrentShopContext::runWith($this->shopA, function (): void {
            $this->ownerA = User::create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shopA->id,
                'telegram_id' => 111111,
                'name' => 'Almaz (Owner)',
                'role' => 'owner',
            ]);
            $this->ownerDeviceA = Device::create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shopA->id,
                'user_id' => $this->ownerA->id,
                'name' => "Almaz's Phone",
                'token_hash' => hash('sha256', 'owner-token'),
            ]);

            $this->staffA = User::create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shopA->id,
                'telegram_id' => 222222,
                'name' => 'Dawit (Staff)',
                'role' => 'staff',
            ]);
            $this->staffDeviceA = Device::create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shopA->id,
                'user_id' => $this->staffA->id,
                'name' => "Dawit's Tablet",
                'token_hash' => hash('sha256', 'staff-token'),
            ]);
        });

        // Create Products
        CurrentShopContext::runWith($this->shopA, function (): void {
            $this->productA = Product::create([
                'id' => (string) Str::uuid7(),
                'name' => 'Teff Maganaja',
                'sku' => 'TEFF-01',
                'unit' => 'kg',
                'cost_price_cents' => 9000,
                'sell_price_cents' => 11000,
            ]);
        });

        CurrentShopContext::runWith($this->shopB, function (): void {
            $this->productB = Product::create([
                'id' => (string) Str::uuid7(),
                'name' => 'Sugar',
                'sku' => 'SUG-01',
                'unit' => 'kg',
                'cost_price_cents' => 4000,
                'sell_price_cents' => 5000,
            ]);
        });

        CurrentShopContext::clear();
    }

    protected function tearDown(): void
    {
        CurrentShopContext::clear();
        parent::tearDown();
    }

    public function test_instant_transfer_records_out_and_in_in_single_transaction(): void
    {
        // 1. Initial stock in Warehouse: 50.000 kg
        $this->ledger->record(new Movement([
            'shop_id' => $this->shopA->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->warehouseA->id,
            'type' => 'in',
            'qty_milli' => 50000,
            'unit_price_cents' => 9000,
        ]));

        $this->assertSame(50000, $this->ledger->stockOf($this->productA, $this->warehouseA));
        $this->assertSame(0, $this->ledger->stockOf($this->productA, $this->mainLocationA));
        $this->assertSame(50000, $this->ledger->stockOf($this->productA));

        // 2. Perform instant transfer: 20.000 kg from Warehouse to Main store
        $res = $this->ledger->transfer(
            product: $this->productA,
            fromLocation: $this->warehouseA,
            toLocation: $this->mainLocationA,
            qty: '20.000',
            user: $this->ownerA,
            note: 'Morning store restock'
        );

        $this->assertSame('completed', $res['status']);
        $this->assertNotNull($res['transfer_id']);
        $this->assertNotNull($res['out']);
        $this->assertNotNull($res['in']);
        $this->assertSame($res['out']->transfer_id, $res['in']->transfer_id);

        // 3. Verify stock balances
        $this->assertSame(30000, $this->ledger->stockOf($this->productA, $this->warehouseA));
        $this->assertSame(20000, $this->ledger->stockOf($this->productA, $this->mainLocationA));
        $this->assertSame(50000, $this->ledger->stockOf($this->productA), 'Total shop stock must be strictly conserved');

        // 4. Verify stockByLocation query
        $balances = $this->ledger->stockByLocation($this->productA, $this->shopA->id);
        $this->assertCount(2, $balances);
        $stockMap = $balances->pluck('stock_milli', 'id')->toArray();
        $this->assertSame(20000, $stockMap[$this->mainLocationA->id]);
        $this->assertSame(30000, $stockMap[$this->warehouseA->id]);

        // 5. inTransit should be empty
        $inTransit = $this->ledger->inTransit($this->shopA);
        $this->assertCount(0, $inTransit);
    }

    public function test_two_step_transfer_requires_receipt_and_tracks_in_transit(): void
    {
        // Enable transfer_requires_receipt on Shop A
        $this->shopA->transfer_requires_receipt = true;
        $this->shopA->save();

        // 1. Initial stock in Warehouse: 100.000 kg
        $this->ledger->record(new Movement([
            'shop_id' => $this->shopA->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->warehouseA->id,
            'type' => 'in',
            'qty_milli' => 100000,
        ]));

        // 2. Initiate transfer: 35.000 kg
        $res = $this->ledger->transfer(
            product: $this->productA,
            fromLocation: $this->warehouseA,
            toLocation: $this->mainLocationA,
            qty: 35000,
            user: $this->ownerA
        );

        $this->assertSame('in_transit', $res['status']);
        $this->assertNotNull($res['transfer_id']);
        $this->assertNotNull($res['out']);
        $this->assertNull($res['in']);

        // Stock at warehouse decremented; stock at store NOT yet credited
        $this->assertSame(65000, $this->ledger->stockOf($this->productA, $this->warehouseA));
        $this->assertSame(0, $this->ledger->stockOf($this->productA, $this->mainLocationA));

        // 3. Query inTransit
        $inTransit = $this->ledger->inTransit($this->shopA);
        $this->assertCount(1, $inTransit);
        $this->assertSame($res['transfer_id'], $inTransit[0]['transfer_id']);
        $this->assertSame(35000, $inTransit[0]['qty_milli']);
        $this->assertSame('35.000', $inTransit[0]['qty']);
        $this->assertSame($this->warehouseA->id, $inTransit[0]['from_location_id']);
        $this->assertSame($this->mainLocationA->id, $inTransit[0]['to_location_id']);

        // 4. Receive transfer at store
        $received = $this->ledger->receive(
            transferId: $res['transfer_id'],
            qtyReceived: '35.000',
            user: $this->staffA
        );

        $this->assertSame('transfer_in', $received->type);
        $this->assertSame(35000, $received->qty_milli);
        $this->assertSame($this->mainLocationA->id, $received->location_id);

        // Stock now credited to destination
        $this->assertSame(35000, $this->ledger->stockOf($this->productA, $this->mainLocationA));
        $this->assertSame(100000, $this->ledger->stockOf($this->productA));

        // In-transit list is now cleared
        $this->assertCount(0, $this->ledger->inTransit($this->shopA));
    }

    public function test_per_location_negative_stock_rule(): void
    {
        // Warehouse has 10 units, Store has 20 units. Total = 30 units.
        $this->ledger->record(new Movement([
            'shop_id' => $this->shopA->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->warehouseA->id,
            'type' => 'in',
            'qty_milli' => 10000,
        ]));

        $this->ledger->record(new Movement([
            'shop_id' => $this->shopA->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->mainLocationA->id,
            'type' => 'in',
            'qty_milli' => 20000,
        ]));

        $this->assertSame(30000, $this->ledger->stockOf($this->productA));

        // Attempting to transfer 15 units OUT of Warehouse must fail because Warehouse only has 10,
        // even though the shop as a whole has 30!
        $this->expectException(InsufficientStockException::class);
        $this->expectExceptionMessage('Insufficient stock at location');

        $this->ledger->transfer(
            product: $this->productA,
            fromLocation: $this->warehouseA,
            toLocation: $this->mainLocationA,
            qty: '15.000'
        );
    }

    public function test_negative_stock_allowed_when_shop_setting_enabled(): void
    {
        $this->shopA->allow_negative_stock = true;
        $this->shopA->save();

        // Warehouse has 5 units
        $this->ledger->record(new Movement([
            'shop_id' => $this->shopA->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->warehouseA->id,
            'type' => 'in',
            'qty_milli' => 5000,
        ]));

        // Transferring 12 units out of Warehouse succeeds because negative stock is allowed
        $res = $this->ledger->transfer(
            product: $this->productA,
            fromLocation: $this->warehouseA,
            toLocation: $this->mainLocationA,
            qty: '12.000'
        );

        $this->assertSame('completed', $res['status']);
        $this->assertSame(-7000, $this->ledger->stockOf($this->productA, $this->warehouseA));
        $this->assertSame(12000, $this->ledger->stockOf($this->productA, $this->mainLocationA));
    }

    public function test_replayed_transfer_movements_are_idempotent(): void
    {
        $this->ledger->record(new Movement([
            'shop_id' => $this->shopA->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->warehouseA->id,
            'type' => 'in',
            'qty_milli' => 50000,
        ]));

        $transferId = (string) Str::uuid7();

        // Transfer 10.000 kg
        $this->shopA->transfer_requires_receipt = true;
        $this->shopA->save();

        $res = $this->ledger->transfer(
            product: $this->productA,
            fromLocation: $this->warehouseA,
            toLocation: $this->mainLocationA,
            qty: 10000,
            transferId: $transferId
        );

        $movementId = (string) Str::uuid7();

        // First receive
        $in1 = $this->ledger->receive(
            transferId: $transferId,
            qtyReceived: 10000,
            movementId: $movementId
        );

        // Replay receive with same movement ID
        $in2 = $this->ledger->receive(
            transferId: $transferId,
            qtyReceived: 10000,
            movementId: $movementId
        );

        $this->assertSame($in1->id, $in2->id);
        $this->assertSame(10000, $this->ledger->stockOf($this->productA, $this->mainLocationA), 'Stock must not be double counted on replay');
    }

    public function test_discrepancy_detection_when_received_qty_differs_from_sent_qty(): void
    {
        $this->shopA->transfer_requires_receipt = true;
        $this->shopA->save();

        $this->ledger->record(new Movement([
            'shop_id' => $this->shopA->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->warehouseA->id,
            'type' => 'in',
            'qty_milli' => 40000,
        ]));

        // Dispatch 20.000 kg from Warehouse
        $res = $this->ledger->transfer(
            product: $this->productA,
            fromLocation: $this->warehouseA,
            toLocation: $this->mainLocationA,
            qty: '20.000'
        );

        // Receive only 18.000 kg at Store (2.000 kg damaged/missing in transit)
        $this->ledger->receive(
            transferId: $res['transfer_id'],
            qtyReceived: '18.000'
        );

        // Verify ledger recorded exact quantities without auto-adjusting
        $this->assertSame(20000, $this->ledger->stockOf($this->productA, $this->warehouseA));
        $this->assertSame(18000, $this->ledger->stockOf($this->productA, $this->mainLocationA));

        // Query discrepancies
        $discrepancies = $this->ledger->discrepancies($this->shopA);
        $this->assertCount(1, $discrepancies);
        $d = $discrepancies[0];
        $this->assertSame($res['transfer_id'], $d['transfer_id']);
        $this->assertSame(20000, $d['sent_qty_milli']);
        $this->assertSame(18000, $d['received_qty_milli']);
        $this->assertSame(-2000, $d['difference_milli']);
        $this->assertSame('-2.000', $d['difference_qty']);
        $this->assertTrue($d['is_discrepancy']);
    }

    public function test_cross_shop_isolation_prohibits_transfers_and_operations(): void
    {
        // 1. Cannot transfer between locations of different shops
        $this->expectException(CrossShopViolationException::class);
        $this->ledger->transfer(
            product: $this->productA,
            fromLocation: $this->warehouseA,
            toLocation: $this->mainLocationB,
            qty: 5000
        );
    }

    public function test_cannot_record_movement_for_location_belonging_to_another_shop(): void
    {
        $this->expectException(CrossShopViolationException::class);

        $this->ledger->record(new Movement([
            'shop_id' => $this->shopA->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->mainLocationB->id, // Location from Shop B!
            'type' => 'in',
            'qty_milli' => 10000,
        ]));
    }

    public function test_location_archival_and_deletion_constraints(): void
    {
        // 1. Default location cannot be archived or deleted
        $reason = null;
        $this->assertFalse($this->mainLocationA->canArchive($reason));
        $this->assertStringContainsString('Default location cannot be archived', $reason);

        // 2. Put stock in warehouse
        $this->ledger->record(new Movement([
            'shop_id' => $this->shopA->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->warehouseA->id,
            'type' => 'in',
            'qty_milli' => 15000,
        ]));

        // Warehouse holds stock: cannot be archived
        $this->assertTrue($this->warehouseA->hasStock());
        $this->assertFalse($this->warehouseA->canArchive($reason));
        $this->assertStringContainsString('Location still holds stock', $reason);

        // Transfer remaining stock out of warehouse
        $this->ledger->transfer(
            product: $this->productA,
            fromLocation: $this->warehouseA,
            toLocation: $this->mainLocationA,
            qty: '15.000'
        );

        $this->assertSame(0, $this->ledger->stockOf($this->productA, $this->warehouseA));
        $this->assertFalse($this->warehouseA->hasStock());

        // Now warehouse has zero stock and CAN be archived
        $this->assertTrue($this->warehouseA->canArchive($reason));
    }

    public function test_only_one_default_location_per_shop(): void
    {
        $this->assertTrue($this->mainLocationA->fresh()->is_default);
        $this->assertFalse($this->warehouseA->fresh()->is_default);

        // Set warehouse as default via Sync
        $locId = $this->warehouseA->id;
        $payload = [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $locId,
                    'entity' => 'location',
                    'op' => 'upsert',
                    'data' => [
                        'id' => $locId,
                        'name' => 'Kaliti Central Warehouse',
                        'is_default' => true,
                    ],
                ],
            ],
        ];

        $res = $this->sync->sync($this->ownerDeviceA, $payload, 500);
        $this->assertCount(1, $res['accepted']);

        // Warehouse is now default; original main location is no longer default
        $this->assertTrue($this->warehouseA->fresh()->is_default);
        $this->assertFalse($this->mainLocationA->fresh()->is_default);
    }

    public function test_sync_wire_location_owner_only_and_movements_backward_compatibility(): void
    {
        // 1. Staff device attempts to create location -> REJECTED
        $newLocId = (string) Str::uuid7();
        $staffPayload = [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $newLocId,
                    'entity' => 'location',
                    'op' => 'upsert',
                    'data' => [
                        'id' => $newLocId,
                        'name' => 'Staff Secret Store',
                    ],
                ],
            ],
        ];

        $staffRes = $this->sync->sync($this->staffDeviceA, $staffPayload, 500);
        $this->assertCount(1, $staffRes['rejected']);
        $this->assertContains($staffRes['rejected'][0]['reason'], ['Only shop owners can manage locations.', 'forbidden_permission']);

        // 2. Owner device creates location -> ACCEPTED
        $ownerRes = $this->sync->sync($this->ownerDeviceA, $staffPayload, 500);
        $this->assertCount(1, $ownerRes['accepted']);
        $this->assertDatabaseHas('locations', ['id' => $newLocId, 'name' => 'Staff Secret Store']);

        // 3. Movement without location_id from older client version -> Assigned to default location
        $movementId = (string) Str::uuid7();
        $legacyMovementPayload = [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $movementId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'data' => [
                        'id' => $movementId,
                        'product_id' => $this->productA->id,
                        'type' => 'in',
                        'qty' => '42.000',
                    ],
                ],
            ],
        ];

        $legacyRes = $this->sync->sync($this->ownerDeviceA, $legacyMovementPayload, 500);
        $this->assertCount(1, $legacyRes['accepted']);

        $recordedMovement = Movement::withoutGlobalScope(\App\Scopes\ShopScope::class)->find($movementId);
        $this->assertNotNull($recordedMovement);
        $this->assertSame($this->mainLocationA->id, $recordedMovement->location_id, 'Omitted location_id must resolve to default location');
    }

    public function test_backfill_migration_logic_handles_existing_unassigned_data(): void
    {
        // Create an unmigrated raw shop directly in DB without locations
        $shopId = (string) Str::uuid7();
        \Illuminate\Support\Facades\DB::table('shops')->insert([
            'id' => $shopId,
            'name' => 'Legacy Migrated Shop',
            'currency' => 'ETB',
            'language' => 'am',
            'low_stock_default_milli' => 5000,
            'allow_negative_stock' => 0,
            'locations_enabled' => 0,
            'transfer_requires_receipt' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = (string) Str::uuid7();
        \Illuminate\Support\Facades\DB::table('products')->insert([
            'id' => $productId,
            'shop_id' => $shopId,
            'name' => 'Legacy Item',
            'unit' => 'pcs',
            'cost_price_cents' => 100,
            'sell_price_cents' => 150,
            'is_active' => 1,
            'updated_at_ms' => (int) (microtime(true) * 1000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $movementId = (string) Str::uuid7();
        \Illuminate\Support\Facades\DB::table('movements')->insert([
            'id' => $movementId,
            'shop_id' => $shopId,
            'product_id' => $productId,
            'location_id' => null, // Legacy unassigned
            'type' => 'in',
            'qty_milli' => 77000,
            'unit_price_cents' => 100,
            'created_at_ms' => (int) (microtime(true) * 1000),
            'server_seq' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run backfill routine (same logic as in migration up())
        $nowMs = (int) (microtime(true) * 1000);
        $defaultLocId = (string) Str::uuid7();
        \Illuminate\Support\Facades\DB::table('locations')->insert([
            'id' => $defaultLocId,
            'shop_id' => $shopId,
            'name' => 'Main',
            'kind' => 'store',
            'is_default' => 1,
            'is_active' => 1,
            'updated_at_ms' => $nowMs,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('movements')
            ->where('shop_id', $shopId)
            ->whereNull('location_id')
            ->update(['location_id' => $defaultLocId]);

        // Verify movement was assigned
        $updated = \Illuminate\Support\Facades\DB::table('movements')->where('id', $movementId)->first();
        $this->assertSame($defaultLocId, $updated->location_id);

        // Verify stockOf calculates correctly using default location
        $shop = Shop::find($shopId);
        $this->assertFalse($shop->locations_enabled);
        $this->assertSame(77000, $this->ledger->stockOf($productId, null, $shopId));
    }
}
