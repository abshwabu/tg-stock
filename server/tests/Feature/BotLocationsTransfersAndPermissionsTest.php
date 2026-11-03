<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Context\CurrentShopContext;
use App\Jobs\SendLowStockAlertJob;
use App\Models\Device;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Product;
use App\Models\RejectedChange;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserLocation;
use App\Models\UserPermission;
use App\Scopes\ShopScope;
use App\Services\LedgerService;
use App\Services\PermissionService;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\Handlers\LocationHandler;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BotLocationsTransfersAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected string $secretToken = 'sec-token-locs-123';
    protected Shop $shop;
    protected Location $storeLoc;
    protected Location $warehouseLoc;
    protected User $owner;
    protected User $salesperson;
    protected User $warehouseKeeper;
    protected Product $product;
    protected LedgerService $ledger;
    protected PermissionService $permissionService;
    protected BotStateService $botState;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.telegram.bot_token', '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11');
        Config::set('services.telegram.webhook_secret', $this->secretToken);

        Http::fake([
            'https://api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 101]], 200),
            'https://api.telegram.org/bot*/editMessageText' => Http::response(['ok' => true, 'result' => ['message_id' => 101]], 200),
            'https://api.telegram.org/bot*/answerCallbackQuery' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $this->ledger = app(LedgerService::class);
        $this->permissionService = app(PermissionService::class);
        $this->botState = app(BotStateService::class);

        // Create Shop
        $this->shop = Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Addis General Store',
            'currency' => 'ETB',
            'language' => 'en',
            'low_stock_default_milli' => 5000,
            'allow_negative_stock' => false,
            'transfer_requires_receipt' => false,
        ]);

        CurrentShopContext::runWith($this->shop, function (): void {
            // Default Store Location
            $this->storeLoc = $this->shop->getDefaultLocation();
            $this->storeLoc->name = 'Store';
            $this->storeLoc->kind = 'store';
            $this->storeLoc->save();

            // Warehouse Location
            $this->warehouseLoc = Location::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'name' => 'Warehouse',
                'kind' => 'warehouse',
                'is_default' => false,
                'is_active' => true,
            ]);

            // Owner User
            $this->owner = User::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'telegram_id' => 900001,
                'name' => 'Owner Abebe',
                'language' => 'en',
                'shop_id' => $this->shop->id,
                'role' => 'owner',
                'is_active' => true,
            ]);

            // Salesperson User
            $this->salesperson = User::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'telegram_id' => 900002,
                'name' => 'Salesperson Almaz',
                'language' => 'en',
                'shop_id' => $this->shop->id,
                'role' => 'salesperson',
                'is_active' => true,
            ]);

            // Warehouse Keeper User
            $this->warehouseKeeper = User::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'telegram_id' => 900003,
                'name' => 'Keeper Chala',
                'language' => 'en',
                'shop_id' => $this->shop->id,
                'role' => 'warehouse_keeper',
                'is_active' => true,
            ]);

            // Standard Product
            $this->product = Product::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'name' => 'Coffee (ቡና)',
                'unit' => 'kg',
                'sell_price_cents' => 35000, // 350 ETB
                'cost_price_cents' => 28000, // 280 ETB
                'low_stock_at_milli' => 5000,
                'is_active' => true,
            ]);
        });
    }

    /**
     * Helper to send webhook updates.
     */
    protected function postUpdate(array $payload)
    {
        return $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', $payload);
    }

    /**
     * 1. Test location switcher visibility and switching.
     */
    public function test_location_switcher_visibility_and_switching(): void
    {
        // Case A: Owner has access to multiple locations -> sees switcher
        $ownerKeyboard = TelegramUi::mainMenuKeyboard('en', $this->owner);
        $this->assertNotEmpty($ownerKeyboard['keyboard']);
        $topRowText = $ownerKeyboard['keyboard'][0][0]['text'] ?? '';
        $this->assertStringContainsString('📍', $topRowText);
        $this->assertStringContainsString('▾', $topRowText);

        // Case B: Restrict Salesperson to a single location (Store only)
        UserLocation::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->salesperson->id,
            'location_id' => $this->storeLoc->id,
        ]);

        $salesKeyboard = TelegramUi::mainMenuKeyboard('en', $this->salesperson);
        $salesTopText = $salesKeyboard['keyboard'][0][0]['text'] ?? '';
        // Single-location users NEVER see the switcher
        $this->assertStringNotContainsString('▾', $salesTopText);
        $this->assertStringNotContainsString('📍', $salesTopText);

        // Case C: Owner taps switcher menu via command /switch
        $this->postUpdate([
            'update_id' => 1001,
            'message' => [
                'chat' => ['id' => 900001],
                'text' => '/switch',
            ],
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['text'], 'Switch Location');
        });

        // Case D: Owner selects Warehouse location via callback: loc:set:locId
        $this->postUpdate([
            'update_id' => 1002,
            'callback_query' => [
                'id' => 'cq_switch_wh',
                'message' => ['chat' => ['id' => 900001], 'message_id' => 50],
                'data' => "loc:set:{$this->warehouseLoc->id}",
            ],
        ])->assertStatus(200);

        $locHandler = app(LocationHandler::class);
        $currentLoc = $locHandler->getCurrentLocation($this->owner);
        $this->assertSame($this->warehouseLoc->id, $currentLoc->id);
    }

    /**
     * 2. Test instant transfer flow (transfer_requires_receipt = false).
     */
    public function test_instant_transfer_flow(): void
    {
        $this->shop->transfer_requires_receipt = false;
        $this->shop->save();

        // Give Store 20 kg of Coffee
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $this->product->id,
            'location_id' => $this->storeLoc->id,
            'type' => 'in',
            'qty_milli' => 20000,
            'unit_price_cents' => 28000,
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]));

        $this->assertSame(20000, $this->ledger->stockOf($this->product, $this->storeLoc));
        $this->assertSame(0, $this->ledger->stockOf($this->product, $this->warehouseLoc));

        $chatId = 900001; // Owner

        // Step 1: Start transfer via text menu /transfer
        $this->postUpdate([
            'update_id' => 2001,
            'message' => ['chat' => ['id' => $chatId], 'text' => '🚚 Transfer'],
        ])->assertStatus(200);

        // Step 2: Select From Location (Store)
        $this->postUpdate([
            'update_id' => 2002,
            'callback_query' => [
                'id' => 'cq_trans_from',
                'message' => ['chat' => ['id' => $chatId], 'message_id' => 10],
                'data' => "trans:from:{$this->storeLoc->id}",
            ],
        ])->assertStatus(200);

        // Step 3: Select To Location (Warehouse)
        $this->postUpdate([
            'update_id' => 2003,
            'callback_query' => [
                'id' => 'cq_trans_to',
                'message' => ['chat' => ['id' => $chatId], 'message_id' => 11],
                'data' => "trans:to:{$this->warehouseLoc->id}",
            ],
        ])->assertStatus(200);

        // Step 4: Select Product
        $this->postUpdate([
            'update_id' => 2004,
            'callback_query' => [
                'id' => 'cq_trans_prod',
                'message' => ['chat' => ['id' => $chatId], 'message_id' => 12],
                'data' => "trans:prod:{$this->product->id}",
            ],
        ])->assertStatus(200);

        // Step 5: Input Quantity: 5
        $this->postUpdate([
            'update_id' => 2005,
            'message' => ['chat' => ['id' => $chatId], 'text' => '5'],
        ])->assertStatus(200);

        // Step 6: Confirm Transfer
        $this->postUpdate([
            'update_id' => 2006,
            'callback_query' => [
                'id' => 'cq_trans_confirm',
                'message' => ['chat' => ['id' => $chatId], 'message_id' => 13],
                'data' => 'trans:confirm:0',
            ],
        ])->assertStatus(200);

        // Verify stock balances: Store has 15 kg, Warehouse has 5 kg
        $this->assertSame(15000, $this->ledger->stockOf($this->product, $this->storeLoc));
        $this->assertSame(5000, $this->ledger->stockOf($this->product, $this->warehouseLoc));
        $this->assertSame(20000, $this->ledger->stockOf($this->product)); // Total unchanged

        // Verify ledger movements created
        $out = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('type', 'transfer_out')
            ->first();
        $this->assertNotNull($out);
        $this->assertSame(-5000, $out->qty_milli);

        $in = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('type', 'transfer_in')
            ->first();
        $this->assertNotNull($in);
        $this->assertSame(5000, $in->qty_milli);
        $this->assertSame($out->transfer_id, $in->transfer_id);
    }

    /**
     * 3. Test two-step transfer flow with receipt confirmation and discrepancy alert.
     */
    public function test_two_step_transfer_flow_with_confirmation_and_discrepancy_alert(): void
    {
        $this->shop->transfer_requires_receipt = true;
        $this->shop->save();

        // Restrict warehouse keeper to warehouse location
        UserLocation::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $this->warehouseKeeper->id,
            'location_id' => $this->warehouseLoc->id,
        ]);

        // Stock Store with 30 kg
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $this->product->id,
            'location_id' => $this->storeLoc->id,
            'type' => 'in',
            'qty_milli' => 30000,
            'unit_price_cents' => 28000,
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]));

        $chatId = 900001; // Owner

        // Transfer 10 kg from Store to Warehouse
        $transferRes = $this->ledger->transfer(
            product: $this->product,
            fromLocation: $this->storeLoc,
            toLocation: $this->warehouseLoc,
            qty: '10.000',
            user: $this->owner
        );

        $transferId = $transferRes['transfer_id'];
        $this->assertSame('in_transit', $transferRes['status']);

        // Assert 1 transfer currently in transit
        $transits = $this->ledger->inTransit($this->shop);
        $this->assertCount(1, $transits);
        $this->assertSame($transferId, $transits->first()['transfer_id']);

        // Destination receiver taps [✏️ Different qty]
        $receiverChatId = 900003; // Warehouse Keeper Chala
        $this->postUpdate([
            'update_id' => 3001,
            'callback_query' => [
                'id' => 'cq_diff_qty',
                'message' => ['chat' => ['id' => $receiverChatId], 'message_id' => 88],
                'data' => "trans:diff_p:{$transferId}",
            ],
        ])->assertStatus(200);

        // Receiver enters actual received quantity: 8 (missing 2)
        $this->postUpdate([
            'update_id' => 3002,
            'message' => ['chat' => ['id' => $receiverChatId], 'text' => '8'],
        ])->assertStatus(200);

        // In-transit should now be resolved
        $this->assertCount(0, $this->ledger->inTransit($this->shop));

        // Warehouse received stock should be 8.000
        $this->assertSame(8000, $this->ledger->stockOf($this->product, $this->warehouseLoc));

        // Transfer discrepancy should be recorded
        $discrepancies = $this->ledger->discrepancies($this->shop);
        $this->assertCount(1, $discrepancies);
        $disc = $discrepancies->first();
        $this->assertSame(10000, $disc['sent_qty_milli']);
        $this->assertSame(8000, $disc['received_qty_milli']);
        $this->assertSame(-2000, $disc['difference_milli']);

        // Owner must have received discrepancy alert message on Telegram
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['text'], 'TRANSFER DISCREPANCY ALERT')
                && str_contains($request['text'], '10.000')
                && str_contains($request['text'], '8.000');
        });
    }

    /**
     * 4. Test product card shows stock per location and in transit.
     */
    public function test_product_card_shows_stock_per_location_and_in_transit(): void
    {
        // 12 at Store, 6 at Warehouse
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $this->product->id,
            'location_id' => $this->storeLoc->id,
            'type' => 'in',
            'qty_milli' => 12000,
            'unit_price_cents' => 28000,
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]));

        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouseLoc->id,
            'type' => 'in',
            'qty_milli' => 6000,
            'unit_price_cents' => 28000,
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]));

        // Put 2 kg in transit from Store
        $this->shop->transfer_requires_receipt = true;
        $this->shop->save();

        $this->ledger->transfer(
            product: $this->product,
            fromLocation: $this->storeLoc,
            toLocation: $this->warehouseLoc,
            qty: '2.000',
            user: $this->owner
        );

        // View product card
        $this->postUpdate([
            'update_id' => 4001,
            'callback_query' => [
                'id' => 'cq_view_card',
                'message' => ['chat' => ['id' => 900001], 'message_id' => 20],
                'data' => "prod:view:{$this->product->id}",
            ],
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'editMessageText')
                && str_contains($request['text'], 'Store:')
                && str_contains($request['text'], 'Warehouse:')
                && str_contains($request['text'], 'In transit:');
        });
    }

    /**
     * 5. Test permission editor round trip and member notification.
     */
    public function test_permission_editor_round_trip(): void
    {
        $ownerChatId = 900001;
        $targetUserId = $this->salesperson->id;

        // Step 1: Start Permission Editor
        $this->postUpdate([
            'update_id' => 5001,
            'callback_query' => [
                'id' => 'cq_ped_start',
                'message' => ['chat' => ['id' => $ownerChatId], 'message_id' => 30],
                'data' => "team:ped_start:{$targetUserId}",
            ],
        ])->assertStatus(200);

        // Step 2: Select role preset 'warehouse_keeper'
        $this->postUpdate([
            'update_id' => 5002,
            'callback_query' => [
                'id' => 'cq_ped_role',
                'message' => ['chat' => ['id' => $ownerChatId], 'message_id' => 30],
                'data' => "team:ped_role:{$targetUserId}:warehouse_keeper",
            ],
        ])->assertStatus(200);

        // Step 3: Toggle feature 'cost.view' (flip from deny to allow)
        $this->postUpdate([
            'update_id' => 5003,
            'callback_query' => [
                'id' => 'cq_ped_tog',
                'message' => ['chat' => ['id' => $ownerChatId], 'message_id' => 30],
                'data' => "team:ped_tog:{$targetUserId}:cost.view:1",
            ],
        ])->assertStatus(200);

        // Step 4: Save permissions
        $this->postUpdate([
            'update_id' => 5004,
            'callback_query' => [
                'id' => 'cq_ped_save',
                'message' => ['chat' => ['id' => $ownerChatId], 'message_id' => 30],
                'data' => "team:ped_save:{$targetUserId}",
            ],
        ])->assertStatus(200);

        // Assert member in DB has new role
        $this->salesperson->refresh();
        $this->assertSame('warehouse_keeper', $this->salesperson->role);

        // Assert cost.view override was saved as 'allow'
        $override = UserPermission::where('user_id', $this->salesperson->id)
            ->where('permission', 'cost.view')
            ->first();
        $this->assertNotNull($override);
        $this->assertSame('allow', $override->effect);

        // Assert member was notified on Telegram
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && (int) $request['chat_id'] === 900002
                && str_contains($request['text'], 'Your shop permissions have been updated');
        });
    }

    /**
     * 6. Test forbidden callback is rejected even if the button is forged.
     */
    public function test_forbidden_callback_is_rejected_even_if_button_is_forged(): void
    {
        $salesChatId = 900002; // Salesperson without team.manage, stock.adjust, price.edit, locations.manage

        // 1. Forged adjust callback: adj:start:<id>
        $this->postUpdate([
            'update_id' => 6001,
            'callback_query' => [
                'id' => 'cq_forged_adj',
                'message' => ['chat' => ['id' => $salesChatId], 'message_id' => 40],
                'data' => "adj:start:{$this->product->id}",
            ],
        ])->assertStatus(200);

        // 2. Forged permission editor callback: team:ped_start:<id>
        $this->postUpdate([
            'update_id' => 6002,
            'callback_query' => [
                'id' => 'cq_forged_team',
                'message' => ['chat' => ['id' => $salesChatId], 'message_id' => 41],
                'data' => "team:ped_start:{$this->owner->id}",
            ],
        ])->assertStatus(200);

        // 3. Forged location receipt toggle: loc:toggle:receipt
        $this->postUpdate([
            'update_id' => 6003,
            'callback_query' => [
                'id' => 'cq_forged_loc',
                'message' => ['chat' => ['id' => $salesChatId], 'message_id' => 42],
                'data' => 'loc:toggle:receipt:0',
            ],
        ])->assertStatus(200);

        // 4. Forged price edit: prod:price:<id>
        $this->postUpdate([
            'update_id' => 6004,
            'callback_query' => [
                'id' => 'cq_forged_price',
                'message' => ['chat' => ['id' => $salesChatId], 'message_id' => 43],
                'data' => "prod:price:{$this->product->id}",
            ],
        ])->assertStatus(200);

        // Assert answerCallbackQuery sent with alert
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'answerCallbackQuery')
                && str_contains($request['text'] ?? '', 'permission');
        });
    }

    /**
     * 7. Test low-stock alert names the specific location.
     */
    public function test_low_stock_alert_names_location(): void
    {
        SendLowStockAlertJob::dispatch(
            $this->product->id,
            2000, // 2 kg remaining (below 5 kg threshold)
            $this->shop->id,
            $this->warehouseLoc->id
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['text'], 'LOW STOCK WARNING')
                && str_contains($request['text'], 'Warehouse');
        });
    }

    /**
     * 8. Test owner review queue: approve and dismiss rejected offline changes.
     */
    public function test_owner_review_queue_approve_and_dismiss(): void
    {
        $device = Device::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $this->salesperson->id,
            'name' => 'Sales POS Phone',
            'token_hash' => hash('sha256', 'pos-token-123'),
        ]);

        $changeA = RejectedChange::create([
            'id' => (string) Str::uuid7(),
            'change_id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $this->salesperson->id,
            'device_id' => $device->id,
            'entity' => 'product',
            'op' => 'create',
            'change_data' => ['name' => 'Offline Flour', 'sell_price_cents' => 9500],
            'reason' => 'permission_denied: products.create',
            'status' => 'pending',
            'client_ts' => (int) (microtime(true) * 1000),
        ]);

        $changeB = RejectedChange::create([
            'id' => (string) Str::uuid7(),
            'change_id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $this->salesperson->id,
            'device_id' => $device->id,
            'entity' => 'movement',
            'op' => 'create',
            'change_data' => ['type' => 'adjust', 'qty' => '1.000'],
            'reason' => 'permission_denied: stock.adjust',
            'status' => 'pending',
            'client_ts' => (int) (microtime(true) * 1000),
        ]);

        $ownerChatId = 900001;

        // View review queue via command /review
        $this->postUpdate([
            'update_id' => 7001,
            'message' => ['chat' => ['id' => $ownerChatId], 'text' => '/review'],
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['text'], 'Needs Review');
        });

        // Approve Change A
        $this->postUpdate([
            'update_id' => 7002,
            'callback_query' => [
                'id' => 'cq_app_a',
                'message' => ['chat' => ['id' => $ownerChatId], 'message_id' => 77],
                'data' => "rq:app:{$changeA->id}",
            ],
        ])->assertStatus(200);

        $changeA->refresh();
        $this->assertSame('approved', $changeA->status);
        $this->assertSame($this->owner->id, $changeA->reviewed_by);

        // Dismiss Change B
        $this->postUpdate([
            'update_id' => 7003,
            'callback_query' => [
                'id' => 'cq_dsm_b',
                'message' => ['chat' => ['id' => $ownerChatId], 'message_id' => 77],
                'data' => "rq:dsm:{$changeB->id}",
            ],
        ])->assertStatus(200);

        $changeB->refresh();
        $this->assertSame('dismissed', $changeB->status);
        $this->assertSame($this->owner->id, $changeB->reviewed_by);
    }

    /**
     * 9. Test reports menu and location-filtered period reports.
     */
    public function test_reports_location_filtering_and_special_reports(): void
    {
        $ownerChatId = 900001;

        // 1. Stock by location report: rep:loc:stock
        $this->postUpdate([
            'update_id' => 8001,
            'callback_query' => [
                'id' => 'cq_rep_stock',
                'message' => ['chat' => ['id' => $ownerChatId], 'message_id' => 60],
                'data' => 'rep:loc:stock:0',
            ],
        ])->assertStatus(200);

        // 2. In-transit report: rep:loc:transit
        $this->postUpdate([
            'update_id' => 8002,
            'callback_query' => [
                'id' => 'cq_rep_trans',
                'message' => ['chat' => ['id' => $ownerChatId], 'message_id' => 60],
                'data' => 'rep:loc:transit:0',
            ],
        ])->assertStatus(200);

        // 3. Discrepancies report: rep:loc:discrepancies
        $this->postUpdate([
            'update_id' => 8003,
            'callback_query' => [
                'id' => 'cq_rep_disc',
                'message' => ['chat' => ['id' => $ownerChatId], 'message_id' => 60],
                'data' => 'rep:loc:discrepancies:0',
            ],
        ])->assertStatus(200);

        // 4. Period report with location filter: rep:p:today:storeId
        $this->postUpdate([
            'update_id' => 8004,
            'callback_query' => [
                'id' => 'cq_rep_p_store',
                'message' => ['chat' => ['id' => $ownerChatId], 'message_id' => 60],
                'data' => "rep:p:today:{$this->storeLoc->id}",
            ],
        ])->assertStatus(200);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'editMessageText')
                && str_contains($request['text'], 'Sales Report: Today')
                && str_contains($request['text'], 'Store');
        });
    }
}
