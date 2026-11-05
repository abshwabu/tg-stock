<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Device;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\LedgerService;
use App\Services\PairingService;
use App\Services\PaymentReportService;
use App\Services\PermissionService;
use App\Services\SalesService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class NonStockableItemsTest extends TestCase
{
    use RefreshDatabase;

    protected PairingService $pairing;
    protected LedgerService $ledger;
    protected SalesService $salesService;
    protected PaymentReportService $reportService;
    protected PermissionService $permissionService;
    protected SyncService $syncService;

    protected Shop $shop;
    protected User $owner;
    protected Device $device;
    protected string $token;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pairing = app(PairingService::class);
        $this->ledger = app(LedgerService::class);
        $this->salesService = app(SalesService::class);
        $this->reportService = app(PaymentReportService::class);
        $this->permissionService = app(PermissionService::class);
        $this->syncService = app(SyncService::class);

        // Setup Shop with paired owner device
        $pair = $this->pairing->startPairing('Bole Cafe & Lounge', 'Owner POS');
        $confirm = $this->pairing->confirmPairing($pair['code'], 10001, 'Owner User');
        $this->token = $this->pairing->getPairingStatus($pair['code'])['device_token'];

        $this->shop = $confirm['shop'];
        $this->device = $confirm['device'];
        $this->owner = $confirm['user'];
        $this->location = $this->shop->getDefaultLocation();
    }

    /**
     * 1. Service sale leaves stock untouched (null) but counts as revenue.
     */
    public function test_service_sale_leaves_stock_untouched_and_counts_as_revenue(): void
    {
        // Create service product
        $service = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Haircut & Styling',
            'unit' => 'serv',
            'kind' => 'service',
            'sell_price_cents' => 20000, // 200 ETB
            'cost_price_cents' => 3000,  // 30 ETB
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        // Stock for service must be null
        $this->assertNull($this->ledger->stockOf($service));
        $this->assertNull($this->ledger->stockOf($service, $this->location));

        // Create sale header
        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'user_id' => $this->owner->id,
            'total_cents' => 40000, // 2 haircuts
            'created_at_ms' => (int) (microtime(true) * 1000),
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        // Add line for 2 haircuts (2.000 units = 2000 milli)
        $res = $this->salesService->addLine(
            sale: $sale,
            product: $service,
            qty: '2.000',
            unitPriceCents: 20000,
            location: $this->location,
            user: $this->owner
        );

        $saleMov = $res['sale_movement'];
        $this->assertFalse($saleMov->tracks_stock);
        $this->assertSame(-2000, $saleMov->qty_milli);
        $this->assertSame(20000, $saleMov->unit_price_cents);
        $this->assertEmpty($res['consume_movements']);

        // Stock remains null
        $this->assertNull($this->ledger->stockOf($service));

        // Check revenue report includes it
        $now = (int) (microtime(true) * 1000);
        $breakdown = $this->reportService->salesByKind($this->shop, [$now - 3600000, $now + 3600000]);

        $this->assertSame(40000, $breakdown['total_revenue_cents']);
        $this->assertSame(40000, $breakdown['by_kind']['service']['revenue_cents']);
        $this->assertSame(2000, $breakdown['by_kind']['service']['units_sold_milli']);
        $this->assertSame('2.000', $breakdown['by_kind']['service']['units_sold']);
        $this->assertSame(1, $breakdown['by_kind']['service']['sales_count']);
    }

    /**
     * 2. Consumable deduction math and rounding.
     */
    public function test_consumable_deduction_math_and_half_up_rounding(): void
    {
        // Raw material 1: Coffee Beans (stockable)
        $coffee = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Coffee Beans',
            'unit' => 'kg',
            'kind' => 'stockable',
            'cost_price_cents' => 50000, // 500 ETB / kg
            'sell_price_cents' => 80000,
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        // Restock 10.000 kg of coffee beans
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $coffee->id,
            'location_id' => $this->location->id,
            'type' => 'in',
            'qty_milli' => 10000, // 10.000 kg
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]));

        // Raw material 2: Milk (stockable)
        $milk = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Milk',
            'unit' => 'L',
            'kind' => 'stockable',
            'cost_price_cents' => 8000,
            'sell_price_cents' => 12000,
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        // Restock 5.000 L of milk
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $milk->id,
            'location_id' => $this->location->id,
            'type' => 'in',
            'qty_milli' => 5000,
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]));

        // Consumable item: Macchiato
        // Recipe: 18 milli (0.018 kg) coffee + 65 milli (0.065 L) milk per cup
        $macchiato = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Macchiato',
            'unit' => 'cup',
            'kind' => 'consumable',
            'sell_price_cents' => 5000, // 50 ETB
            'materials' => [
                ['product_id' => $coffee->id, 'qty_milli' => 18],
                ['product_id' => $milk->id, 'qty_milli' => 65],
            ],
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        $this->assertNull($this->ledger->stockOf($macchiato));

        // Sell 7 cups (7000 milli)
        // Coffee deduction: round((18 * 7000) / 1000) = 126 milli
        // Milk deduction: round((65 * 7000) / 1000) = 455 milli
        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'user_id' => $this->owner->id,
            'total_cents' => 35000,
            'created_at_ms' => (int) (microtime(true) * 1000),
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        $res = $this->salesService->addLine(
            sale: $sale,
            product: $macchiato,
            qty: '7.000',
            unitPriceCents: 5000,
            location: $this->location,
            user: $this->owner
        );

        $saleMov = $res['sale_movement'];
        $this->assertFalse($saleMov->tracks_stock);
        $this->assertSame(-7000, $saleMov->qty_milli);

        $this->assertCount(2, $res['consume_movements']);
        $coffeeConsume = collect($res['consume_movements'])->firstWhere('product_id', $coffee->id);
        $milkConsume = collect($res['consume_movements'])->firstWhere('product_id', $milk->id);

        $this->assertNotNull($coffeeConsume);
        $this->assertTrue($coffeeConsume->tracks_stock);
        $this->assertSame('consume', $coffeeConsume->type);
        $this->assertSame(-126, $coffeeConsume->qty_milli);
        $this->assertSame($saleMov->id, $coffeeConsume->source_movement_id);
        $this->assertSame(0, $coffeeConsume->unit_price_cents);

        $this->assertNotNull($milkConsume);
        $this->assertTrue($milkConsume->tracks_stock);
        $this->assertSame('consume', $milkConsume->type);
        $this->assertSame(-455, $milkConsume->qty_milli);
        $this->assertSame($saleMov->id, $milkConsume->source_movement_id);
        $this->assertSame(0, $milkConsume->unit_price_cents);

        // Verify remaining stocks of raw materials
        $this->assertSame(10000 - 126, $this->ledger->stockOf($coffee));
        $this->assertSame(5000 - 455, $this->ledger->stockOf($milk));

        // Test half-up rounding: material with 15 milli sold with 333 milli
        // (15 * 333) / 1000 = 4.995 -> rounds to 5 milli
        $roundingMat = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Spice Mix',
            'unit' => 'g',
            'kind' => 'stockable',
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $roundingMat->id,
            'location_id' => $this->location->id,
            'type' => 'in',
            'qty_milli' => 1000,
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]));

        $roundingConsumable = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Spiced Dish',
            'unit' => 'portion',
            'kind' => 'consumable',
            'materials' => [
                ['product_id' => $roundingMat->id, 'qty_milli' => 15],
            ],
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        $resRounding = $this->salesService->addLine(
            sale: $sale,
            product: $roundingConsumable,
            qty: '0.333',
            unitPriceCents: 1000,
            location: $this->location
        );

        $this->assertSame(-5, $resRounding['consume_movements'][0]->qty_milli);
        $this->assertSame(995, $this->ledger->stockOf($roundingMat));
    }

    /**
     * 3. Shortage warning vs block setting.
     */
    public function test_shortage_warning_vs_block_sale_setting(): void
    {
        $rawSugar = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Sugar',
            'unit' => 'g',
            'kind' => 'stockable',
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        // Stock 500 milli
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $rawSugar->id,
            'location_id' => $this->location->id,
            'type' => 'in',
            'qty_milli' => 500,
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]));

        // Consumable uses 300 milli sugar per portion
        $sweetTea = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Sweet Tea',
            'unit' => 'cup',
            'kind' => 'consumable',
            'materials' => [
                ['product_id' => $rawSugar->id, 'qty_milli' => 300],
            ],
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'user_id' => $this->owner->id,
            'total_cents' => 4000,
            'created_at_ms' => (int) (microtime(true) * 1000),
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        // Case A: block_sale_when_material_short = false (default)
        // Selling 2 portions requires 600 milli, available is 500 milli
        $this->shop->block_sale_when_material_short = false;
        $this->shop->save();

        $res = $this->salesService->addLine(
            sale: $sale,
            product: $sweetTea,
            qty: '2.000',
            unitPriceCents: 2000,
            location: $this->location
        );

        // Warning returned
        $this->assertNotEmpty($res['warnings']);
        $this->assertStringContainsString('shortage', strtolower($res['warnings'][0]));
        // Stock allowed to drop to -100
        $this->assertSame(-100, $this->ledger->stockOf($rawSugar));

        // Case B: block_sale_when_material_short = true
        $this->shop->block_sale_when_material_short = true;
        $this->shop->save();

        // Selling 1 more portion requires 300 milli, available is -100 milli
        $this->expectException(InsufficientStockException::class);
        $this->salesService->addLine(
            sale: $sale,
            product: $sweetTea,
            qty: '1.000',
            unitPriceCents: 2000,
            location: $this->location
        );
    }

    /**
     * 4. Rejected stock operations on non-stockable items.
     */
    public function test_stock_operations_on_non_stockable_items_are_rejected(): void
    {
        $service = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Consulting Hour',
            'unit' => 'hr',
            'kind' => 'service',
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        // 1. Stock-in rejected
        $inMov = new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $service->id,
            'location_id' => $this->location->id,
            'type' => 'in',
            'qty_milli' => 5000,
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]);

        try {
            $this->ledger->record($inMov);
            $this->fail('Stock in on service should have thrown InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('not_stockable', $e->getMessage());
        }

        // 2. Adjust rejected
        $adjMov = new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $service->id,
            'location_id' => $this->location->id,
            'type' => 'adjust',
            'qty_milli' => 1000,
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]);

        try {
            $this->ledger->record($adjMov);
            $this->fail('Adjust on service should have thrown InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('not_stockable', $e->getMessage());
        }

        // 3. Transfer rejected
        $loc2 = Location::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Branch 2',
            'kind' => 'store',
            'is_default' => false,
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        try {
            $this->ledger->transfer(
                product: $service,
                fromLocation: $this->location,
                toLocation: $loc2,
                qty: '1.000',
                user: $this->owner
            );
            $this->fail('Transfer on service should have thrown InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('not_stockable', $e->getMessage());
        }
    }

    /**
     * 5. Kind-change rules:
     * - stockable -> service/consumable only when stock is zero
     * - service/consumable -> stockable allowed and starts at zero
     * - kind_changed_at_ms set on every change
     */
    public function test_kind_change_rules(): void
    {
        $prod = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Convertible Item',
            'unit' => 'pcs',
            'kind' => 'stockable',
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        // Restock to 5000 milli
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $prod->id,
            'location_id' => $this->location->id,
            'type' => 'in',
            'qty_milli' => 5000,
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]));

        $this->assertSame(5000, $this->ledger->stockOf($prod));

        // Attempting to change to service when stock is non-zero must fail
        try {
            $this->salesService->changeKind($prod, 'service');
            $this->fail('Should have failed with stock_not_zero');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('stock_not_zero', $e->getMessage());
        }

        // Adjust stock to 0
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $prod->id,
            'location_id' => $this->location->id,
            'type' => 'adjust',
            'qty_milli' => -5000,
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]));

        $this->assertSame(0, $this->ledger->stockOf($prod));

        // Now change to service succeeds
        $t1 = 1700000000000;
        $this->salesService->changeKind($prod, 'service', $t1);
        $this->assertSame('service', $prod->kind);
        $this->assertSame($t1, $prod->kind_changed_at_ms);
        $this->assertNull($this->ledger->stockOf($prod));

        // Changing back from service to stockable is allowed and starts at 0 stock
        $t2 = 1700000050000;
        $this->salesService->changeKind($prod, 'stockable', $t2);
        $this->assertSame('stockable', $prod->kind);
        $this->assertSame($t2, $prod->kind_changed_at_ms);
        $this->assertSame(0, $this->ledger->stockOf($prod));
    }

    /**
     * 6. Delete and kind-change protection for materials.
     */
    public function test_delete_and_kind_change_protection_for_materials(): void
    {
        $rawFlour = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Wheat Flour',
            'unit' => 'kg',
            'kind' => 'stockable',
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        $bread = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Fresh Bread',
            'unit' => 'loaf',
            'kind' => 'consumable',
            'materials' => [
                ['product_id' => $rawFlour->id, 'qty_milli' => 500],
            ],
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        // Attempting to change Flour to service must fail with used_as_material
        try {
            $this->salesService->changeKind($rawFlour, 'service');
            $this->fail('Expected used_as_material exception');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('used_as_material', $e->getMessage());
        }

        // Attempting to delete Flour via sync must fail with used_as_material
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/sync', [
                'changes' => [
                    [
                        'id' => $rawFlour->id,
                        'entity' => 'product',
                        'op' => 'delete',
                        'data' => ['id' => $rawFlour->id],
                        'client_ts' => (int) (microtime(true) * 1000),
                    ],
                ],
                'cursor' => 0,
            ]);

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertCount(1, $json['rejected']);
        $this->assertSame('used_as_material', $json['rejected'][0]['reason']);

        // Remove Flour from Bread recipe
        $bread->materials = [];
        $bread->save();

        // Now Flour can be deleted
        $response2 = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/sync', [
                'changes' => [
                    [
                        'id' => $rawFlour->id,
                        'entity' => 'product',
                        'op' => 'delete',
                        'data' => ['id' => $rawFlour->id],
                        'client_ts' => (int) (microtime(true) * 1000),
                    ],
                ],
                'cursor' => 0,
            ]);

        $response2->assertStatus(200);
        $this->assertContains($rawFlour->id, $response2->json()['accepted']);
    }

    /**
     * 7. Wire defaults for older clients:
     * - missing product fields default to stockable and tracks_stock=true
     */
    public function test_wire_defaults_for_older_clients(): void
    {
        $oldProdId = (string) Str::uuid7();
        $now = (int) (microtime(true) * 1000);

        // Older client sync without kind, ask_price, materials
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/sync', [
                'changes' => [
                    [
                        'id' => $oldProdId,
                        'entity' => 'product',
                        'op' => 'upsert',
                        'data' => [
                            'id' => $oldProdId,
                            'name' => 'Old Client Widget',
                            'unit' => 'pcs',
                            'cost_price_cents' => 1000,
                            'sell_price_cents' => 1500,
                            'is_active' => true,
                            'updated_at' => $now,
                        ],
                        'client_ts' => $now,
                    ],
                ],
                'cursor' => 0,
            ]);

        $response->assertStatus(200);
        $this->assertContains($oldProdId, $response->json()['accepted']);

        $saved = Product::withoutGlobalScope(ShopScope::class)->find($oldProdId);
        $this->assertSame('stockable', $saved->kind);
        $this->assertFalse($saved->ask_price);
        $this->assertNull($saved->materials);

        // Older client movement sync without tracks_stock
        $oldMovId = (string) Str::uuid7();
        $movResponse = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/sync', [
                'changes' => [
                    [
                        'id' => $oldMovId,
                        'entity' => 'movement',
                        'op' => 'upsert',
                        'data' => [
                            'id' => $oldMovId,
                            'product_id' => $oldProdId,
                            'location_id' => $this->location->id,
                            'type' => 'in',
                            'qty' => '10.000',
                            'created_at' => $now,
                        ],
                        'client_ts' => $now,
                    ],
                ],
                'cursor' => 0,
            ]);

        $movResponse->assertStatus(200);
        $this->assertContains($oldMovId, $movResponse->json()['accepted']);

        $savedMov = Movement::withoutGlobalScope(ShopScope::class)->find($oldMovId);
        $this->assertTrue($savedMov->tracks_stock);
        $this->assertNull($savedMov->source_movement_id);
    }

    /**
     * 8. tracks_stock_mismatch validation before and after a kind change.
     */
    public function test_tracks_stock_mismatch_validation_before_and_after_kind_change(): void
    {
        $prod = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Hybrid Tech',
            'unit' => 'pcs',
            'kind' => 'stockable',
            'kind_changed_at_ms' => 2000,
            'is_active' => true,
            'updated_at_ms' => 2000,
        ]);

        // Sale movement before kind change (created_at = 1000 <= 2000):
        // Either tracks_stock true or false is accepted to preserve history
        $movBeforeId = (string) Str::uuid7();
        $resBefore = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/sync', [
                'changes' => [
                    [
                        'id' => $movBeforeId,
                        'entity' => 'movement',
                        'op' => 'upsert',
                        'data' => [
                            'id' => $movBeforeId,
                            'product_id' => $prod->id,
                            'location_id' => $this->location->id,
                            'type' => 'sale',
                            'tracks_stock' => false,
                            'qty' => '-1.000',
                            'created_at' => 1000,
                        ],
                        'client_ts' => 1000,
                    ],
                ],
                'cursor' => 0,
            ]);

        $resBefore->assertStatus(200);
        $this->assertContains($movBeforeId, $resBefore->json()['accepted']);

        // Product is stockable, but movement created_at is 3000 (> 2000)
        // If client sends tracks_stock=false on a stockable item, must reject with tracks_stock_mismatch
        $movAfterId = (string) Str::uuid7();
        $resAfter = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/sync', [
                'changes' => [
                    [
                        'id' => $movAfterId,
                        'entity' => 'movement',
                        'op' => 'upsert',
                        'data' => [
                            'id' => $movAfterId,
                            'product_id' => $prod->id,
                            'location_id' => $this->location->id,
                            'type' => 'sale',
                            'tracks_stock' => false,
                            'qty' => '-1.000',
                            'created_at' => 3000,
                        ],
                        'client_ts' => 3000,
                    ],
                ],
                'cursor' => 0,
            ]);

        $resAfter->assertStatus(200);
        $json = $resAfter->json();
        $this->assertCount(1, $json['rejected']);
        $this->assertSame('tracks_stock_mismatch', $json['rejected'][0]['reason']);
    }

    /**
     * 9. invalid_consume validation cases.
     */
    public function test_invalid_consume_cases(): void
    {
        $stockable = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Flour',
            'kind' => 'stockable',
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        $service = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Baking Class',
            'kind' => 'service',
            'is_active' => true,
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'user_id' => $this->owner->id,
            'total_cents' => 1000,
            'created_at_ms' => (int) (microtime(true) * 1000),
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        $validSaleLine = Movement::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $service->id,
            'location_id' => $this->location->id,
            'sale_id' => $sale->id,
            'type' => 'sale',
            'tracks_stock' => false,
            'qty_milli' => -1000,
            'created_at_ms' => (int) (microtime(true) * 1000),
            'server_seq' => 1,
        ]);

        $now = (int) (microtime(true) * 1000);

        // Case A: positive quantity on consume movement
        $resA = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/sync', [
                'changes' => [
                    [
                        'id' => (string) Str::uuid7(),
                        'entity' => 'movement',
                        'op' => 'upsert',
                        'data' => [
                            'id' => (string) Str::uuid7(),
                            'product_id' => $stockable->id,
                            'location_id' => $this->location->id,
                            'sale_id' => $sale->id,
                            'source_movement_id' => $validSaleLine->id,
                            'type' => 'consume',
                            'qty' => '1.000', // positive!
                            'created_at' => $now,
                        ],
                        'client_ts' => $now,
                    ],
                ],
                'cursor' => 0,
            ]);
        $this->assertSame('invalid_consume', $resA->json()['rejected'][0]['reason']);

        // Case B: consume targets a non-stockable material
        $resB = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/sync', [
                'changes' => [
                    [
                        'id' => (string) Str::uuid7(),
                        'entity' => 'movement',
                        'op' => 'upsert',
                        'data' => [
                            'id' => (string) Str::uuid7(),
                            'product_id' => $service->id, // not stockable!
                            'location_id' => $this->location->id,
                            'sale_id' => $sale->id,
                            'source_movement_id' => $validSaleLine->id,
                            'type' => 'consume',
                            'qty' => '-1.000',
                            'created_at' => $now,
                        ],
                        'client_ts' => $now,
                    ],
                ],
                'cursor' => 0,
            ]);
        $this->assertSame('invalid_consume', $resB->json()['rejected'][0]['reason']);

        // Case C: consume references a tracking sale line (tracks_stock=true)
        $trackingSaleLine = Movement::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $stockable->id,
            'location_id' => $this->location->id,
            'sale_id' => $sale->id,
            'type' => 'sale',
            'tracks_stock' => true,
            'qty_milli' => -1000,
            'created_at_ms' => (int) (microtime(true) * 1000),
            'server_seq' => 2,
        ]);

        $resC = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/sync', [
                'changes' => [
                    [
                        'id' => (string) Str::uuid7(),
                        'entity' => 'movement',
                        'op' => 'upsert',
                        'data' => [
                            'id' => (string) Str::uuid7(),
                            'product_id' => $stockable->id,
                            'location_id' => $this->location->id,
                            'sale_id' => $sale->id,
                            'source_movement_id' => $trackingSaleLine->id, // tracking line!
                            'type' => 'consume',
                            'qty' => '-1.000',
                            'created_at' => $now,
                        ],
                        'client_ts' => $now,
                    ],
                ],
                'cursor' => 0,
            ]);
        $this->assertSame('invalid_consume', $resC->json()['rejected'][0]['reason']);
    }

    /**
     * 10. Report totals and estimated profit across all kinds.
     */
    public function test_report_totals_and_estimated_profit(): void
    {
        $now = (int) (microtime(true) * 1000);
        $range = [$now - 3600000, $now + 3600000];

        // 1. Stockable item: Soda
        // Sell: 50 ETB (5000 cents), Cost: 30 ETB (3000 cents)
        $soda = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Coca-Cola',
            'kind' => 'stockable',
            'sell_price_cents' => 5000,
            'cost_price_cents' => 3000,
            'is_active' => true,
            'updated_at_ms' => $now,
        ]);

        // 2. Service item: Delivery Fee
        // Sell: 100 ETB (10000 cents), Cost: 40 ETB (4000 cents)
        $delivery = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Fast Delivery',
            'kind' => 'service',
            'sell_price_cents' => 10000,
            'cost_price_cents' => 4000,
            'is_active' => true,
            'updated_at_ms' => $now,
        ]);

        // 3. Raw material: Meat
        // Cost: 600 ETB / kg (60000 cents)
        $meat = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Beef',
            'unit' => 'kg',
            'kind' => 'stockable',
            'cost_price_cents' => 60000,
            'sell_price_cents' => 80000,
            'is_active' => true,
            'updated_at_ms' => $now,
        ]);

        // 4. Consumable: Burger
        // Sell: 250 ETB (25000 cents), Recipe: 0.200 kg beef (200 milli)
        $burger = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Burger',
            'kind' => 'consumable',
            'sell_price_cents' => 25000,
            'materials' => [
                ['product_id' => $meat->id, 'qty_milli' => 200],
            ],
            'is_active' => true,
            'updated_at_ms' => $now,
        ]);

        // Restock meat: 5 kg
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $meat->id,
            'location_id' => $this->location->id,
            'type' => 'in',
            'qty_milli' => 5000,
            'created_at_ms' => $now,
        ]));

        // Restock soda: 10 bottles
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $soda->id,
            'location_id' => $this->location->id,
            'type' => 'in',
            'qty_milli' => 10000,
            'created_at_ms' => $now,
        ]));

        // Sale with 1 soda, 1 delivery, 1 burger
        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'user_id' => $this->owner->id,
            'total_cents' => 40000, // 50 + 100 + 250 = 400 ETB
            'created_at_ms' => $now,
            'updated_at_ms' => $now,
        ]);

        $this->salesService->addLine($sale, $soda, '1.000', 5000, $this->location, $this->owner, createdAtMs: $now);
        $this->salesService->addLine($sale, $delivery, '1.000', 10000, $this->location, $this->owner, createdAtMs: $now);
        $this->salesService->addLine($sale, $burger, '1.000', 25000, $this->location, $this->owner, createdAtMs: $now);

        // A. SalesByKind
        $byKind = $this->reportService->salesByKind($this->shop, $range);
        $this->assertSame(40000, $byKind['total_revenue_cents']);
        $this->assertSame(5000, $byKind['by_kind']['stockable']['revenue_cents']);
        $this->assertSame(10000, $byKind['by_kind']['service']['revenue_cents']);
        $this->assertSame(25000, $byKind['by_kind']['consumable']['revenue_cents']);

        // B. MaterialsUsed
        // Consumed 0.200 kg beef = 120 ETB (12000 cents)
        $materials = $this->reportService->materialsUsed($this->shop, $range);
        $this->assertSame(1, $materials['count']);
        $this->assertSame(12000, $materials['total_cost_cents']);
        $this->assertSame('0.200', $materials['materials'][0]['qty']);

        // C. Estimated Profit
        // Total Revenue: 40000 cents
        // Costs:
        // - Soda: 3000 cents
        // - Delivery: 4000 cents
        // - Burger: 0.200 kg * 60000 = 12000 cents
        // Total Cost = 19000 cents
        // Profit = 40000 - 19000 = 21000 cents
        $profitData = $this->reportService->estimatedProfit($this->shop, $range);
        $this->assertSame(40000, $profitData['revenue_cents']);
        $this->assertSame(19000, $profitData['cost_cents']);
        $this->assertSame(21000, $profitData['profit_cents']);

        // D. Stock list excludes non-stockable items
        $stockList = $this->ledger->stockList($this->shop);
        $stockProdIds = $stockList->pluck('id')->all();
        $this->assertContains($soda->id, $stockProdIds);
        $this->assertContains($meat->id, $stockProdIds);
        $this->assertNotContains($delivery->id, $stockProdIds);
        $this->assertNotContains($burger->id, $stockProdIds);
    }
}
