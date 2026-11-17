<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Context\CurrentShopContext;
use App\Models\CashFlowEntry;
use App\Models\Change;
use App\Models\Device;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\PaymentReportService;
use App\Services\PermissionService;
use App\Services\SyncService;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\TelegramRouter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BotCashFlowTest extends TestCase
{
    use RefreshDatabase;

    protected string $secretToken = 'secret-test-token-cashflow';
    protected Shop $shop;
    protected Location $location;
    protected User $owner;
    protected User $cashier;
    protected User $viewOnlyStaff;
    protected BotStateService $botState;
    protected PaymentReportService $reportService;
    protected SyncService $syncService;
    protected TelegramRouter $router;
    protected array $sentMessages = [];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.telegram.bot_token', '123456:TEST_BOT_TOKEN_CASHFLOW');
        Config::set('services.telegram.webhook_secret', $this->secretToken);

        $this->botState = app(BotStateService::class);
        $this->reportService = app(PaymentReportService::class);
        $this->syncService = app(SyncService::class);
        $this->router = app(TelegramRouter::class);

        $this->sentMessages = [];
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $this->sentMessages[] = [
                'url' => $request->url(),
                'data' => $request->data(),
            ];
            if (str_contains($request->url(), 'answerCallbackQuery')) {
                return Http::response(['ok' => true, 'result' => true], 200);
            }
            return Http::response(['ok' => true, 'result' => ['message_id' => rand(100, 999)]], 200);
        });

        // 1. Create shop
        $this->shop = Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Addis Store',
            'currency' => 'ETB',
            'language' => 'am',
            'payment_methods_enabled' => ['cash', 'bank', 'wallet'],
            'timezone' => 'Africa/Addis_Ababa',
        ]);

        CurrentShopContext::runWith($this->shop, function (): void {
            $this->location = Location::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'name' => 'Main Shop',
                'is_active' => true,
            ]);

            $this->owner = User::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'name' => 'Abebe (Owner)',
                'telegram_id' => 11111111,
                'role' => 'owner',
                'language' => 'am',
                'is_active' => true,
            ]);

            $this->cashier = User::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'name' => 'Chala (Cashier)',
                'telegram_id' => 22222222,
                'role' => 'salesperson',
                'language' => 'am',
                'is_active' => true,
            ]);

            $this->viewOnlyStaff = User::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'name' => 'Warehouse Guard',
                'telegram_id' => 33333333,
                'role' => 'warehouse_keeper',
                'language' => 'am',
                'is_active' => true,
            ]);
        });
    }

    protected function sendWebhookMessage(int|string $chatId, string $text): void
    {
        $payload = [
            'update_id' => rand(1000, 9999),
            'message' => [
                'message_id' => rand(100, 999),
                'from' => [
                    'id' => $chatId,
                    'is_bot' => false,
                    'first_name' => 'TestUser',
                ],
                'chat' => [
                    'id' => $chatId,
                    'type' => 'private',
                ],
                'date' => time(),
                'text' => $text,
            ],
        ];

        $this->router->dispatch($payload);
    }

    protected function sendWebhookCallback(int|string $chatId, string $callbackData, int $messageId = 555): void
    {
        $payload = [
            'update_id' => rand(1000, 9999),
            'callback_query' => [
                'id' => 'cq_' . Str::random(10),
                'from' => [
                    'id' => $chatId,
                    'is_bot' => false,
                    'first_name' => 'TestUser',
                ],
                'message' => [
                    'message_id' => $messageId,
                    'chat' => [
                        'id' => $chatId,
                        'type' => 'private',
                    ],
                    'date' => time(),
                    'text' => 'Sample message text',
                ],
                'data' => $callbackData,
            ],
        ];

        $this->router->dispatch($payload);
    }

    public function test_menu_shows_cash_flow_buttons_and_summary(): void
    {
        $this->sendWebhookMessage($this->owner->telegram_id, '💸 ገቢና ወጪ');

        $this->assertNotEmpty($this->sentMessages);
        $last = end($this->sentMessages);
        $this->assertStringContainsString('የገቢና ወጪ አስተዳደር', $last['data']['text'] ?? '');

        // Verify inline buttons are present: Add Income, Add Expense, Today, Month
        $rawMarkup = $last['data']['reply_markup'] ?? [];
        $markup = is_array($rawMarkup) ? $rawMarkup : json_decode($rawMarkup, true);
        $this->assertIsArray($markup);
        $callbacks = [];
        foreach ($markup['inline_keyboard'] as $row) {
            foreach ($row as $btn) {
                $callbacks[] = $btn['callback_data'];
            }
        }
        $this->assertContains('cf:add:income', $callbacks);
        $this->assertContains('cf:add:expense', $callbacks);
        $this->assertContains('cf:list:today', $callbacks);
        $this->assertContains('cf:list:month', $callbacks);
    }

    public function test_owner_records_expense_flow_end_to_end(): void
    {
        $chatId = $this->owner->telegram_id;

        // 1. Click Add Expense button
        $this->sendWebhookCallback($chatId, 'cf:add:expense');
        $state = $this->botState->get($chatId);
        $this->assertNotNull($state);
        $this->assertSame('cash_flow', $state->flow);
        $this->assertSame('await_amount', $state->step);
        $this->assertSame('expense', $state->data['type']);

        // 2. Enter amount: 350.50
        $this->sendWebhookMessage($chatId, '350.50');
        $state = $this->botState->get($chatId);
        $this->assertSame('await_category', $state->step);
        $this->assertSame(35050, $state->data['amount_cents']);

        // 3. Choose category: Transport (cf:cat:transport)
        $this->sendWebhookCallback($chatId, 'cf:cat:transport');
        $state = $this->botState->get($chatId);
        $this->assertSame('await_method', $state->step);
        $this->assertStringContainsString('ትራንስፖርት', $state->data['category']);

        // 4. Choose method: Cash (cf:meth:cash)
        $this->sendWebhookCallback($chatId, 'cf:meth:cash');
        $state = $this->botState->get($chatId);
        $this->assertSame('await_note', $state->step);
        $this->assertSame('cash', $state->data['payment_method']);

        // 5. Enter note
        $this->sendWebhookMessage($chatId, 'ለዕቃ ማመላለሻ ባጃጅ');
        $this->assertNull($this->botState->get($chatId)); // Flow cleared

        // Verify entry in database
        $entry = CashFlowEntry::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->latest()
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('expense', $entry->type);
        $this->assertSame(35050, $entry->amount_cents);
        $this->assertStringContainsString('ትራንስፖርት', $entry->category);
        $this->assertSame('cash', $entry->payment_method);
        $this->assertSame('ለዕቃ ማመላለሻ ባጃጅ', $entry->note);
        $this->assertSame($this->owner->id, $entry->user_id);

        // Verify sync Change was created
        $change = Change::withoutGlobalScope(ShopScope::class)
            ->where('entity', 'cash_flow')
            ->where('entity_id', $entry->id)
            ->first();
        $this->assertNotNull($change);

        // Verify confirmation message was sent
        $last = end($this->sentMessages);
        $this->assertStringContainsString('ተጨማሪ ወጪ ተመዝግቧል', $last['data']['text'] ?? '');
        $this->assertStringContainsString('350.50 ETB', $last['data']['text'] ?? '');
    }

    public function test_user_can_record_income_and_skip_note(): void
    {
        $chatId = $this->cashier->telegram_id;

        // 1. Direct text command /income
        $this->sendWebhookMessage($chatId, '/income');
        $state = $this->botState->get($chatId);
        $this->assertNotNull($state);
        $this->assertSame('income', $state->data['type']);

        // 2. Enter amount
        $this->sendWebhookMessage($chatId, '1200');

        // 3. Choose category (commission)
        $this->sendWebhookCallback($chatId, 'cf:cat:commission');

        // 4. Choose method (bank)
        $this->sendWebhookCallback($chatId, 'cf:meth:bank');

        // 5. Skip note
        $this->sendWebhookCallback($chatId, 'cf:skip_note:0');
        $this->assertNull($this->botState->get($chatId));

        $entry = CashFlowEntry::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('type', 'income')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(120000, $entry->amount_cents);
        $this->assertSame('bank', $entry->payment_method);
        $this->assertNull($entry->note);
    }

    public function test_custom_category_typed_by_user(): void
    {
        $chatId = $this->owner->telegram_id;

        $this->sendWebhookCallback($chatId, 'cf:add:expense');
        $this->sendWebhookMessage($chatId, '500');

        // Type custom category string instead of pressing a button
        $this->sendWebhookMessage($chatId, 'የካፌ ኪራይ ክፍያ');
        $state = $this->botState->get($chatId);
        $this->assertSame('await_method', $state->step);
        $this->assertSame('የካፌ ኪራይ ክፍያ', $state->data['category']);

        $this->sendWebhookCallback($chatId, 'cf:meth:wallet');
        $this->sendWebhookCallback($chatId, 'cf:skip_note:0');

        $entry = CashFlowEntry::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('category', 'የካፌ ኪራይ ክፍያ')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('wallet', $entry->payment_method);
        $this->assertSame(50000, $entry->amount_cents);
    }

    public function test_list_entries_and_delete_entry(): void
    {
        $chatId = $this->owner->telegram_id;
        $nowMs = (int) (microtime(true) * 1000);

        $entry = CashFlowEntry::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $this->owner->id,
            'type' => 'expense',
            'amount_cents' => 8500,
            'category' => 'Food',
            'payment_method' => 'cash',
            'note' => 'Lunch',
            'created_at_ms' => $nowMs,
        ]);

        // 1. View today's list
        $this->sendWebhookCallback($chatId, 'cf:list:today');
        $last = end($this->sentMessages);
        $this->assertStringContainsString('Food', $last['data']['text'] ?? '');
        $this->assertStringContainsString('85.00 ETB', $last['data']['text'] ?? '');

        // 2. Tap delete on the entry
        $this->sendWebhookCallback($chatId, "cf:del:{$entry->id}");
        $last = end($this->sentMessages);
        $this->assertStringContainsString('ይህን መዝገብ ለመሰረዝ እርግጠኛ ነዎት', $last['data']['text'] ?? '');

        // 3. Confirm deletion
        $this->sendWebhookCallback($chatId, "cf:del_conf:{$entry->id}");
        $entry->refresh();
        $this->assertNotNull($entry->deleted_at_ms);

        $last = end($this->sentMessages);
        $this->assertStringContainsString('ተሰርዟል', $last['data']['text'] ?? '');
    }

    public function test_sync_api_ingests_and_serves_cash_flow(): void
    {
        $device = Device::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $this->owner->id,
            'name' => 'Cashier POS Tablet',
            'token_hash' => hash('sha256', 'sync-device-token-12345'),
            'is_active' => true,
        ]);

        $cfId = (string) Str::uuid7();
        $payload = [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $cfId,
                    'entity' => 'cash_flow',
                    'op' => 'upsert',
                    'data' => [
                        'id' => $cfId,
                        'type' => 'expense',
                        'amount_cents' => 45000,
                        'category' => 'Repair',
                        'payment_method' => 'cash',
                        'note' => 'Printer fix',
                        'created_at_ms' => (int) (microtime(true) * 1000),
                    ],
                    'client_ts' => (int) (microtime(true) * 1000),
                ],
            ],
        ];

        $response = $this->syncService->sync($device, $payload, strlen(json_encode($payload)));
        $this->assertContains($cfId, $response['accepted']);

        // Check it was created in server database
        $created = CashFlowEntry::withoutGlobalScope(ShopScope::class)->find($cfId);
        $this->assertNotNull($created);
        $this->assertSame(45000, $created->amount_cents);
        $this->assertSame('Repair', $created->category);

        // Check another sync call from device2 receives this cash_flow entry in server changes
        $device2 = Device::create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $this->cashier->id,
            'name' => 'Second Phone Device',
            'token_hash' => hash('sha256', 'sync-device2-token-67890'),
            'is_active' => true,
        ]);

        $secondSync = $this->syncService->sync($device2, ['cursor' => 0, 'changes' => []], 50);
        $receivedEntities = array_column($secondSync['changes'], 'entity');
        $this->assertContains('cash_flow', $receivedEntities);
    }

    public function test_payment_report_cash_expected_includes_extra_cash_flows(): void
    {
        $now = Carbon::now('Africa/Addis_Ababa');
        $nowMs = $now->timestamp * 1000;

        $sale = \App\Models\Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'total_cents' => 100000,
            'created_at_ms' => $nowMs,
            'updated_at_ms' => $nowMs,
        ]);

        // Sale payment: 1000 ETB cash
        Payment::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'sale_id' => $sale->id,
            'method' => 'cash',
            'amount_cents' => 100000,
            'received_at_ms' => $nowMs,
            'created_at_ms' => $nowMs,
            'server_seq' => 1,
        ]);

        // Extra Cash Income: 200 ETB
        CashFlowEntry::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $this->owner->id,
            'type' => 'income',
            'amount_cents' => 20000,
            'category' => 'Commission',
            'payment_method' => 'cash',
            'created_at_ms' => $nowMs,
        ]);

        // Extra Cash Expense: 150 ETB
        CashFlowEntry::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $this->owner->id,
            'type' => 'expense',
            'amount_cents' => 15000,
            'category' => 'Transport',
            'payment_method' => 'cash',
            'created_at_ms' => $nowMs,
        ]);

        $cashReport = $this->reportService->cashExpected($this->shop, $now);

        // Expected cash in drawer: 1000.00 + 200.00 - 150.00 = 1050.00 ETB (105000 cents)
        $this->assertSame(105000, $cashReport['expected_cash_cents']);
        $this->assertSame(20000, $cashReport['extra_cash_income_cents']);
        $this->assertSame(15000, $cashReport['extra_cash_expense_cents']);
    }

    public function test_unauthorized_user_is_denied(): void
    {
        $chatId = $this->viewOnlyStaff->telegram_id;

        $this->sendWebhookCallback($chatId, 'cf:add:expense');
        $this->assertNull($this->botState->get($chatId));

        $last = end($this->sentMessages);
        $this->assertStringContainsString(__('permission_denied'), $last['data']['text'] ?? '');
    }

    public function test_report_period_today_includes_extra_cash_flow(): void
    {
        $now = Carbon::now('Africa/Addis_Ababa');
        $nowMs = $now->timestamp * 1000;

        CashFlowEntry::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'user_id' => $this->owner->id,
            'type' => 'income',
            'amount_cents' => 50000,
            'category' => 'Commission',
            'payment_method' => 'cash',
            'created_at_ms' => $nowMs,
        ]);

        $this->sendWebhookCallback($this->owner->telegram_id, 'rep:p:today');

        $last = end($this->sentMessages);
        $text = $last['data']['text'] ?? '';
        $this->assertStringContainsString('ተጨማሪ የገቢና ወጪ እንቅስቃሴ', $text);
        $this->assertStringContainsString('500.00 ETB', $text);
    }
}
