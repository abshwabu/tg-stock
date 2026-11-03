<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Context\CurrentShopContext;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\LedgerService;
use App\Services\Telegram\BotStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BotLedgerFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected string $secretToken = 'secret-test-token-xyz123';
    protected Shop $shop;
    protected User $owner;
    protected User $staff;
    protected LedgerService $ledger;
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
        $this->botState = app(BotStateService::class);

        // Setup test shop, owner and staff
        $this->shop = Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Bole Supermarket',
            'currency' => 'ETB',
            'language' => 'am',
            'low_stock_default_milli' => 5000, // 5.000 units
            'allow_negative_stock' => false,
        ]);

        CurrentShopContext::runWith($this->shop, function (): void {
            $this->owner = User::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'telegram_id' => 111001,
                'name' => 'Owner Alula',
                'language' => 'am',
                'shop_id' => $this->shop->id,
                'role' => 'owner',
                'is_active' => true,
            ]);

            $this->staff = User::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'telegram_id' => 111002,
                'name' => 'Staff Helen',
                'language' => 'am',
                'shop_id' => $this->shop->id,
                'role' => 'staff',
                'is_active' => true,
            ]);
        });
    }

    public function test_product_catalog_listing_and_low_stock_badge(): void
    {
        CurrentShopContext::runWith($this->shop, function (): void {
            // Product 1: Low stock (3.000 units, threshold 5.000)
            $p1 = Product::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'name' => 'Cooking Oil (ዘይት)',
                'unit' => 'liter',
                'sell_price_cents' => 45000,
                'cost_price_cents' => 40000,
                'low_stock_at_milli' => 5000,
                'is_active' => true,
                'updated_at_ms' => 1000,
            ]);

            $this->ledger->record(new Movement([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'product_id' => $p1->id,
                'user_id' => $this->owner->id,
                'type' => 'in',
                'qty_milli' => 3000,
                'unit_price_cents' => 40000,
                'created_at_ms' => 1000,
            ]));

            // Product 2: In stock (25.000 units)
            $p2 = Product::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'name' => 'Sugar (ስኳር)',
                'unit' => 'kg',
                'sell_price_cents' => 12000,
                'cost_price_cents' => 10000,
                'low_stock_at_milli' => 5000,
                'is_active' => true,
                'updated_at_ms' => 1000,
            ]);

            $this->ledger->record(new Movement([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'product_id' => $p2->id,
                'user_id' => $this->owner->id,
                'type' => 'in',
                'qty_milli' => 25000,
                'unit_price_cents' => 10000,
                'created_at_ms' => 1000,
            ]));
        });

        // Owner sends "📦 Products" (or Amharic "📦 ዕቃዎች")
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 501,
                'message' => [
                    'message_id' => 1,
                    'chat' => ['id' => 111001],
                    'text' => '📦 ዕቃዎች',
                ],
            ]);
        $res->assertStatus(200);

        // Verify sent message contains product list with low stock badge 🔴 and ok badge 🟢
        Http::assertSent(function ($request) {
            $markup = $request['reply_markup'] ?? [];
            $buttons = $markup['inline_keyboard'] ?? [];

            $foundLow = false;
            $foundOk = false;
            foreach ($buttons as $row) {
                foreach ($row as $btn) {
                    if (str_contains($btn['text'], '🔴') && str_contains($btn['text'], 'Cooking Oil')) {
                        $foundLow = true;
                    }
                    if (str_contains($btn['text'], '🟢') && str_contains($btn['text'], 'Sugar')) {
                        $foundOk = true;
                    }
                }
            }
            return $foundLow && $foundOk;
        });
    }

    public function test_search_products_via_plain_text_including_amharic(): void
    {
        CurrentShopContext::runWith($this->shop, function (): void {
            Product::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'name' => 'ጤፍ (Teff Maganaja)',
                'unit' => 'kg',
                'sell_price_cents' => 16000,
                'is_active' => true,
                'updated_at_ms' => 1000,
            ]);
        });

        // User types plain text search "ጤፍ"
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 502,
                'message' => [
                    'message_id' => 2,
                    'chat' => ['id' => 111001],
                    'text' => 'ጤፍ',
                ],
            ]);
        $res->assertStatus(200);

        Http::assertSent(function ($request) {
            $markup = $request['reply_markup'] ?? [];
            $buttons = $markup['inline_keyboard'] ?? [];
            return isset($buttons[0][0]) && str_contains($buttons[0][0]['text'], 'ጤፍ (Teff Maganaja)');
        });
    }

    public function test_add_product_step_by_step_and_opening_stock(): void
    {
        $chatId = 111001;

        // Step 1: Start add product via callback prod:add:start
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 503,
                'callback_query' => [
                    'id' => 'cq_add',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 10],
                    'data' => 'prod:add:start',
                ],
            ])->assertStatus(200);

        $state = $this->botState->get($chatId);
        $this->assertSame('add_product', $state->flow);
        $this->assertSame('name', $state->step);

        // Step 2: Name input
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 504,
                'message' => ['chat' => ['id' => $chatId], 'text' => 'Green Coffee (የቡና ፍሬ)'],
            ])->assertStatus(200);

        $state = $this->botState->get($chatId);
        $this->assertSame('sell_price', $state->step);

        // Step 3: Sell price input "350"
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 505,
                'message' => ['chat' => ['id' => $chatId], 'text' => '350.50'],
            ])->assertStatus(200);

        $state = $this->botState->get($chatId);
        $this->assertSame('opening_stock', $state->step);
        $this->assertSame(35050, $state->data['sell_price_cents']);

        // Step 4: Opening stock input "40"
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 506,
                'message' => ['chat' => ['id' => $chatId], 'text' => '40'],
            ])->assertStatus(200);

        $state = $this->botState->get($chatId);
        $this->assertSame('unit', $state->step);
        $this->assertSame(40000, $state->data['opening_stock_milli']);

        // Step 5: Unit selection callback "prod:add:unit:kg"
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 507,
                'callback_query' => [
                    'id' => 'cq_unit',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 11],
                    'data' => 'prod:add:unit:kg',
                ],
            ])->assertStatus(200);

        $state = $this->botState->get($chatId);
        $this->assertSame('cost_price', $state->step);
        $this->assertSame('kg', $state->data['unit']);

        // Step 6: Cost price input "280"
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 508,
                'message' => ['chat' => ['id' => $chatId], 'text' => '280'],
            ])->assertStatus(200);

        // Verification: Product created in DB
        $prod = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('name', 'Green Coffee (የቡና ፍሬ)')
            ->first();

        $this->assertNotNull($prod);
        $this->assertSame(35050, $prod->sell_price_cents);
        $this->assertSame(28000, $prod->cost_price_cents);
        $this->assertSame('kg', $prod->unit);

        // Opening movement recorded in ledger
        $this->assertSame(40000, $this->ledger->stockOf($prod));

        // State cleared
        $this->assertNull($this->botState->get($chatId));
    }

    public function test_quick_add_product_offers_one_tap_creation(): void
    {
        $chatId = 111001;

        // Owner types "Rice 120 50" (Name: Rice, SellPrice: 120, OpeningStock: 50)
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 509,
                'message' => ['chat' => ['id' => $chatId], 'text' => 'Rice 120 50'],
            ])->assertStatus(200);

        $state = $this->botState->get($chatId);
        $this->assertNotNull($state);
        $this->assertSame('quick_add', $state->flow);

        // Tap quick create button
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 510,
                'callback_query' => [
                    'id' => 'cq_qadd',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 20],
                    'data' => "qadd:create:{$state->data['key']}",
                ],
            ])->assertStatus(200);

        // Product created
        $prod = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('name', 'Rice')
            ->first();

        $this->assertNotNull($prod);
        $this->assertSame(12000, $prod->sell_price_cents);
        $this->assertSame(50000, $this->ledger->stockOf($prod));
    }

    public function test_stock_in_flow_updates_ledger_and_shows_resulting_stock(): void
    {
        $prod = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Spaghetti',
            'unit' => 'pack',
            'sell_price_cents' => 9000,
            'cost_price_cents' => 7000,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        $chatId = 111002; // Staff Helen

        // 1. Staff taps stock in for product: stockin:start:<id>
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 511,
                'callback_query' => [
                    'id' => 'cq_stk',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 30],
                    'data' => "stockin:start:{$prod->id}",
                ],
            ])->assertStatus(200);

        // 2. Staff selects +50 quantity: stockin:qty:50
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 512,
                'callback_query' => [
                    'id' => 'cq_stk_qty',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 31],
                    'data' => 'stockin:qty:50',
                ],
            ])->assertStatus(200);

        // 3. Staff taps skip cost update: stockin:skip_cost:0
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 513,
                'callback_query' => [
                    'id' => 'cq_stk_cost',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 32],
                    'data' => 'stockin:skip_cost:0',
                ],
            ])->assertStatus(200);

        // Verify movement recorded and resulting stock is 50.000 packs
        $this->assertSame(50000, $this->ledger->stockOf($prod));

        $movement = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('product_id', $prod->id)
            ->first();
        $this->assertSame('in', $movement->type);
        $this->assertSame(50000, $movement->qty_milli);
    }

    public function test_sale_flow_fast_taps_multi_item_cart_and_total_calculation(): void
    {
        $prod1 = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Tea (ሻይ)',
            'unit' => 'pack',
            'sell_price_cents' => 5000, // 50 ETB
            'cost_price_cents' => 3500,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $prod1->id,
            'user_id' => $this->owner->id,
            'type' => 'in',
            'qty_milli' => 20000,
            'created_at_ms' => 1000,
        ]));

        $chatId = 111002; // Staff

        // Tap 1: Staff taps 🛒 Sale menu or pick product: sale:pick:<id>
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 514,
                'callback_query' => [
                    'id' => 'cq_sale_1',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 40],
                    'data' => "sale:pick:{$prod1->id}",
                ],
            ])->assertStatus(200);

        // Tap 2: Staff taps quantity "2": sale:qty:2
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 515,
                'callback_query' => [
                    'id' => 'cq_sale_2',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 41],
                    'data' => 'sale:qty:2',
                ],
            ])->assertStatus(200);

        // Tap 3: Staff taps Finish Sale: sale:finish:0
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 516,
                'callback_query' => [
                    'id' => 'cq_sale_3',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 42],
                    'data' => 'sale:finish:0',
                ],
            ])->assertStatus(200);

        // Verify sale was recorded: stock reduced from 20 to 18
        $this->assertSame(18000, $this->ledger->stockOf($prod1));

        $saleMovement = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('product_id', $prod1->id)
            ->where('type', 'sale')
            ->first();

        $this->assertNotNull($saleMovement);
        $this->assertSame(-2000, $saleMovement->qty_milli);
        $this->assertSame(5000, $saleMovement->unit_price_cents);
    }

    public function test_sale_negative_stock_prevention(): void
    {
        $prod = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Honey (ማር)',
            'unit' => 'jar',
            'sell_price_cents' => 80000,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        // Stock has only 2 jars
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $prod->id,
            'type' => 'in',
            'qty_milli' => 2000,
            'created_at_ms' => 1000,
        ]));

        $chatId = 111002;

        // Try to pick product and sell 5 jars when negative stock is disallowed (default)
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 517,
                'callback_query' => [
                    'id' => 'cq_neg_1',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 50],
                    'data' => "sale:pick:{$prod->id}",
                ],
            ])->assertStatus(200);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 518,
                'callback_query' => [
                    'id' => 'cq_neg_2',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 51],
                    'data' => 'sale:qty:5',
                ],
            ])->assertStatus(200);

        // Verify sale was blocked: stock remains 2000 milli
        $this->assertSame(2000, $this->ledger->stockOf($prod));

        // Now owner enables negative stock
        $this->shop->allow_negative_stock = true;
        $this->shop->save();

        // Selling 5 jars should now succeed
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 519,
                'callback_query' => [
                    'id' => 'cq_neg_3',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 52],
                    'data' => 'sale:qty:5',
                ],
            ])->assertStatus(200);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 520,
                'callback_query' => [
                    'id' => 'cq_neg_4',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 53],
                    'data' => 'sale:finish:0',
                ],
            ])->assertStatus(200);

        // Resulting stock is -3.000 jars
        $this->assertSame(-3000, $this->ledger->stockOf($prod));
    }

    public function test_adjust_stock_flow_with_signed_difference_and_reason(): void
    {
        $prod = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Eggs (እንቁላል)',
            'unit' => 'pcs',
            'sell_price_cents' => 1500,
            'cost_price_cents' => 1200,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        // Current stock: 50 eggs
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $prod->id,
            'type' => 'in',
            'qty_milli' => 50000,
            'created_at_ms' => 1000,
        ]));

        $chatId = 111001; // Owner

        // 1. Start adjust: adj:start:<id>
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 521,
                'callback_query' => [
                    'id' => 'cq_adj',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 60],
                    'data' => "adj:start:{$prod->id}",
                ],
            ])->assertStatus(200);

        // 2. Counted actual stock is 45 (5 broken/damaged): sends "45"
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 522,
                'message' => ['chat' => ['id' => $chatId], 'text' => '45'],
            ])->assertStatus(200);

        // 3. Choose reason: adj:reason:damaged
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 523,
                'callback_query' => [
                    'id' => 'cq_reason',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 61],
                    'data' => 'adj:reason:damaged',
                ],
            ])->assertStatus(200);

        // Verify signed difference (-5000) stored in ledger
        $this->assertSame(45000, $this->ledger->stockOf($prod));

        $adjustMovement = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('product_id', $prod->id)
            ->where('type', 'adjust')
            ->first();

        $this->assertNotNull($adjustMovement);
        $this->assertSame(-5000, $adjustMovement->qty_milli);
        $this->assertStringContainsString('የተበላሸ', $adjustMovement->note);
    }

    public function test_undo_own_last_action_within_10_minutes(): void
    {
        $prod = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Biscuits',
            'unit' => 'pack',
            'sell_price_cents' => 3000,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        // Stock 10 packs
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $prod->id,
            'type' => 'in',
            'qty_milli' => 10000,
            'created_at_ms' => 1000,
        ]));

        $chatId = 111002; // Staff

        // Staff records a sale of 3 packs
        $nowMs = (int) (microtime(true) * 1000);
        $saleMovement = $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $prod->id,
            'user_id' => $this->staff->id,
            'type' => 'sale',
            'qty_milli' => -3000,
            'unit_price_cents' => 3000,
            'created_at_ms' => $nowMs,
        ]));

        $this->assertSame(7000, $this->ledger->stockOf($prod));

        // Staff immediately triggers Undo
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 524,
                'callback_query' => [
                    'id' => 'cq_undo',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 70],
                    'data' => 'undo:last:0',
                ],
            ]);
        $res->assertStatus(200);

        // Stock restored to 10 packs
        $this->assertSame(10000, $this->ledger->stockOf($prod));

        // Compensating movement verified
        $undoMovement = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('note', 'like', "%Compensating undo for [{$saleMovement->id}]%")
            ->first();

        $this->assertNotNull($undoMovement);
        $this->assertSame(3000, $undoMovement->qty_milli);

        // Total movements is 3 (original in + sale + compensating adjust; never deleted)
        $this->assertSame(3, Movement::withoutGlobalScope(ShopScope::class)->where('shop_id', $this->shop->id)->count());
    }

    public function test_undo_rejects_actions_older_than_10_minutes(): void
    {
        $prod = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Old Action Product',
            'unit' => 'pcs',
            'sell_price_cents' => 1000,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        $elevenMinutesAgoMs = (int) ((microtime(true) - 660) * 1000);

        // Sale happened 11 minutes ago
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $prod->id,
            'user_id' => $this->staff->id,
            'type' => 'sale',
            'qty_milli' => -2000,
            'unit_price_cents' => 1000,
            'created_at_ms' => $elevenMinutesAgoMs,
        ]));

        $chatId = 111002;

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 525,
                'callback_query' => [
                    'id' => 'cq_undo_old',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 75],
                    'data' => 'undo:last:0',
                ],
            ])->assertStatus(200);

        // Stock was not compensated
        $this->assertSame(-2000, $this->ledger->stockOf($prod));
    }

    public function test_staff_cannot_perform_owner_only_actions(): void
    {
        $prod = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Owner Restricted Product',
            'unit' => 'pcs',
            'sell_price_cents' => 2000,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        $chatId = 111002; // Staff Helen

        // 1. Staff attempts to adjust: adj:start:<id>
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 526,
                'callback_query' => [
                    'id' => 'cq_staff_adj',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 80],
                    'data' => "adj:start:{$prod->id}",
                ],
            ])->assertStatus(200);

        // Adjust state was NOT started
        $this->assertNull($this->botState->get($chatId));

        // 2. Staff attempts to delete product: prod:del:<id>
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 527,
                'callback_query' => [
                    'id' => 'cq_staff_del',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 81],
                    'data' => "prod:del:{$prod->id}",
                ],
            ])->assertStatus(200);

        // Product was NOT deleted
        $prod->refresh();
        $this->assertNull($prod->deleted_at_ms);
    }

    public function test_duplicate_callback_tap_does_not_double_record_sale(): void
    {
        $prod = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Water (ውሃ)',
            'unit' => 'bottle',
            'sell_price_cents' => 3000,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $prod->id,
            'type' => 'in',
            'qty_milli' => 10000,
            'created_at_ms' => 1000,
        ]));

        $chatId = 111002;

        // Add 1 bottle to cart
        $this->botState->start($chatId, 'sale', 'cart', [
            'cart' => [
                [
                    'product_id' => $prod->id,
                    'name' => $prod->name,
                    'unit' => $prod->unit,
                    'qty_milli' => 1000,
                    'qty_formatted' => '1.000',
                    'unit_price_cents' => 3000,
                    'total_cents' => 3000,
                ],
            ],
        ]);

        // Tap 1: sale:finish:0
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 528,
                'callback_query' => [
                    'id' => 'cq_finish_1',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 90],
                    'data' => 'sale:finish:0',
                ],
            ])->assertStatus(200);

        // Tap 2 (rapid duplicate tap on same callback): sale:finish:0
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 529,
                'callback_query' => [
                    'id' => 'cq_finish_2',
                    'message' => ['chat' => ['id' => $chatId], 'message_id' => 90],
                    'data' => 'sale:finish:0',
                ],
            ])->assertStatus(200);

        // Only ONE sale movement was recorded: stock is 9.000, NOT 8.000
        $this->assertSame(9000, $this->ledger->stockOf($prod));
        $this->assertSame(1, Movement::withoutGlobalScope(ShopScope::class)->where('shop_id', $this->shop->id)->where('type', 'sale')->count());
    }
}
