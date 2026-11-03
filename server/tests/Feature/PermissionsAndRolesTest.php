<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Context\CurrentShopContext;
use App\Models\Change;
use App\Models\Device;
use App\Models\Location;
use App\Models\Movement;
use App\Models\PermissionLog;
use App\Models\Product;
use App\Models\RejectedChange;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserLocation;
use App\Models\UserPermission;
use App\Scopes\ShopScope;
use App\Services\LedgerService;
use App\Services\PermissionService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class PermissionsAndRolesTest extends TestCase
{
    use RefreshDatabase;

    protected PermissionService $permissionService;
    protected LedgerService $ledger;
    protected SyncService $sync;
    protected Shop $shop;
    protected Location $storeLoc;
    protected Location $warehouseLoc;
    protected Product $product;
    protected User $owner;
    protected Device $ownerDevice;
    protected string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        CurrentShopContext::clear();

        $this->permissionService = app(PermissionService::class);
        $this->ledger = app(LedgerService::class);
        $this->sync = app(SyncService::class);

        // Create Shop
        $this->shop = Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Addis Electronics',
            'currency' => 'ETB',
            'language' => 'am',
            'locations_enabled' => true,
            'transfer_requires_receipt' => false,
            'allow_negative_stock' => true,
        ]);
        $this->storeLoc = $this->shop->getDefaultLocation();

        CurrentShopContext::runWith($this->shop, function (): void {
            $this->warehouseLoc = Location::create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'name' => 'Bole Warehouse',
                'kind' => 'warehouse',
                'is_default' => false,
                'is_active' => true,
                'updated_at_ms' => (int) (microtime(true) * 1000),
            ]);

            $this->owner = User::create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'telegram_id' => 999901,
                'name' => 'Abebe (Owner)',
                'role' => 'owner',
                'is_active' => true,
            ]);

            $this->ownerToken = 'owner-secret-token-123';
            $this->ownerDevice = Device::create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'user_id' => $this->owner->id,
                'name' => "Owner's Phone",
                'token_hash' => hash('sha256', $this->ownerToken),
            ]);

            $this->product = Product::create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'name' => 'Samsung Galaxy A15',
                'sku' => 'SAM-A15',
                'unit' => 'pcs',
                'cost_price_cents' => 1500000,
                'sell_price_cents' => 1800000,
                'is_active' => true,
                'updated_at_ms' => (int) (microtime(true) * 1000),
            ]);
        });
    }

    /**
     * 1. Test permission matrix and presets for all roles.
     */
    public function test_permission_matrix_and_presets(): void
    {
        // Owner has all permissions
        $ownerPerms = $this->permissionService->getPermissions($this->owner);
        $this->assertCount(count(PermissionService::PERMISSIONS), $ownerPerms);
        foreach (PermissionService::PERMISSIONS as $perm) {
            $this->assertTrue($this->permissionService->can($this->owner, $perm));
        }

        // Salesperson preset
        $salesperson = User::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'telegram_id' => 999902,
            'name' => 'Chala (Sales)',
            'role' => 'salesperson',
        ]);
        $salesPerms = $this->permissionService->getPermissions($salesperson);
        $this->assertEqualsCanonicalizing(
            ['products.view', 'sale.create', 'reports.own_sales', 'undo.own'],
            $salesPerms
        );
        $this->assertTrue($this->permissionService->can($salesperson, 'sale.create'));
        $this->assertFalse($this->permissionService->can($salesperson, 'stock.in'));
        $this->assertFalse($this->permissionService->can($salesperson, 'cost.view'));

        // Warehouse Keeper preset
        $keeper = User::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'telegram_id' => 999903,
            'name' => 'Kebede (Keeper)',
            'role' => 'warehouse_keeper',
        ]);
        $keeperPerms = $this->permissionService->getPermissions($keeper);
        $this->assertEqualsCanonicalizing(
            ['products.view', 'stock.in', 'transfer.create', 'transfer.receive', 'undo.own'],
            $keeperPerms
        );
        $this->assertTrue($this->permissionService->can($keeper, 'stock.in'));
        $this->assertFalse($this->permissionService->can($keeper, 'sale.create'));

        // Custom preset starts empty
        $customUser = User::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'telegram_id' => 999904,
            'name' => 'Marta (Custom)',
            'role' => 'custom',
        ]);
        $this->assertSame([], $this->permissionService->getPermissions($customUser));
    }

    /**
     * 2. Test deny overrides allow (deny always wins).
     */
    public function test_deny_overrides_allow(): void
    {
        $salesperson = User::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'telegram_id' => 999905,
            'name' => 'Sara (Sales)',
            'role' => 'salesperson',
        ]);

        // Override: add cost.view as allow, but explicitly deny sale.create
        $this->permissionService->updatePermissions($salesperson, [
            'cost.view' => 'allow',
            'sale.create' => 'deny',
        ]);

        $this->assertTrue($this->permissionService->can($salesperson, 'cost.view'));
        $this->assertFalse($this->permissionService->can($salesperson, 'sale.create'), 'Deny must override preset allow');

        // Conflicting overrides: user_permissions unique per (user_id, permission),
        // but if multiple are evaluated, deny wins
        $overrides = [
            'stock.in' => 'deny',
            'export' => 'allow',
        ];
        $this->permissionService->updatePermissions($salesperson, $overrides);
        $this->assertFalse($this->permissionService->can($salesperson, 'stock.in'));
        $this->assertTrue($this->permissionService->can($salesperson, 'export'));
    }

    /**
     * 3. Test location scope enforcement in sync.
     */
    public function test_location_scope_enforcement_in_sync(): void
    {
        $salesperson = User::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'telegram_id' => 999906,
            'name' => 'Tolosa (Sales)',
            'role' => 'salesperson',
        ]);

        // Assign user strictly to storeLoc
        $this->permissionService->updatePermissions($salesperson, [], [$this->storeLoc->id]);

        $token = 'sales-token-1';
        $device = Device::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $salesperson->id,
            'name' => "Tolosa's POS",
            'token_hash' => hash('sha256', $token),
        ]);

        $nowMs = (int) (microtime(true) * 1000);

        // 1. Sale at allowed location (storeLoc) -> ACCEPTED
        $allowedSaleId = (string) Str::uuid7();
        $payload1 = [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $allowedSaleId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'data' => [
                        'id' => $allowedSaleId,
                        'product_id' => $this->product->id,
                        'location_id' => $this->storeLoc->id,
                        'type' => 'sale',
                        'qty' => '1.000',
                        'unit_price_cents' => 1800000,
                        'created_at' => $nowMs,
                    ],
                ],
            ],
        ];

        $res1 = $this->sync->sync($device, $payload1, 1000);
        $this->assertCount(1, $res1['accepted']);
        $this->assertEmpty($res1['rejected']);

        // 2. Sale at forbidden location (warehouseLoc) -> REJECTED with forbidden_location
        $forbiddenSaleId = (string) Str::uuid7();
        $payload2 = [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $forbiddenSaleId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'data' => [
                        'id' => $forbiddenSaleId,
                        'product_id' => $this->product->id,
                        'location_id' => $this->warehouseLoc->id,
                        'type' => 'sale',
                        'qty' => '1.000',
                        'unit_price_cents' => 1800000,
                        'created_at' => $nowMs,
                    ],
                ],
            ],
        ];

        $res2 = $this->sync->sync($device, $payload2, 1000);
        $this->assertEmpty($res2['accepted']);
        $this->assertCount(1, $res2['rejected']);
        $this->assertSame('forbidden_location', $res2['rejected'][0]['reason']);

        // Verify recorded in rejected_changes
        $this->assertDatabaseHas('rejected_changes', [
            'shop_id' => $this->shop->id,
            'change_id' => $forbiddenSaleId,
            'reason' => 'forbidden_location',
            'status' => 'pending',
        ]);
    }

    /**
     * 4. Test effective-at-time evaluation for offline changes.
     */
    public function test_effective_at_time_offline_evaluation(): void
    {
        $salesperson = User::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'telegram_id' => 999907,
            'name' => 'Fikru (Sales)',
            'role' => 'salesperson',
        ]);

        $token = 'offline-sales-token';
        $device = Device::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $salesperson->id,
            'name' => "Fikru's Tablet",
            'token_hash' => hash('sha256', $token),
        ]);

        $t100 = 1000000;
        $t200 = 2000000;
        $t150 = 1500000;
        $t250 = 2500000;

        // Establish snapshot at t100 with salesperson permissions
        PermissionLog::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $salesperson->id,
            'effective_from_ms' => $t100,
            'snapshot' => [
                'active' => true,
                'role' => 'salesperson',
                'permissions' => PermissionService::PRESETS['salesperson'],
                'location_ids' => [$this->storeLoc->id],
            ],
            'changed_by' => $this->owner->id,
        ]);

        // At t200, owner revokes sale.create permission from salesperson
        PermissionLog::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $salesperson->id,
            'effective_from_ms' => $t200,
            'snapshot' => [
                'active' => true,
                'role' => 'salesperson',
                'permissions' => ['products.view', 'reports.own_sales', 'undo.own'],
                'location_ids' => [$this->storeLoc->id],
            ],
            'changed_by' => $this->owner->id,
        ]);

        // Offline change generated at t150 (before revocation) -> SHOULD BE ACCEPTED!
        $validOfflineId = (string) Str::uuid7();
        $payload1 = [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $validOfflineId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => $t150,
                    'data' => [
                        'id' => $validOfflineId,
                        'product_id' => $this->product->id,
                        'location_id' => $this->storeLoc->id,
                        'type' => 'sale',
                        'qty' => '1.000',
                        'unit_price_cents' => 1800000,
                        'created_at' => $t150,
                    ],
                ],
            ],
        ];

        $res1 = $this->sync->sync($device, $payload1, 1000);
        $this->assertCount(1, $res1['accepted'], 'Offline change created before revocation must be accepted');
        $this->assertEmpty($res1['rejected']);

        // Offline change generated at t250 (after revocation) -> SHOULD BE REJECTED!
        $invalidOfflineId = (string) Str::uuid7();
        $payload2 = [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $invalidOfflineId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => $t250,
                    'data' => [
                        'id' => $invalidOfflineId,
                        'product_id' => $this->product->id,
                        'location_id' => $this->storeLoc->id,
                        'type' => 'sale',
                        'qty' => '1.000',
                        'unit_price_cents' => 1800000,
                        'created_at' => $t250,
                    ],
                ],
            ],
        ];

        $res2 = $this->sync->sync($device, $payload2, 1000);
        $this->assertEmpty($res2['accepted']);
        $this->assertCount(1, $res2['rejected']);
        $this->assertSame('forbidden_permission', $res2['rejected'][0]['reason']);
    }

    /**
     * 5. Test cost stripping when user lacks cost.view permission.
     */
    public function test_cost_stripping_when_user_lacks_cost_view(): void
    {
        $salesperson = User::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'telegram_id' => 999908,
            'name' => 'Bete (Sales)',
            'role' => 'salesperson',
        ]);
        $this->permissionService->updatePermissions($salesperson, [], [$this->storeLoc->id]);

        $token = 'sales-cost-test-token';
        $salesDevice = Device::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $salesperson->id,
            'name' => "Bete's Phone",
            'token_hash' => hash('sha256', $token),
        ]);

        // Record a stock in movement and product change from owner device
        $inMovement = new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $this->product->id,
            'location_id' => $this->storeLoc->id,
            'type' => 'in',
            'qty_milli' => 10000,
            'unit_price_cents' => 1500000, // Cost price
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]);
        $this->ledger->record($inMovement);

        Change::create([
            'shop_id' => $this->shop->id,
            'device_id' => $this->ownerDevice->id,
            'entity' => 'product',
            'entity_id' => $this->product->id,
        ]);

        // Salesperson pulls changes (lacks cost.view)
        $pullRes = $this->sync->sync($salesDevice, ['cursor' => 0, 'changes' => []], 100);

        $prodChange = null;
        $inChange = null;
        foreach ($pullRes['changes'] as $c) {
            if ($c['entity'] === 'product' && $c['id'] === $this->product->id) {
                $prodChange = $c;
            } elseif ($c['entity'] === 'movement' && $c['id'] === $inMovement->id) {
                $inChange = $c;
            }
        }

        $this->assertNotNull($prodChange);
        $this->assertNotNull($inChange);

        // Assert cost_price_cents is nulled out on product
        $this->assertNull($prodChange['data']['cost_price_cents']);
        $this->assertSame(1800000, $prodChange['data']['sell_price_cents']);

        // Assert unit_price_cents is nulled out on in movement
        $this->assertNull($inChange['data']['unit_price_cents']);

        // Owner pulls changes (has cost.view) -> costs are present
        $ownerPull = $this->sync->sync($this->ownerDevice, ['cursor' => 0, 'changes' => []], 100);
        $ownerInChange = null;
        foreach ($ownerPull['changes'] as $c) {
            if ($c['entity'] === 'movement' && $c['id'] === $inMovement->id) {
                $ownerInChange = $c;
            }
        }
        $this->assertNotNull($ownerInChange);
        $this->assertSame(1500000, $ownerInChange['data']['unit_price_cents']);
    }

    /**
     * 6. Test product view stripping when user lacks products.view permission.
     */
    public function test_product_view_stripping_when_user_lacks_products_view(): void
    {
        $blindUser = User::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'telegram_id' => 999909,
            'name' => 'Blind User',
            'role' => 'custom', // empty permissions
        ]);

        $token = 'blind-token';
        $blindDevice = Device::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $blindUser->id,
            'name' => 'Blind Tablet',
            'token_hash' => hash('sha256', $token),
        ]);

        Change::create([
            'shop_id' => $this->shop->id,
            'device_id' => $this->ownerDevice->id,
            'entity' => 'product',
            'entity_id' => $this->product->id,
        ]);

        $pull = $this->sync->sync($blindDevice, ['cursor' => 0, 'changes' => []], 100);
        $this->assertEmpty($pull['changes'], 'User without products.view must receive zero product changes');
        foreach ($pull['changes'] as $c) {
            $this->assertNotSame('product', $c['entity'], 'User without products.view must receive zero product changes');
        }
    }

    /**
     * 7. Test pull filtering by location and transfer visibility.
     */
    public function test_pull_filtering_by_location_and_transfer_visibility(): void
    {
        $salesperson = User::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'telegram_id' => 999910,
            'name' => 'Store Only Sales',
            'role' => 'salesperson',
        ]);
        $this->permissionService->updatePermissions($salesperson, [], [$this->storeLoc->id]);

        $token = 'store-sales-tok';
        $device = Device::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $salesperson->id,
            'name' => 'Store Phone',
            'token_hash' => hash('sha256', $token),
        ]);

        // 1. Movement at warehouse (unrelated sale)
        $warehouseMovement = new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouseLoc->id,
            'type' => 'sale',
            'qty_milli' => -1000,
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]);
        $this->ledger->record($warehouseMovement);

        // 2. Transfer from warehouse to store (touches salesperson's store location!)
        $transferRes = $this->ledger->transfer(
            product: $this->product,
            fromLocation: $this->warehouseLoc,
            toLocation: $this->storeLoc,
            qty: '5.000',
            user: $this->owner
        );

        $outMovementId = $transferRes['out']->id;

        $pull = $this->sync->sync($device, ['cursor' => 0, 'changes' => []], 100);
        $pulledMovementIds = array_column(
            array_filter($pull['changes'], fn ($c) => $c['entity'] === 'movement'),
            'id'
        );

        // Warehouse sale should NOT be in pulled changes
        $this->assertNotContains($warehouseMovement->id, $pulledMovementIds);

        // Transfer out touching store location MUST be in pulled changes
        $this->assertContains($outMovementId, $pulledMovementIds);
    }

    /**
     * 8. Test review queue approval and dismissal of rejected changes.
     */
    public function test_review_queue_approval_and_dismissal(): void
    {
        $salesperson = User::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'telegram_id' => 999911,
            'name' => 'Review Queue Sales',
            'role' => 'salesperson',
        ]);
        $this->permissionService->updatePermissions($salesperson, [], [$this->storeLoc->id]);

        $token = 'rq-token';
        $device = Device::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $salesperson->id,
            'name' => 'RQ Tablet',
            'token_hash' => hash('sha256', $token),
        ]);

        // Attempt stock.in (forbidden for salesperson)
        $changeId = (string) Str::uuid7();
        $payload = [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $changeId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'data' => [
                        'id' => $changeId,
                        'product_id' => $this->product->id,
                        'location_id' => $this->storeLoc->id,
                        'type' => 'in',
                        'qty' => '50.000',
                        'unit_price_cents' => 1500000,
                    ],
                ],
            ],
        ];

        $res = $this->sync->sync($device, $payload, 1000);
        $this->assertCount(1, $res['rejected']);
        $this->assertSame('forbidden_permission', $res['rejected'][0]['reason']);

        // Check rejection queue via API
        $listRes = $this->withToken($this->ownerToken)->getJson('/v1/rejected-changes');
        $listRes->assertStatus(200);
        $this->assertCount(1, $listRes->json('rejected_changes'));
        $rcId = $listRes->json('rejected_changes.0.id');

        // Non-owner cannot approve
        $this->withToken($token)->postJson("/v1/rejected-changes/{$rcId}/approve")->assertStatus(403);

        // Owner approves
        $approveRes = $this->withToken($this->ownerToken)->postJson("/v1/rejected-changes/{$rcId}/approve");
        $approveRes->assertStatus(200);
        $approveRes->assertJson(['success' => true]);

        // Verify movement was applied as the original user
        $appliedMovement = Movement::withoutGlobalScope(ShopScope::class)->find($changeId);
        $this->assertNotNull($appliedMovement);
        $this->assertSame($salesperson->id, $appliedMovement->user_id);
        $this->assertSame(50000, $appliedMovement->qty_milli);

        // Verify status is approved
        $this->assertDatabaseHas('rejected_changes', [
            'id' => $rcId,
            'status' => 'approved',
            'reviewed_by' => $this->owner->id,
        ]);
    }

    /**
     * 9. Test upload-only sync for revoked device within 7 days.
     */
    public function test_upload_only_sync_for_revoked_device_within_7_days(): void
    {
        $salesperson = User::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'telegram_id' => 999912,
            'name' => 'Revoked User',
            'role' => 'salesperson',
        ]);
        $this->permissionService->updatePermissions($salesperson, [], [$this->storeLoc->id]);

        $token = 'revoked-device-tok';
        $revokedAt = now()->subDays(2);
        $revokedAtMs = (int) $revokedAt->getTimestampMs();

        // Ensure permission snapshot was effective before the offline work
        PermissionLog::where('user_id', $salesperson->id)->update([
            'effective_from_ms' => $revokedAtMs - 50000,
        ]);

        $device = Device::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $salesperson->id,
            'name' => 'Revoked Phone',
            'token_hash' => hash('sha256', $token),
            'revoked_at' => $revokedAt,
            'revoked_sync_used' => false,
        ]);

        // Attempting normal GET /v1/me or GET /v1/devices -> 401
        $this->withToken($token)->getJson('/v1/me')->assertStatus(401);
        $this->withToken($token)->getJson('/v1/devices')->assertStatus(401);

        // Work created BEFORE revocation (t = revokedAtMs - 10000)
        $validSaleId = (string) Str::uuid7();
        $preRevocationTime = $revokedAtMs - 10000;

        // Work created AFTER revocation (t = revokedAtMs + 10000)
        $invalidSaleId = (string) Str::uuid7();
        $postRevocationTime = $revokedAtMs + 10000;

        $payload = [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $validSaleId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => $preRevocationTime,
                    'data' => [
                        'id' => $validSaleId,
                        'product_id' => $this->product->id,
                        'location_id' => $this->storeLoc->id,
                        'type' => 'sale',
                        'qty' => '1.000',
                        'unit_price_cents' => 1800000,
                        'created_at' => $preRevocationTime,
                    ],
                ],
                [
                    'id' => $invalidSaleId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => $postRevocationTime,
                    'data' => [
                        'id' => $invalidSaleId,
                        'product_id' => $this->product->id,
                        'location_id' => $this->storeLoc->id,
                        'type' => 'sale',
                        'qty' => '1.000',
                        'unit_price_cents' => 1800000,
                        'created_at' => $postRevocationTime,
                    ],
                ],
            ],
        ];

        // First sync: Upload-only allowed!
        $syncRes = $this->withToken($token)->postJson('/v1/sync', $payload);
        $syncRes->assertStatus(200);

        // Pre-revocation accepted, post-revocation rejected
        $syncRes->assertJsonFragment(['accepted' => [$validSaleId]]);
        $syncRes->assertJsonFragment(['id' => $invalidSaleId]);

        // Pull is completely blocked (changes is empty)
        $this->assertEmpty($syncRes->json('changes'));

        // Device is now marked revoked_sync_used = true
        $this->assertTrue($device->fresh()->revoked_sync_used);

        // Second sync attempt: Must be rejected with 401
        $secondSyncRes = $this->withToken($token)->postJson('/v1/sync', ['cursor' => 0, 'changes' => []]);
        $secondSyncRes->assertStatus(401);
    }

    /**
     * 10. Test last owner protection against demotion and deactivation.
     */
    public function test_last_owner_protection(): void
    {
        // Owner cannot be demoted
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot demote or remove the last active owner of the shop.');
        $this->permissionService->updatePermissions($this->owner, newRole: 'salesperson');
    }

    public function test_last_owner_cannot_be_deactivated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot deactivate or remove the last active owner of the shop.');
        $this->permissionService->deactivateUser($this->owner);
    }

    /**
     * 11. Test double-confirmed ownership transfer.
     */
    public function test_double_confirmed_ownership_transfer(): void
    {
        $newOwner = User::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'telegram_id' => 999913,
            'name' => 'Yonas (Successor)',
            'role' => 'salesperson',
        ]);

        // Step 1: Initiate transfer -> receives confirmation code
        $step1 = $this->permissionService->transferOwnership($this->shop, $this->owner, $newOwner, false);
        $this->assertFalse($step1['confirmed']);
        $this->assertNotEmpty($step1['confirmation_code']);
        $code = $step1['confirmation_code'];

        // Invalid code throws exception
        try {
            $this->permissionService->transferOwnership($this->shop, $this->owner, $newOwner, true, 'WRONG6');
            $this->fail('Expected InvalidArgumentException on wrong confirmation code.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid or expired', $e->getMessage());
        }

        // Step 2: Confirm transfer with valid code
        $step2 = $this->permissionService->transferOwnership($this->shop, $this->owner, $newOwner, true, $code);
        $this->assertTrue($step2['confirmed']);

        // Verify roles swapped
        $this->assertSame('owner', $newOwner->fresh()->role);
        $this->assertSame('salesperson', $this->owner->fresh()->role);

        // Verify snapshot logs appended
        $this->assertDatabaseHas('permission_log', [
            'user_id' => $newOwner->id,
            'changed_by' => $this->owner->id,
        ]);
    }

    /**
     * 12. Test GET /v1/me and sync response perm_version.
     */
    public function test_me_endpoint_and_sync_response_perm_version(): void
    {
        $res = $this->withToken($this->ownerToken)->getJson('/v1/me');
        $res->assertStatus(200);
        $res->assertJsonStructure([
            'user' => ['id', 'name', 'role', 'perm_version'],
            'role',
            'permissions',
            'location_ids',
            'perm_version',
        ]);
        $this->assertSame('owner', $res->json('role'));
        $this->assertSame(1, $res->json('perm_version'));

        // Sync response also has perm_version
        $syncRes = $this->withToken($this->ownerToken)->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [],
        ]);
        $syncRes->assertStatus(200);
        $syncRes->assertJsonStructure(['accepted', 'rejected', 'changes', 'cursor', 'perm_version']);
        $this->assertSame(1, $syncRes->json('perm_version'));
    }
}
