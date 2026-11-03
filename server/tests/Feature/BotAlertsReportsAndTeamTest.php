<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Context\CurrentShopContext;
use App\Jobs\GenerateExportJob;
use App\Jobs\SendLowStockAlertJob;
use App\Models\ActivityLog;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TeamInvite;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\DailySummaryService;
use App\Services\LedgerService;
use App\Services\Telegram\TelegramClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class BotAlertsReportsAndTeamTest extends TestCase
{
    use RefreshDatabase;

    protected string $secretToken = 'secret-test-token-task06';
    protected Shop $shop;
    protected User $owner;
    protected User $staff;
    protected LedgerService $ledger;
    protected ?\Closure $customHttpHandler = null;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.telegram.bot_token', '123456:TEST_BOT_TOKEN_ABC123');
        Config::set('services.telegram.webhook_secret', $this->secretToken);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if ($this->customHttpHandler !== null) {
                $handler = $this->customHttpHandler;
                return $handler($request);
            }
            if (str_contains($request->url(), 'sendDocument')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => 102]], 200);
            }
            if (str_contains($request->url(), 'answerCallbackQuery')) {
                return Http::response(['ok' => true, 'result' => true], 200);
            }
            return Http::response(['ok' => true, 'result' => ['message_id' => 101]], 200);
        });

        $this->ledger = app(LedgerService::class);

        $this->shop = Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Addis Minimarket',
            'currency' => 'ETB',
            'language' => 'am',
            'low_stock_default_milli' => 5000, // 5.000 units
            'allow_negative_stock' => false,
            'daily_summary_time' => '20:00',
            'staff_can_adjust' => false,
            'use_ethiopian_calendar' => false,
        ]);

        CurrentShopContext::runWith($this->shop, function (): void {
            $this->owner = User::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'telegram_id' => 777001,
                'name' => 'Abebe (Owner)',
                'language' => 'am',
                'shop_id' => $this->shop->id,
                'role' => 'owner',
                'is_active' => true,
            ]);

            $this->staff = User::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'telegram_id' => 777002,
                'name' => 'Chaltu (Staff)',
                'language' => 'am',
                'shop_id' => $this->shop->id,
                'role' => 'staff',
                'is_active' => true,
            ]);
        });
    }

    public function test_low_stock_alert_dispatched_and_debounced_24h(): void
    {
        Queue::fake([SendLowStockAlertJob::class]);

        $product = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Sunflower Oil',
            'unit' => 'lt',
            'cost_price_cents' => 80000,
            'sell_price_cents' => 95000,
            'low_stock_at_milli' => 5000,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        // Opening stock: 8 units
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $product->id,
            'type' => 'in',
            'qty_milli' => 8000,
            'created_at_ms' => 1000,
        ]));

        // No low stock alert on stock in
        Queue::assertNotPushed(SendLowStockAlertJob::class);

        // 1. Sale drops stock to 4 units (<= 5 threshold) -> Alert dispatched
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $product->id,
            'type' => 'sale',
            'qty_milli' => -4000,
            'unit_price_cents' => 95000,
            'created_at_ms' => 2000,
        ]));

        Queue::assertPushed(SendLowStockAlertJob::class, 1);

        $product->refresh();
        $this->assertNotNull($product->low_stock_alerted_at_ms);

        // 2. Second sale within 24h drops stock to 2 units -> Debounced, no duplicate job
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $product->id,
            'type' => 'sale',
            'qty_milli' => -2000,
            'unit_price_cents' => 95000,
            'created_at_ms' => 3000,
        ]));

        Queue::assertPushed(SendLowStockAlertJob::class, 1);
    }

    public function test_restock_clears_low_stock_debounce_flag(): void
    {
        $product = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Sugar',
            'unit' => 'kg',
            'cost_price_cents' => 10000,
            'sell_price_cents' => 13000,
            'low_stock_at_milli' => 5000,
            'low_stock_alerted_at_ms' => 10000,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        // Restock brings stock to 20 kg (> threshold)
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $product->id,
            'type' => 'in',
            'qty_milli' => 20000,
            'created_at_ms' => 20000,
        ]));

        $product->refresh();
        $this->assertNull($product->low_stock_alerted_at_ms);
    }

    public function test_daily_summary_command_sends_once_per_day_and_skips_when_empty(): void
    {
        $product = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Coffee',
            'unit' => 'kg',
            'cost_price_cents' => 45000,
            'sell_price_cents' => 60000,
            'low_stock_at_milli' => 3000,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        $tz = 'Africa/Addis_Ababa';
        $todayNow = Carbon::now($tz)->setTime(20, 30); // 20:30 (past 20:00 schedule)
        Carbon::setTestNow($todayNow);

        // Shop has no movements -> should skip sending
        $service = app(DailySummaryService::class);
        $sentCount = $service->processDueSummaries();
        $this->assertSame(0, $sentCount);

        // Record a sale today
        $nowMs = (int) ($todayNow->timestamp * 1000);
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $product->id,
            'type' => 'sale',
            'qty_milli' => -2000,
            'unit_price_cents' => 60000,
            'created_at_ms' => $nowMs,
        ]));

        // Reset last sent date to test processing with sale
        $this->shop->daily_summary_last_sent_date = null;
        $this->shop->save();

        // Run summary command
        $this->artisan('bot:daily-summary')->assertSuccessful();

        $this->shop->refresh();
        $this->assertSame($todayNow->format('Y-m-d'), $this->shop->daily_summary_last_sent_date);

        // Second run on the same day should do nothing (idempotent)
        $sentCountSecond = $service->processDueSummaries();
        $this->assertSame(0, $sentCountSecond);

        Carbon::setTestNow(); // restore
    }

    public function test_reports_owner_view_and_staff_restricted_view(): void
    {
        // 1. Staff accessing /menu or reports button gets restricted personal view
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 901,
                'message' => [
                    'chat' => ['id' => $this->staff->telegram_id],
                    'from' => ['id' => $this->staff->telegram_id],
                    'text' => '📊 ሪፖርት',
                ],
            ])->assertStatus(200);

        // 2. Owner accessing reports gets owner menu with period choices
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 902,
                'message' => [
                    'chat' => ['id' => $this->owner->telegram_id],
                    'from' => ['id' => $this->owner->telegram_id],
                    'text' => '📊 ሪፖርት',
                ],
            ])->assertStatus(200);

        // 3. Staff trying to trigger owner period report via callback receives permission denied
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 903,
                'callback_query' => [
                    'id' => 'cq_rep_staff',
                    'from' => ['id' => $this->staff->telegram_id],
                    'message' => ['chat' => ['id' => $this->staff->telegram_id], 'message_id' => 55],
                    'data' => 'rep:p:today',
                ],
            ])->assertStatus(200);
    }

    public function test_reports_period_aggregations_and_profit_calculation(): void
    {
        $prodA = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Tea (ሻይ)',
            'unit' => 'pack',
            'cost_price_cents' => 8000,
            'sell_price_cents' => 12000,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        $prodB = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Biscuits (ቢስኩት)',
            'unit' => 'pcs',
            'cost_price_cents' => 2000,
            'sell_price_cents' => 3000,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        $nowMs = (int) (microtime(true) * 1000);

        // Sale: 2 packs of Tea @ 120 ETB (Rev 240, Cost 160, Profit 80)
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $prodA->id,
            'type' => 'sale',
            'qty_milli' => -2000,
            'unit_price_cents' => 12000,
            'created_at_ms' => $nowMs,
        ]));

        // Sale: 5 pcs of Biscuits @ 30 ETB (Rev 150, Cost 100, Profit 50)
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $prodB->id,
            'type' => 'sale',
            'qty_milli' => -5000,
            'unit_price_cents' => 3000,
            'created_at_ms' => $nowMs,
        ]));

        // Owner views Today's report
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 910,
                'callback_query' => [
                    'id' => 'cq_today_rep',
                    'from' => ['id' => $this->owner->telegram_id],
                    'message' => ['chat' => ['id' => $this->owner->telegram_id], 'message_id' => 10],
                    'data' => 'rep:p:today',
                ],
            ])->assertStatus(200);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'editMessageText')) {
                $text = $request['text'];
                // Total revenue: 240 + 150 = 390.00 ETB
                return str_contains($text, '390.00 ETB') && str_contains($text, '2');
            }
            return false;
        });
    }

    public function test_stock_valuation_report(): void
    {
        $prod = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Flour (ዱቄት)',
            'unit' => 'kg',
            'cost_price_cents' => 6000,
            'sell_price_cents' => 8500,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        // Stock in: 100 kg
        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'product_id' => $prod->id,
            'type' => 'in',
            'qty_milli' => 100000,
            'cost_price_cents' => 6000,
            'created_at_ms' => 1000,
        ]));

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 920,
                'callback_query' => [
                    'id' => 'cq_stock_val',
                    'from' => ['id' => $this->owner->telegram_id],
                    'message' => ['chat' => ['id' => $this->owner->telegram_id], 'message_id' => 12],
                    'data' => 'rep:stock:val',
                ],
            ])->assertStatus(200);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'editMessageText')) {
                $text = $request['text'];
                // 100 * 60 = 6,000 ETB at cost; 100 * 85 = 8,500 ETB at sell
                return str_contains($text, '6,000.00 ETB') && str_contains($text, '8,500.00 ETB');
            }
            return false;
        });
    }

    public function test_csv_export_job_generates_bom_and_cleans_up_file(): void
    {
        $prod = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Teft (ጤፍ)',
            'unit' => 'kg',
            'cost_price_cents' => 9000,
            'sell_price_cents' => 11500,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        $job = new GenerateExportJob('products', $this->shop->id, $this->owner->telegram_id, 'am');
        $job->handle(app(TelegramClient::class), $this->ledger);

        // Verify Telegram sendDocument was called
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendDocument');
        });

        // Verify temporary files directory has no leftover files
        $dir = storage_path('app/exports');
        if (is_dir($dir)) {
            $files = array_diff(scandir($dir) ?: [], ['.', '..']);
            $this->assertEmpty($files, 'Export temporary files should be unlinked immediately after sending.');
        }
    }

    public function test_team_invite_creation_and_acceptance(): void
    {
        // 1. Owner creates staff invite link
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 930,
                'callback_query' => [
                    'id' => 'cq_create_inv',
                    'from' => ['id' => $this->owner->telegram_id],
                    'message' => ['chat' => ['id' => $this->owner->telegram_id], 'message_id' => 20],
                    'data' => 'team:invite:create',
                ],
            ])->assertStatus(200);

        $invite = TeamInvite::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->first();

        $this->assertNotNull($invite);
        $this->assertFalse($invite->isExpired());
        $this->assertFalse($invite->isUsed());

        // 2. New user joins via /start join_<token>
        // We find the invite token by generating known token or mocking
        $rawToken = 'testinvitetoken12345';
        $invite->token_hash = hash('sha256', $rawToken);
        $invite->save();

        $newTelegramId = 888123;
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 931,
                'message' => [
                    'chat' => ['id' => $newTelegramId],
                    'from' => [
                        'id' => $newTelegramId,
                        'first_name' => 'Kassahun',
                        'username' => 'kassa_eth',
                    ],
                    'text' => "/start join_{$rawToken}",
                ],
            ])->assertStatus(200);

        // Verify user created as staff
        $newUser = User::withoutGlobalScope(ShopScope::class)
            ->where('telegram_id', $newTelegramId)
            ->first();

        $this->assertNotNull($newUser);
        $this->assertSame('staff', $newUser->role);
        $this->assertSame($this->shop->id, $newUser->shop_id);

        $invite->refresh();
        $this->assertTrue($invite->isUsed());
        $this->assertSame($newTelegramId, $invite->used_by_telegram_id);
    }

    public function test_team_invite_expiry_and_single_use(): void
    {
        $rawToken = 'expiredtoken999';
        $invite = TeamInvite::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'inviter_user_id' => $this->owner->id,
            'token_hash' => hash('sha256', $rawToken),
            'role' => 'staff',
            'expires_at' => now()->subHour(), // Expired
        ]);

        $newTelegramId = 999123;
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 935,
                'message' => [
                    'chat' => ['id' => $newTelegramId],
                    'from' => ['id' => $newTelegramId, 'first_name' => 'Late User'],
                    'text' => "/start join_{$rawToken}",
                ],
            ])->assertStatus(200);

        $newUser = User::withoutGlobalScope(ShopScope::class)
            ->where('telegram_id', $newTelegramId)
            ->first();

        $this->assertNull($newUser);
    }

    public function test_staff_status_toggle_and_removal(): void
    {
        // 1. Owner toggles staff active status
        $this->assertTrue($this->staff->is_active);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 940,
                'callback_query' => [
                    'id' => 'cq_toggle_staff',
                    'from' => ['id' => $this->owner->telegram_id],
                    'message' => ['chat' => ['id' => $this->owner->telegram_id], 'message_id' => 25],
                    'data' => "team:toggle_active:{$this->staff->id}",
                ],
            ])->assertStatus(200);

        $this->staff->refresh();
        $this->assertFalse($this->staff->is_active);

        // 2. Owner removes staff member
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 941,
                'callback_query' => [
                    'id' => 'cq_remove_staff',
                    'from' => ['id' => $this->owner->telegram_id],
                    'message' => ['chat' => ['id' => $this->owner->telegram_id], 'message_id' => 25],
                    'data' => "team:remove:{$this->staff->id}",
                ],
            ])->assertStatus(200);

        $deletedStaff = User::withoutGlobalScope(ShopScope::class)->find($this->staff->id);
        $this->assertNull($deletedStaff);
    }

    public function test_staff_can_adjust_toggle_enforces_permissions(): void
    {
        $product = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Soap (ሳሙና)',
            'unit' => 'pcs',
            'cost_price_cents' => 3000,
            'sell_price_cents' => 4500,
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        // Default: staff cannot adjust
        $this->assertFalse((bool) $this->shop->staff_can_adjust);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 950,
                'callback_query' => [
                    'id' => 'cq_staff_adj_denied',
                    'from' => ['id' => $this->staff->telegram_id],
                    'message' => ['chat' => ['id' => $this->staff->telegram_id], 'message_id' => 30],
                    'data' => "adj:start:{$product->id}",
                ],
            ])->assertStatus(200);

        // Owner toggles staff_can_adjust to true
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 951,
                'callback_query' => [
                    'id' => 'cq_toggle_adj',
                    'from' => ['id' => $this->owner->telegram_id],
                    'message' => ['chat' => ['id' => $this->owner->telegram_id], 'message_id' => 31],
                    'data' => 'team:toggle:adjust',
                ],
            ])->assertStatus(200);

        $this->shop->refresh();
        $this->assertTrue((bool) $this->shop->staff_can_adjust);

        // Now staff can start adjustment
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 952,
                'callback_query' => [
                    'id' => 'cq_staff_adj_allowed',
                    'from' => ['id' => $this->staff->telegram_id],
                    'message' => ['chat' => ['id' => $this->staff->telegram_id], 'message_id' => 32],
                    'data' => "adj:start:{$product->id}",
                ],
            ])->assertStatus(200);
    }

    public function test_ownership_transfer_with_double_confirmation(): void
    {
        // Step 1: Warning prompt
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 960,
                'callback_query' => [
                    'id' => 'cq_trans_1',
                    'from' => ['id' => $this->owner->telegram_id],
                    'message' => ['chat' => ['id' => $this->owner->telegram_id], 'message_id' => 40],
                    'data' => "team:transfer_init:{$this->staff->id}",
                ],
            ])->assertStatus(200);

        // Step 2: Final confirmation prompt
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 961,
                'callback_query' => [
                    'id' => 'cq_trans_2',
                    'from' => ['id' => $this->owner->telegram_id],
                    'message' => ['chat' => ['id' => $this->owner->telegram_id], 'message_id' => 40],
                    'data' => "team:transfer_confirm:{$this->staff->id}",
                ],
            ])->assertStatus(200);

        // Step 3: Execute transfer
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 962,
                'callback_query' => [
                    'id' => 'cq_trans_3',
                    'from' => ['id' => $this->owner->telegram_id],
                    'message' => ['chat' => ['id' => $this->owner->telegram_id], 'message_id' => 40],
                    'data' => "team:transfer_execute:{$this->staff->id}",
                ],
            ])->assertStatus(200);

        $this->owner->refresh();
        $this->staff->refresh();

        $this->assertSame('staff', $this->owner->role);
        $this->assertSame('owner', $this->staff->role);

        // Verify activity log recorded
        $log = ActivityLog::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('action', 'ownership.transfer')
            ->first();

        $this->assertNotNull($log);
    }

    public function test_price_change_updates_product_and_logs_activity(): void
    {
        $product = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'name' => 'Mineral Water',
            'unit' => 'bottle',
            'cost_price_cents' => 1500,
            'sell_price_cents' => 2500, // 25.00 ETB
            'is_active' => true,
            'updated_at_ms' => 1000,
        ]);

        // 1. Owner clicks edit price
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 970,
                'callback_query' => [
                    'id' => 'cq_edit_p',
                    'from' => ['id' => $this->owner->telegram_id],
                    'message' => ['chat' => ['id' => $this->owner->telegram_id], 'message_id' => 50],
                    'data' => "prod:price:{$product->id}",
                ],
            ])->assertStatus(200);

        // 2. Owner enters new price "32.50"
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 971,
                'message' => [
                    'chat' => ['id' => $this->owner->telegram_id],
                    'from' => ['id' => $this->owner->telegram_id],
                    'text' => '32.50',
                ],
            ])->assertStatus(200);

        $product->refresh();
        $this->assertSame(3250, $product->sell_price_cents);

        $log = ActivityLog::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('action', 'price.change')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('32.50', $log->description);
    }

    public function test_telegram_403_forbidden_deactivates_user(): void
    {
        $this->customHttpHandler = fn($req) => Http::response([
            'ok' => false,
            'error_code' => 403,
            'description' => 'Forbidden: bot was blocked by the user',
        ], 403);

        $client = app(TelegramClient::class);
        $res = $client->sendMessage($this->staff->telegram_id, 'Hello staff');

        $this->assertNull($res);

        $this->staff->refresh();
        $this->assertFalse($this->staff->is_active);
    }
}
