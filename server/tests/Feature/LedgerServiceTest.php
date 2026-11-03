<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Context\CurrentShopContext;
use App\Exceptions\CrossShopViolationException;
use App\Exceptions\MissingShopContextException;
use App\Models\Change;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Shop;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected LedgerService $ledger;
    protected Shop $shopA;
    protected Shop $shopB;
    protected Product $productA1;
    protected Product $productA2;
    protected Product $productB1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = new LedgerService();

        // Create Shop A
        $this->shopA = Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Addis Ababa Bole Shop',
            'currency' => 'ETB',
            'language' => 'am',
            'low_stock_default_milli' => 5000,
        ]);

        // Create Shop B
        $this->shopB = Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Hawassa Piassa Shop',
            'currency' => 'ETB',
            'language' => 'am',
            'low_stock_default_milli' => 3000,
        ]);

        // Create Products in Shop A
        CurrentShopContext::runWith($this->shopA, function (): void {
            $this->productA1 = Product::create([
                'id' => (string) Str::uuid7(),
                'name' => 'Teff Maganaja (ጤፍ)',
                'sku' => 'TEFF-MAG-01',
                'unit' => 'kg',
                'cost_price_cents' => 9500,
                'sell_price_cents' => 12000,
                'low_stock_at_milli' => 10000, // 10.000 kg
            ]);

            $this->productA2 = Product::create([
                'id' => (string) Str::uuid7(),
                'name' => 'Coffee Beans Yirgacheffe (ቡና)',
                'sku' => 'COF-YIRG-01',
                'unit' => 'kg',
                'cost_price_cents' => 45000,
                'sell_price_cents' => 60000,
            ]);
        });

        // Create Product in Shop B
        CurrentShopContext::runWith($this->shopB, function (): void {
            $this->productB1 = Product::create([
                'id' => (string) Str::uuid7(),
                'name' => 'Barley (ገብስ)',
                'sku' => 'BAR-01',
                'unit' => 'kg',
                'cost_price_cents' => 7000,
                'sell_price_cents' => 9000,
            ]);
        });

        CurrentShopContext::clear();
    }

    public function test_record_movement_stamps_server_seq_and_appends_to_changes(): void
    {
        CurrentShopContext::set($this->shopA);

        $movementId = (string) Str::uuid7();
        $movement = new Movement([
            'id' => $movementId,
            'product_id' => $this->productA1->id,
            'type' => 'in',
            'qty_milli' => 25000, // 25.000 kg restock
            'unit_price_cents' => 9500,
            'note' => 'Supplier Delivery #101',
        ]);

        $recorded = $this->ledger->record($movement);

        $this->assertSame($movementId, $recorded->id);
        $this->assertSame(1, $recorded->server_seq);
        $this->assertSame($this->shopA->id, $recorded->shop_id);

        // Verify movement exists in DB
        $this->assertDatabaseHas('movements', [
            'id' => $movementId,
            'shop_id' => $this->shopA->id,
            'server_seq' => 1,
            'qty_milli' => 25000,
        ]);

        // Verify change record appended
        $this->assertDatabaseHas('changes', [
            'shop_id' => $this->shopA->id,
            'entity' => 'movement',
            'entity_id' => $movementId,
        ]);

        // Verify stock computation
        $this->assertSame(25000, $this->ledger->stockOf($this->productA1));
    }

    public function test_idempotent_replays_are_harmless_and_do_not_double_count(): void
    {
        CurrentShopContext::set($this->shopA);

        $movementId = (string) Str::uuid7();
        $movement1 = new Movement([
            'id' => $movementId,
            'product_id' => $this->productA1->id,
            'type' => 'in',
            'qty_milli' => 10000, // 10.000 kg
            'unit_price_cents' => 9500,
            'note' => 'Original batch',
        ]);

        $recorded1 = $this->ledger->record($movement1);
        $this->assertSame(10000, $this->ledger->stockOf($this->productA1));
        $this->assertSame(1, Movement::count());
        $this->assertSame(1, Change::count());

        // Replay: exact same movement UUID sent again (network retry or offline sync repeat)
        $movement2 = new Movement([
            'id' => $movementId,
            'product_id' => $this->productA1->id,
            'type' => 'in',
            'qty_milli' => 10000,
            'unit_price_cents' => 9500,
            'note' => 'Original batch (retry)',
        ]);

        $recorded2 = $this->ledger->record($movement2);

        // Assert returned instance matches original and nothing was duplicated
        $this->assertSame($recorded1->id, $recorded2->id);
        $this->assertSame(1, Movement::count());
        $this->assertSame(1, Change::count());
        $this->assertSame(10000, $this->ledger->stockOf($this->productA1), 'Stock must not be double counted on replay');
    }

    public function test_concurrent_style_double_submits_handle_gracefully(): void
    {
        CurrentShopContext::set($this->shopA);

        $movementId = (string) Str::uuid7();

        // Simulate two concurrent execution flows with the same movement ID
        $m1 = new Movement([
            'id' => $movementId,
            'product_id' => $this->productA1->id,
            'type' => 'sale',
            'qty_milli' => -5000, // -5.000 kg
            'unit_price_cents' => 12000,
        ]);

        $m2 = new Movement([
            'id' => $movementId,
            'product_id' => $this->productA1->id,
            'type' => 'sale',
            'qty_milli' => -5000,
            'unit_price_cents' => 12000,
        ]);

        $res1 = $this->ledger->record($m1);
        $res2 = $this->ledger->record($m2);

        $this->assertSame($movementId, $res1->id);
        $this->assertSame($movementId, $res2->id);
        $this->assertSame(1, Movement::count());
        $this->assertSame(-5000, $this->ledger->stockOf($this->productA1));
    }

    public function test_stock_computed_as_sum_of_movements_ledger(): void
    {
        CurrentShopContext::set($this->shopA);

        // 1. Initial intake: +50.000 kg
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'product_id' => $this->productA1->id,
            'type' => 'in',
            'qty_milli' => 50000,
        ]));

        // 2. Point of sale purchase: -12.500 kg
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'product_id' => $this->productA1->id,
            'type' => 'sale',
            'qty_milli' => -12500,
        ]));

        // 3. Spoilage adjustment: -2.500 kg
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'product_id' => $this->productA1->id,
            'type' => 'adjust',
            'qty_milli' => -2500,
            'note' => 'Water damage',
        ]));

        // 4. Return from customer: +1.000 kg
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'product_id' => $this->productA1->id,
            'type' => 'in',
            'qty_milli' => 1000,
            'note' => 'Customer return',
        ]));

        // 50000 - 12500 - 2500 + 1000 = 36000 (36.000 kg)
        $this->assertSame(36000, $this->ledger->stockOf($this->productA1));
        $this->assertSame('36.000', LedgerService::formatQty($this->ledger->stockOf($this->productA1)));
    }

    public function test_stock_list_computes_all_products_in_shop(): void
    {
        CurrentShopContext::set($this->shopA);

        // Product A1: +10.000 kg
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'product_id' => $this->productA1->id,
            'type' => 'in',
            'qty_milli' => 10000,
        ]));

        // Product A2 has 0 movements

        $list = $this->ledger->stockList($this->shopA);

        $this->assertCount(2, $list);

        $itemA1 = $list->firstWhere('id', $this->productA1->id);
        $itemA2 = $list->firstWhere('id', $this->productA2->id);

        $this->assertNotNull($itemA1);
        $this->assertNotNull($itemA2);
        $this->assertSame(10000, (int) $itemA1->stock_milli);
        $this->assertSame(0, (int) $itemA2->stock_milli);
    }

    public function test_cross_shop_isolation_in_queries(): void
    {
        // Under Shop A context, only Shop A products are visible
        CurrentShopContext::set($this->shopA);
        $productsA = Product::all();
        $this->assertCount(2, $productsA);
        $this->assertTrue($productsA->contains('id', $this->productA1->id));
        $this->assertTrue($productsA->contains('id', $this->productA2->id));
        $this->assertFalse($productsA->contains('id', $this->productB1->id));

        // Under Shop B context, only Shop B products are visible
        CurrentShopContext::set($this->shopB);
        $productsB = Product::all();
        $this->assertCount(1, $productsB);
        $this->assertTrue($productsB->contains('id', $this->productB1->id));
        $this->assertFalse($productsB->contains('id', $this->productA1->id));

        // Without shop context, zero records returned by default to prevent leak
        CurrentShopContext::clear();
        $this->assertCount(0, Product::all());
    }

    public function test_cannot_create_model_without_shop_context_or_shop_id(): void
    {
        CurrentShopContext::clear();

        $this->expectException(MissingShopContextException::class);

        Product::create([
            'name' => 'Orphan Product',
            'unit' => 'pcs',
            'cost_price_cents' => 100,
            'sell_price_cents' => 200,
        ]);
    }

    public function test_cannot_record_movement_for_product_belonging_to_another_shop(): void
    {
        // Context is Shop A, but product belongs to Shop B
        CurrentShopContext::set($this->shopA);

        $this->expectException(CrossShopViolationException::class);

        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'product_id' => $this->productB1->id, // Belongs to Shop B!
            'type' => 'in',
            'qty_milli' => 5000,
        ]));
    }

    public function test_cannot_query_stock_of_product_belonging_to_another_shop(): void
    {
        CurrentShopContext::set($this->shopA);

        $this->expectException(CrossShopViolationException::class);

        // Product B1 belongs to Shop B
        $this->ledger->stockOf($this->productB1);
    }

    public function test_unique_sku_per_shop_allows_null_skus(): void
    {
        CurrentShopContext::set($this->shopA);

        // Multiple products with null SKU are permitted
        $p1 = Product::create([
            'name' => 'Unbranded Sugar 1',
            'sku' => null,
            'unit' => 'kg',
            'cost_price_cents' => 5000,
            'sell_price_cents' => 6000,
        ]);

        $p2 = Product::create([
            'name' => 'Unbranded Sugar 2',
            'sku' => null,
            'unit' => 'kg',
            'cost_price_cents' => 5000,
            'sell_price_cents' => 6000,
        ]);

        $this->assertNotNull($p1->id);
        $this->assertNotNull($p2->id);

        // Different shops can use the same SKU
        CurrentShopContext::runWith($this->shopB, function (): void {
            $pShopB = Product::create([
                'name' => 'Teff Maganaja Shop B',
                'sku' => 'TEFF-MAG-01', // Same SKU as Shop A's productA1
                'unit' => 'kg',
                'cost_price_cents' => 9500,
                'sell_price_cents' => 12000,
            ]);
            $this->assertNotNull($pShopB->id);
        });

        // Duplicate SKU within the same shop throws unique constraint violation
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Product::create([
            'name' => 'Duplicate SKU Product',
            'sku' => 'TEFF-MAG-01', // Duplicate in Shop A!
            'unit' => 'kg',
            'cost_price_cents' => 9500,
            'sell_price_cents' => 12000,
        ]);
    }
}
