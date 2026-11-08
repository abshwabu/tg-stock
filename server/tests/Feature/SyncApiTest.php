<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Change;
use App\Models\Movement;
use App\Models\Product;
use App\Scopes\ShopScope;
use App\Services\LedgerService;
use App\Services\PairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncApiTest extends TestCase
{
    use RefreshDatabase;

    protected PairingService $pairing;
    protected LedgerService $ledger;

    protected array $shopA;
    protected array $shopB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pairing = app(PairingService::class);
        $this->ledger = app(LedgerService::class);

        // Setup Shop A with Device A1
        $pairA1 = $this->pairing->startPairing('Addis Bole Shop', 'Phone A1');
        $confirmA1 = $this->pairing->confirmPairing($pairA1['code'], 10001, 'Owner A');
        $tokenA1 = $this->pairing->getPairingStatus($pairA1['code'])['device_token'];

        // Add Device A2 to Shop A
        $pairA2 = $this->pairing->startPairing(null, 'Phone A2');
        $confirmA2 = $this->pairing->confirmPairing($pairA2['code'], 10001, 'Owner A');
        $tokenA2 = $this->pairing->getPairingStatus($pairA2['code'])['device_token'];

        $this->shopA = [
            'shop' => $confirmA1['shop'],
            'device1' => $confirmA1['device'],
            'token1' => $tokenA1,
            'device2' => $confirmA2['device'],
            'token2' => $tokenA2,
        ];

        // Setup Shop B with Device B1
        $pairB1 = $this->pairing->startPairing('Hawassa Shop', 'Phone B1');
        $confirmB1 = $this->pairing->confirmPairing($pairB1['code'], 20002, 'Owner B');
        $tokenB1 = $this->pairing->getPairingStatus($pairB1['code'])['device_token'];

        $this->shopB = [
            'shop' => $confirmB1['shop'],
            'device1' => $confirmB1['device'],
            'token1' => $tokenB1,
        ];
    }

    public function test_first_sync_accepts_full_local_history_without_data_loss(): void
    {
        $productId = (string) Str::uuid7();
        $movementId1 = (string) Str::uuid7();
        $movementId2 = (string) Str::uuid7();

        $batch = [
            'cursor' => 0,
            'changes' => [
                // 1. Initial product created offline
                [
                    'id' => $productId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $productId,
                        'name' => 'Teff Maganaja (ጤፍ)',
                        'sku' => 'TEFF-01',
                        'unit' => 'kg',
                        'cost_price_cents' => 9000,
                        'sell_price_cents' => 12000,
                        'low_stock_at' => '10.000',
                        'is_active' => true,
                        'updated_at' => 1730000000000,
                    ],
                ],
                // 2. Initial stock intake: +100.000 kg
                [
                    'id' => $movementId1,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => 1730000001000,
                    'data' => [
                        'id' => $movementId1,
                        'product_id' => $productId,
                        'type' => 'in',
                        'qty' => '100.000',
                        'unit_price_cents' => 9000,
                        'note' => 'Opening stock',
                        'created_at' => 1730000001000,
                    ],
                ],
                // 3. First offline retail sale: -15.500 kg
                [
                    'id' => $movementId2,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => 1730000002000,
                    'data' => [
                        'id' => $movementId2,
                        'product_id' => $productId,
                        'type' => 'sale',
                        'qty' => '-15.500',
                        'unit_price_cents' => 12000,
                        'note' => 'Walk-in customer',
                        'created_at' => 1730000002000,
                    ],
                ],
            ],
        ];

        $res = $this->withToken($this->shopA['token1'])->postJson('/v1/sync', $batch);

        $res->assertStatus(200);
        $res->assertJsonCount(3, 'accepted');
        $this->assertEmpty($res->json('rejected'));
        $this->assertGreaterThan(0, $res->json('cursor'));

        // Verify product in database
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'shop_id' => $this->shopA['shop']->id,
            'name' => 'Teff Maganaja (ጤፍ)',
        ]);

        // Verify movements in database
        $this->assertDatabaseHas('movements', ['id' => $movementId1, 'qty_milli' => 100000]);
        $this->assertDatabaseHas('movements', ['id' => $movementId2, 'qty_milli' => -15500]);

        // Compute authoritative stock from ledger: 100.000 - 15.500 = 84.500 kg
        $product = Product::withoutGlobalScope(ShopScope::class)->find($productId);
        $this->assertSame(84500, $this->ledger->stockOf($product));
        $this->assertSame('84.500', LedgerService::formatQty($this->ledger->stockOf($product)));
    }

    public function test_replayed_batches_are_harmless_and_produce_no_duplicates(): void
    {
        $productId = (string) Str::uuid7();
        $movementId = (string) Str::uuid7();

        $batch = [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $productId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $productId,
                        'name' => 'Wheat (ስንዴ)',
                        'unit' => 'kg',
                        'cost_price_cents' => 5000,
                        'sell_price_cents' => 7000,
                        'updated_at' => 1730000000000,
                    ],
                ],
                [
                    'id' => $movementId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => 1730000001000,
                    'data' => [
                        'id' => $movementId,
                        'product_id' => $productId,
                        'type' => 'in',
                        'qty' => '50.000',
                        'created_at' => 1730000001000,
                    ],
                ],
            ],
        ];

        // First push
        $res1 = $this->withToken($this->shopA['token1'])->postJson('/v1/sync', $batch);
        $res1->assertStatus(200);
        $res1->assertJsonCount(2, 'accepted');

        $initialProductCount = Product::withoutGlobalScope(ShopScope::class)->where('shop_id', $this->shopA['shop']->id)->count();
        $initialMovementCount = Movement::withoutGlobalScope(ShopScope::class)->where('shop_id', $this->shopA['shop']->id)->count();
        $this->assertSame(1, $initialProductCount);
        $this->assertSame(1, $initialMovementCount);

        // Replay exact same batch (e.g. timeout on mobile client before response received)
        $res2 = $this->withToken($this->shopA['token1'])->postJson('/v1/sync', $batch);
        $res2->assertStatus(200);
        $res2->assertJsonCount(2, 'accepted');

        // Verify no duplicate records created
        $finalProductCount = Product::withoutGlobalScope(ShopScope::class)->where('shop_id', $this->shopA['shop']->id)->count();
        $finalMovementCount = Movement::withoutGlobalScope(ShopScope::class)->where('shop_id', $this->shopA['shop']->id)->count();
        $this->assertSame(1, $finalProductCount);
        $this->assertSame(1, $finalMovementCount);

        $product = Product::withoutGlobalScope(ShopScope::class)->find($productId);
        $this->assertSame(50000, $this->ledger->stockOf($product));
    }

    public function test_two_devices_selling_offline_both_land_correctly(): void
    {
        $productId = (string) Str::uuid7();

        // Step 1: Create product with opening stock of 50.000 units on server
        $this->withToken($this->shopA['token1'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $productId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $productId,
                        'name' => 'Sunflower Oil (ዘይት)',
                        'unit' => 'L',
                        'cost_price_cents' => 80000,
                        'sell_price_cents' => 95000,
                        'updated_at' => 1730000000000,
                    ],
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => 1730000001000,
                    'data' => [
                        'id' => (string) Str::uuid7(),
                        'product_id' => $productId,
                        'type' => 'in',
                        'qty' => '50.000',
                        'created_at' => 1730000001000,
                    ],
                ],
            ],
        ]);

        // Step 2: Device 1 sells 10.000 L offline
        $sale1Id = (string) Str::uuid7();
        $resDevice1 = $this->withToken($this->shopA['token1'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $sale1Id,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => 1730000005000,
                    'data' => [
                        'id' => $sale1Id,
                        'product_id' => $productId,
                        'type' => 'sale',
                        'qty' => '-10.000',
                        'created_at' => 1730000005000,
                    ],
                ],
            ],
        ]);
        $resDevice1->assertStatus(200);
        $resDevice1->assertJson(['accepted' => [$sale1Id]]);

        // Step 3: Device 2 simultaneously sold 15.000 L offline and now syncs
        $sale2Id = (string) Str::uuid7();
        $resDevice2 = $this->withToken($this->shopA['token2'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $sale2Id,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => 1730000006000,
                    'data' => [
                        'id' => $sale2Id,
                        'product_id' => $productId,
                        'type' => 'sale',
                        'qty' => '-15.000',
                        'created_at' => 1730000006000,
                    ],
                ],
            ],
        ]);
        $resDevice2->assertStatus(200);
        $resDevice2->assertJson(['accepted' => [$sale2Id]]);

        // Assert: Both sales landed in the ledger
        $this->assertDatabaseHas('movements', ['id' => $sale1Id, 'qty_milli' => -10000]);
        $this->assertDatabaseHas('movements', ['id' => $sale2Id, 'qty_milli' => -15000]);

        // Authoritative stock: 50.000 - 10.000 - 15.000 = 25.000 L
        $product = Product::withoutGlobalScope(ShopScope::class)->find($productId);
        $this->assertSame(25000, $this->ledger->stockOf($product));
        $this->assertSame('25.000', LedgerService::formatQty($this->ledger->stockOf($product)));

        // Device 2 should receive Device 1's sale in its pull changes
        $incomingForDevice2 = $resDevice2->json('changes');
        $this->assertNotEmpty($incomingForDevice2);
        $receivedIds = array_column(array_column($incomingForDevice2, 'data'), 'id');
        $this->assertContains($sale1Id, $receivedIds);
        $this->assertNotContains($sale2Id, $receivedIds, "Device 2 must not receive its own change in the response");
    }

    public function test_stale_product_edits_lose_correctly_under_last_write_wins(): void
    {
        $productId = (string) Str::uuid7();

        // 1. Initial product created at timestamp 1000
        $this->withToken($this->shopA['token1'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $productId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => 1000,
                    'data' => [
                        'id' => $productId,
                        'name' => 'Initial Name',
                        'unit' => 'pcs',
                        'updated_at' => 1000,
                    ],
                ],
            ],
        ]);

        // 2. Newer update landed at timestamp 5000: name = "Newer Name"
        $this->withToken($this->shopA['token1'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $productId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => 5000,
                    'data' => [
                        'id' => $productId,
                        'name' => 'Newer Name',
                        'unit' => 'pcs',
                        'updated_at' => 5000,
                    ],
                ],
            ],
        ]);

        // 3. Stale update arrives with timestamp 3000: name = "Stale Name"
        $staleRes = $this->withToken($this->shopA['token2'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $productId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => 3000,
                    'data' => [
                        'id' => $productId,
                        'name' => 'Stale Name',
                        'unit' => 'pcs',
                        'updated_at' => 3000,
                    ],
                ],
            ],
        ]);

        $staleRes->assertStatus(200);
        // Stale edit is rejected
        $this->assertCount(1, $staleRes->json('rejected'));
        $this->assertSame($productId, $staleRes->json('rejected.0.id'));

        // Verify product in DB retained "Newer Name"
        $product = Product::withoutGlobalScope(ShopScope::class)->find($productId);
        $this->assertSame('Newer Name', $product->name);
        $this->assertSame(5000, $product->updated_at_ms);
    }

    public function test_cross_shop_access_is_impossible(): void
    {
        // 1. Create a product in Shop B
        $productBId = (string) Str::uuid7();
        $this->withToken($this->shopB['token1'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $productBId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $productBId,
                        'name' => 'Shop B Secret Product',
                        'unit' => 'pcs',
                        'updated_at' => 1730000000000,
                    ],
                ],
            ],
        ]);

        // 2. Shop A attempts to record a movement referencing Shop B's product
        $illegalMovementId = (string) Str::uuid7();
        $crossRes = $this->withToken($this->shopA['token1'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $illegalMovementId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $illegalMovementId,
                        'product_id' => $productBId, // Belongs to Shop B!
                        'type' => 'in',
                        'qty' => '10.000',
                    ],
                ],
            ],
        ]);

        $crossRes->assertStatus(200);
        $this->assertCount(1, $crossRes->json('rejected'));
        $this->assertSame($illegalMovementId, $crossRes->json('rejected.0.id'));
        $this->assertStringContainsString('does not exist in this shop', $crossRes->json('rejected.0.reason'));

        // Verify illegal movement was not saved
        $this->assertDatabaseMissing('movements', ['id' => $illegalMovementId]);
    }

    public function test_client_timestamps_more_than_5_minutes_in_future_are_clamped(): void
    {
        $productId = (string) Str::uuid7();
        $oneDayInFuture = (int) (microtime(true) * 1000) + (86400 * 1000);

        $res = $this->withToken($this->shopA['token1'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $productId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => $oneDayInFuture,
                    'data' => [
                        'id' => $productId,
                        'name' => 'Future Clamped Product',
                        'unit' => 'pcs',
                        'updated_at' => $oneDayInFuture,
                    ],
                ],
            ],
        ]);

        $res->assertStatus(200);
        $res->assertJson(['accepted' => [$productId]]);

        $product = Product::withoutGlobalScope(ShopScope::class)->find($productId);
        $nowMs = (int) (microtime(true) * 1000);

        // Assert timestamp was clamped to server time (within 5 seconds)
        $this->assertLessThan($oneDayInFuture, $product->updated_at_ms);
        $this->assertLessThanOrEqual($nowMs + 1000, $product->updated_at_ms);
    }

    public function test_sync_enforces_strict_validation_and_rejects_unknown_fields(): void
    {
        // Unknown root field
        $resUnknownRoot = $this->withToken($this->shopA['token1'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [],
            'unexpected_field' => 'bad_data',
        ]);
        $resUnknownRoot->assertStatus(422);

        // Unknown change item field
        $resUnknownItem = $this->withToken($this->shopA['token1'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => (string) Str::uuid7(),
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [],
                    'malicious_extra' => 'hack',
                ],
            ],
        ]);
        $resUnknownItem->assertStatus(200);
        $this->assertNotEmpty($resUnknownItem->json('rejected'));
        $this->assertStringContainsString('Unknown field(s) in change item', $resUnknownItem->json('rejected.0.reason'));
    }

    public function test_product_lww_tie_broken_by_device_id(): void
    {
        $productId = (string) Str::uuid7();
        $device1 = $this->shopA['device1'];
        $device2 = $this->shopA['device2'];

        // Determine which device has lexicographically lower / higher id
        $lowerToken = strcmp((string) $device1->id, (string) $device2->id) < 0 ? $this->shopA['token1'] : $this->shopA['token2'];
        $higherToken = strcmp((string) $device1->id, (string) $device2->id) < 0 ? $this->shopA['token2'] : $this->shopA['token1'];

        // 1. Initial product created by lower device
        $res1 = $this->withToken($lowerToken)->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $productId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $productId,
                        'name' => 'From Lower Device',
                        'unit' => 'pcs',
                        'updated_at' => 1730000000000,
                    ],
                ],
            ],
        ]);
        $res1->assertStatus(200);
        $this->assertContains($productId, $res1->json('accepted'));

        // 2. Higher device submits an edit with the EXACT same updated_at timestamp
        $resHigher = $this->withToken($higherToken)->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $productId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $productId,
                        'name' => 'From Higher Device (Winner)',
                        'unit' => 'pcs',
                        'updated_at' => 1730000000000,
                    ],
                ],
            ],
        ]);
        $resHigher->assertStatus(200);
        $this->assertContains($productId, $resHigher->json('accepted'));

        // Product on server should now have winner's name
        $product = Product::withoutGlobalScope(ShopScope::class)->find($productId);
        $this->assertSame('From Higher Device (Winner)', $product->name);

        // 3. Lower device attempts to overwrite with the exact same timestamp - should LOSE the tie breaker
        $resLowerAgain = $this->withToken($lowerToken)->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $productId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $productId,
                        'name' => 'From Lower Device (Loser)',
                        'unit' => 'pcs',
                        'updated_at' => 1730000000000,
                    ],
                ],
            ],
        ]);
        $resLowerAgain->assertStatus(200);
        $this->assertCount(1, $resLowerAgain->json('rejected'));
        $this->assertStringContainsString('tie_breaker_lost', $resLowerAgain->json('rejected.0.reason'));

        // Database remains winner's data
        $product->refresh();
        $this->assertSame('From Higher Device (Winner)', $product->name);
    }

    public function test_sync_ingests_movements_referencing_products_in_same_batch_regardless_of_order(): void
    {
        $productId = (string) Str::uuid7();
        $movementId = (string) Str::uuid7();

        // Put movement FIRST in the array before product
        $res = $this->withToken($this->shopA['token1'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $movementId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => 1730000001000,
                    'data' => [
                        'id' => $movementId,
                        'product_id' => $productId,
                        'type' => 'in',
                        'qty' => '100.000',
                        'created_at' => 1730000001000,
                    ],
                ],
                [
                    'id' => $productId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $productId,
                        'name' => 'Teff (ጤፍ)',
                        'unit' => 'kg',
                        'updated_at' => 1730000000000,
                    ],
                ],
            ],
        ]);

        $res->assertStatus(200);
        $this->assertContains($productId, $res->json('accepted'));
        $this->assertContains($movementId, $res->json('accepted'));
        $this->assertEmpty($res->json('rejected'));

        // Product and stock verified
        $product = Product::withoutGlobalScope(ShopScope::class)->find($productId);
        $this->assertNotNull($product);
        $this->assertSame(100000, $this->ledger->stockOf($product));
    }

    public function test_sync_recovers_gracefully_when_incoming_payload_references_unknown_location(): void
    {
        $productId = (string) Str::uuid7();
        $saleId = (string) Str::uuid7();
        $paymentId = (string) Str::uuid7();
        $movementId = (string) Str::uuid7();
        $nonExistentLocationId = (string) Str::uuid7();

        $res = $this->withToken($this->shopA['token1'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $productId,
                    'entity' => 'product',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $productId,
                        'name' => 'Coffee Beans 1kg',
                        'unit' => 'kg',
                        'updated_at' => 1730000000000,
                    ],
                ],
                [
                    'id' => $saleId,
                    'entity' => 'sale',
                    'op' => 'upsert',
                    'client_ts' => 1730000001000,
                    'data' => [
                        'id' => $saleId,
                        'location_id' => $nonExistentLocationId,
                        'total_cents' => 50000,
                        'created_at' => 1730000001000,
                    ],
                ],
                [
                    'id' => $paymentId,
                    'entity' => 'payment',
                    'op' => 'upsert',
                    'client_ts' => 1730000001500,
                    'data' => [
                        'id' => $paymentId,
                        'sale_id' => $saleId,
                        'location_id' => $nonExistentLocationId,
                        'method' => 'cash',
                        'amount_cents' => 50000,
                        'received_at' => 1730000001500,
                    ],
                ],
                [
                    'id' => $movementId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => 1730000002000,
                    'data' => [
                        'id' => $movementId,
                        'product_id' => $productId,
                        'sale_id' => $saleId,
                        'location_id' => $nonExistentLocationId,
                        'type' => 'sale',
                        'qty' => '-2.000',
                        'unit_price_cents' => 25000,
                        'created_at' => 1730000002000,
                    ],
                ],
            ],
        ]);

        $res->assertStatus(200);
        $this->assertContains($productId, $res->json('accepted'));
        $this->assertContains($saleId, $res->json('accepted'));
        $this->assertContains($paymentId, $res->json('accepted'));
        $this->assertContains($movementId, $res->json('accepted'));
        $this->assertEmpty($res->json('rejected'));

        // Verify the movement was recorded and associated with the shop's default location
        $movement = Movement::withoutGlobalScope(ShopScope::class)->find($movementId);
        $this->assertNotNull($movement);
        $defaultLoc = $this->shopA['shop']->getDefaultLocation();
        $this->assertSame($defaultLoc->id, $movement->location_id);
    }
}
