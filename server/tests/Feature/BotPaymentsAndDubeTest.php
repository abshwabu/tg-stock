<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Context\CurrentShopContext;
use App\Models\Change;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\BalanceService;
use App\Services\CustomerService;
use App\Services\DailySummaryService;
use App\Services\LedgerService;
use App\Services\PaymentProviderService;
use App\Services\PaymentReportService;
use App\Services\PermissionService;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\TelegramClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BotPaymentsAndDubeTest extends TestCase
{
    use RefreshDatabase;

    protected string $secretToken = 'secret-test-token-task18';
    protected Shop $shop;
    protected Location $location;
    protected User $owner;
    protected User $cashier;
    protected User $viewOnlyStaff;
    protected Product $teff;
    protected Product $oil;
    protected LedgerService $ledger;
    protected BalanceService $balanceService;
    protected CustomerService $customerService;
    protected PaymentProviderService $providerService;
    protected PaymentReportService $reportService;
    protected BotStateService $botState;
    protected array $sentMessages = [];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.telegram.bot_token', '123456:TEST_BOT_TOKEN_TASK18');
        Config::set('services.telegram.webhook_secret', $this->secretToken);

        $this->ledger = app(LedgerService::class);
        $this->balanceService = app(BalanceService::class);
        $this->customerService = app(CustomerService::class);
        $this->providerService = app(PaymentProviderService::class);
        $this->reportService = app(PaymentReportService::class);
        $this->botState = app(BotStateService::class);

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
            'name' => 'Bole Mini Market',
            'currency' => 'ETB',
            'language' => 'en',
            'payment_methods_enabled' => ['cash', 'bank', 'wallet', 'dube'],
            'dube_default_days' => 14,
            'require_payment_reference' => false,
            'low_stock_default_milli' => 5000,
            'allow_negative_stock' => true,
            'daily_summary_time' => '20:00',
            'staff_can_adjust' => false,
            'use_ethiopian_calendar' => false,
        ]);

        CurrentShopContext::runWith($this->shop, function (): void {
            // Location
            $this->location = Location::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'name' => 'Main Store',
                'type' => 'store',
                'is_active' => true,
                'created_at_ms' => 1000,
                'updated_at_ms' => 1000,
            ]);

            // Owner
            $this->owner = User::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'telegram_id' => 999001,
                'name' => 'Solomon Owner',
                'language' => 'en',
                'shop_id' => $this->shop->id,
                'role' => 'owner',
                'is_active' => true,
                'current_location_id' => $this->location->id,
            ]);

            // Cashier (staff with sale.dube and payment.collect)
            $this->cashier = User::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'telegram_id' => 999002,
                'name' => 'Almaz Cashier',
                'language' => 'en',
                'shop_id' => $this->shop->id,
                'role' => 'salesperson',
                'is_active' => true,
                'current_location_id' => $this->location->id,
            ]);

            app(PermissionService::class)->updatePermissions(
                $this->cashier,
                overrides: [
                    'sale.create' => 'allow',
                    'sale.dube' => 'allow',
                    'payment.collect' => 'allow',
                    'customers.manage' => 'allow',
                    'dube.view' => 'allow',
                ],
                locationIds: [$this->location->id]
            );

            // View only staff (cannot sell on dube, cannot collect payments)
            $this->viewOnlyStaff = User::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'telegram_id' => 999003,
                'name' => 'Biruk Staff',
                'language' => 'en',
                'shop_id' => $this->shop->id,
                'role' => 'salesperson',
                'is_active' => true,
                'current_location_id' => $this->location->id,
            ]);

            app(PermissionService::class)->updatePermissions(
                $this->viewOnlyStaff,
                overrides: [
                    'sale.create' => 'allow',
                    'sale.dube' => 'deny',
                    'payment.collect' => 'deny',
                    'customers.manage' => 'deny',
                    'dube.view' => 'deny',
                ],
                locationIds: [$this->location->id]
            );

            // Products
            $this->teff = Product::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'name' => 'Magna Teff',
                'unit' => 'kg',
                'cost_price_cents' => 10000,
                'sell_price_cents' => 12500, // 125.00 ETB
                'is_active' => true,
                'updated_at_ms' => 1000,
            ]);

            $this->oil = Product::withoutGlobalScope(ShopScope::class)->create([
                'id' => (string) Str::uuid7(),
                'shop_id' => $this->shop->id,
                'name' => 'Cooking Oil 5L',
                'unit' => 'pcs',
                'cost_price_cents' => 85000,
                'sell_price_cents' => 95000, // 950.00 ETB
                'is_active' => true,
                'updated_at_ms' => 1000,
            ]);
        });
    }

    /**
     * Helper to send webhook message from user.
     */
    protected function postMessage(User $user, string $text): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => rand(1000, 99999),
                'message' => [
                    'message_id' => rand(100, 999),
                    'chat' => ['id' => $user->telegram_id],
                    'from' => ['id' => $user->telegram_id, 'first_name' => $user->name],
                    'text' => $text,
                ],
            ]);
    }

    /**
     * Helper to send webhook callback query from user.
     */
    protected function postCallback(User $user, string $data, int $messageId = 100): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->secretToken)
            ->postJson('/api/telegram/webhook', [
                'update_id' => rand(1000, 99999),
                'callback_query' => [
                    'id' => 'cq_' . Str::random(8),
                    'from' => ['id' => $user->telegram_id, 'first_name' => $user->name],
                    'message' => [
                        'message_id' => $messageId,
                        'chat' => ['id' => $user->telegram_id],
                    ],
                    'data' => $data,
                ],
            ]);
    }

    public function test_cash_sale_fast_path_finishes_immediately_with_sale_movements_and_payment(): void
    {
        // 1. Pick product
        $this->postCallback($this->owner, "sale:pick:{$this->teff->id}")->assertOk();

        // 2. Select qty 2 kg -> total = 2 × 125.00 = 250.00 ETB (25,000 cents)
        $this->postCallback($this->owner, 'sale:qty:2')->assertOk();

        // 3. Cart summary shown, click finish sale to reach payment step
        $this->postCallback($this->owner, 'sale:finish:0')->assertOk();

        // 4. Tap Cash (fast path 1-tap finish)
        $this->postCallback($this->owner, 'sale:pay_m:cash')->assertOk();

        // Assert DB state
        $sale = Sale::withoutGlobalScope(ShopScope::class)->where('shop_id', $this->shop->id)->first();
        $this->assertNotNull($sale);
        $this->assertSame(25000, $sale->total_cents);
        $this->assertNull($sale->due_date);
        $this->assertNull($sale->customer_id);
        $this->assertFalse($sale->legacy);

        // Movement with sale_id
        $movement = Movement::withoutGlobalScope(ShopScope::class)->where('sale_id', $sale->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame(-2000, $movement->qty_milli);
        $this->assertSame(12500, $movement->unit_price_cents);

        // Payment created
        $payment = Payment::withoutGlobalScope(ShopScope::class)->where('sale_id', $sale->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('cash', $payment->method);
        $this->assertNull($payment->provider_key);
        $this->assertSame(25000, $payment->amount_cents);

        // Appended to changes feed
        $this->assertTrue(Change::withoutGlobalScope(ShopScope::class)->where('entity', 'sale')->where('entity_id', $sale->id)->exists());
        $this->assertTrue(Change::withoutGlobalScope(ShopScope::class)->where('entity', 'payment')->where('entity_id', $payment->id)->exists());
    }

    public function test_bank_sale_with_catalog_provider_and_reference(): void
    {
        // 1. Start sale, pick Oil 1 pcs (950.00 ETB)
        $this->postCallback($this->owner, "sale:pick:{$this->oil->id}")->assertOk();
        $this->postCallback($this->owner, 'sale:qty:1')->assertOk();

        // 2. Select Bank
        $this->postCallback($this->owner, 'sale:pay_m:bank')->assertOk();

        // 3. Choose Commercial Bank of Ethiopia (bank.cbe)
        $this->postCallback($this->owner, 'sale:prov_pick:bank:bank.cbe')->assertOk();

        // 4. Enter reference
        $this->postMessage($this->owner, 'CBE12345678')->assertOk();

        // Assert sale and payment
        $sale = Sale::withoutGlobalScope(ShopScope::class)->where('shop_id', $this->shop->id)->first();
        $this->assertNotNull($sale);
        $this->assertSame(95000, $sale->total_cents);

        $payment = Payment::withoutGlobalScope(ShopScope::class)->where('sale_id', $sale->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('bank', $payment->method);
        $this->assertSame('bank.cbe', $payment->provider_key);
        $this->assertSame(95000, $payment->amount_cents);
        $this->assertSame('CBE12345678', $payment->reference);
    }

    public function test_bank_sale_with_custom_provider_creation(): void
    {
        // Pick Teff 1 kg (125.00 ETB)
        $this->postCallback($this->owner, "sale:pick:{$this->teff->id}")->assertOk();
        $this->postCallback($this->owner, 'sale:qty:1')->assertOk();

        // Select Bank -> Other bank...
        $this->postCallback($this->owner, 'sale:pay_m:bank')->assertOk();
        $this->postCallback($this->owner, 'sale:prov_other:bank')->assertOk();

        // Enter custom bank name
        $this->postMessage($this->owner, 'Cooperative Microbank')->assertOk();

        // Skip reference
        $this->postCallback($this->owner, 'sale:skip_ref:0')->assertOk();

        $sale = Sale::withoutGlobalScope(ShopScope::class)->where('shop_id', $this->shop->id)->first();
        $payment = Payment::withoutGlobalScope(ShopScope::class)->where('sale_id', $sale->id)->first();

        $this->assertNotNull($payment);
        $this->assertSame('bank', $payment->method);
        $this->assertStringStartsWith('custom.', $payment->provider_key);

        // Assert custom provider exists in payment_providers table
        $customProv = PaymentProvider::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('provider_key', $payment->provider_key)
            ->first();
        $this->assertNotNull($customProv);
        $this->assertSame('Cooperative Microbank', $customProv->name);
        $this->assertSame('bank', $customProv->kind);
    }

    public function test_wallet_sale_with_skipped_reference(): void
    {
        // Pick Teff 2 kg (250.00 ETB)
        $this->postCallback($this->owner, "sale:pick:{$this->teff->id}")->assertOk();
        $this->postCallback($this->owner, 'sale:qty:2')->assertOk();

        // Select Wallet
        $this->postCallback($this->owner, 'sale:pay_m:wallet')->assertOk();

        // Pick Telebirr
        $this->postCallback($this->owner, 'sale:prov_pick:wallet:wallet.telebirr')->assertOk();

        // Skip reference button
        $this->postCallback($this->owner, 'sale:skip_ref:0')->assertOk();

        $sale = Sale::withoutGlobalScope(ShopScope::class)->where('shop_id', $this->shop->id)->first();
        $payment = Payment::withoutGlobalScope(ShopScope::class)->where('sale_id', $sale->id)->first();

        $this->assertNotNull($payment);
        $this->assertSame('wallet', $payment->method);
        $this->assertSame('wallet.telebirr', $payment->provider_key);
        $this->assertSame(25000, $payment->amount_cents);
        $this->assertNull($payment->reference);
    }

    public function test_dube_full_credit_sale_creates_sale_without_payment_and_appends_changes(): void
    {
        // Create customer
        $custRes = $this->customerService->upsertCustomer($this->shop->id, (string) Str::uuid7(), 'Bekele Tefera', '+251911223344');
        $customer = $custRes['customer'];

        // Pick Oil 1 pcs (950.00 ETB)
        $this->postCallback($this->cashier, "sale:pick:{$this->oil->id}")->assertOk();
        $this->postCallback($this->cashier, 'sale:qty:1')->assertOk();

        // Select Dube
        $this->postCallback($this->cashier, 'sale:pay_m:dube')->assertOk();

        // Pick customer
        $this->postCallback($this->cashier, "sale:dube_cust:{$customer->id}")->assertOk();

        // Paid now? 0 ETB (0%)
        $this->postCallback($this->cashier, 'sale:dube_pnow:0')->assertOk();

        // Due date: pick +14 days
        $this->postCallback($this->cashier, 'sale:dube_due:14')->assertOk();

        // Confirm Dube sale
        $this->postCallback($this->cashier, 'sale:dube_confirm:0')->assertOk();

        $sale = Sale::withoutGlobalScope(ShopScope::class)->where('shop_id', $this->shop->id)->first();
        $this->assertNotNull($sale);
        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertSame(95000, $sale->total_cents);
        $this->assertNotNull($sale->due_date);

        // No payment created for 0 upfront dube sale
        $paymentsCount = Payment::withoutGlobalScope(ShopScope::class)->where('sale_id', $sale->id)->count();
        $this->assertSame(0, $paymentsCount);

        // Customer credit shows 950.00 debt
        $credit = $this->balanceService->customerCredit($customer);
        $this->assertSame(95000, $credit['total_debt_cents']);
        $this->assertSame(95000, $credit['net_balance_cents']);
    }

    public function test_dube_partial_credit_sale_creates_upfront_payment_and_remaining_dube(): void
    {
        $custRes = $this->customerService->upsertCustomer($this->shop->id, (string) Str::uuid7(), 'Hanna Girma', '+251922334455');
        $customer = $custRes['customer'];

        // Teff 4 kg = 500.00 ETB (50,000 cents)
        $this->postCallback($this->cashier, "sale:pick:{$this->teff->id}")->assertOk();
        $this->postCallback($this->cashier, 'sale:qty:4')->assertOk();

        // Select Dube -> Customer
        $this->postCallback($this->cashier, 'sale:pay_m:dube')->assertOk();
        $this->postCallback($this->cashier, "sale:dube_cust:{$customer->id}")->assertOk();

        // Paid now: 25% (125.00 ETB = 12,500 cents)
        $this->postCallback($this->cashier, 'sale:dube_pnow:12500')->assertOk();

        // Choose upfront method: Cash
        $this->postCallback($this->cashier, 'sale:dube_umeth:cash')->assertOk();

        // Due date: +7 days
        $this->postCallback($this->cashier, 'sale:dube_due:7')->assertOk();

        // Confirm
        $this->postCallback($this->cashier, 'sale:dube_confirm:0')->assertOk();

        $sale = Sale::withoutGlobalScope(ShopScope::class)->where('shop_id', $this->shop->id)->first();
        $this->assertSame(50000, $sale->total_cents);

        // Upfront Payment created for 12,500 cents
        $payment = Payment::withoutGlobalScope(ShopScope::class)->where('sale_id', $sale->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('cash', $payment->method);
        $this->assertSame(12500, $payment->amount_cents);

        // Customer debt is 375.00 ETB (37,500 cents)
        $credit = $this->balanceService->customerCredit($customer);
        $this->assertSame(37500, $credit['total_debt_cents']);
        $this->assertSame(37500, $credit['net_balance_cents']);
    }

    public function test_dube_menu_shows_debtors_and_customer_card_statement(): void
    {
        // Create debtor with open sale
        $customer = $this->customerService->upsertCustomer($this->shop->id, (string) Str::uuid7(), 'Tariku Tadesse', '+251933445566')['customer'];
        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'customer_id' => $customer->id,
            'total_cents' => 60000, // 600.00 ETB
            'due_date' => Carbon::now()->subDays(5)->toDateString(), // overdue
            'created_at_ms' => 1000,
            'updated_at_ms' => 1000,
        ]);

        // 1. Open Dube menu via command or button
        $this->postMessage($this->owner, '📒 Dube')->assertOk();

        // 2. Open Customer Card
        $this->postCallback($this->owner, "dube:cust:{$customer->id}")->assertOk();

        // 3. Customer statement reports overdue
        $credit = $this->balanceService->customerCredit($customer);
        $this->assertSame(60000, $credit['total_debt_cents']);
        $this->assertSame(60000, $credit['overdue_cents']);
    }

    public function test_dube_copyable_reminder_text_format(): void
    {
        $customer = $this->customerService->upsertCustomer($this->shop->id, (string) Str::uuid7(), 'Aster Aweke', '+251944556677')['customer'];
        Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'customer_id' => $customer->id,
            'total_cents' => 45000,
            'due_date' => '2026-11-20',
            'created_at_ms' => 1000,
            'updated_at_ms' => 1000,
        ]);

        $this->postCallback($this->owner, "dube:remind:{$customer->id}")->assertOk();

        // Last sent message should contain copyable tag <code> and customer name
        $lastMsg = end($this->sentMessages);
        $this->assertStringContainsString('<code>', $lastMsg['data']['text']);
        $this->assertStringContainsString('Aster Aweke', $lastMsg['data']['text']);
        $this->assertStringContainsString('450.00 ETB', $lastMsg['data']['text']);
    }

    public function test_dube_repayment_full_balance_allocated_fifo(): void
    {
        $customer = $this->customerService->upsertCustomer($this->shop->id, (string) Str::uuid7(), 'Girma Negash', '+251955667788')['customer'];

        // Older sale: 300 ETB
        $sale1 = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'customer_id' => $customer->id,
            'total_cents' => 30000,
            'due_date' => Carbon::now()->subDays(10)->toDateString(),
            'created_at_ms' => 1000,
            'updated_at_ms' => 1000,
        ]);

        // Newer sale: 500 ETB
        $sale2 = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'customer_id' => $customer->id,
            'total_cents' => 50000,
            'due_date' => Carbon::now()->addDays(10)->toDateString(),
            'created_at_ms' => 2000,
            'updated_at_ms' => 2000,
        ]);

        // 1. Cashier taps Receive Payment
        $this->postCallback($this->cashier, "dube:pay_start:{$customer->id}")->assertOk();

        // 2. Select Full Balance (800.00 ETB)
        $this->postCallback($this->cashier, "dube:pay_amt:{$customer->id}:full")->assertOk();

        // 3. Choose Cash
        $this->postCallback($this->cashier, "dube:pay_m:{$customer->id}:cash:80000")->assertOk();

        // Assert customer credit is 0
        $credit = $this->balanceService->customerCredit($customer);
        $this->assertSame(0, $credit['net_balance_cents']);
        $this->assertSame(0, $this->balanceService->saleBalance($sale1));
        $this->assertSame(0, $this->balanceService->saleBalance($sale2));

        // Payment record created
        $payment = Payment::withoutGlobalScope(ShopScope::class)->where('customer_id', $customer->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame(80000, $payment->amount_cents);
        $this->assertSame('cash', $payment->method);
    }

    public function test_dube_repayment_blocks_overpayment(): void
    {
        $customer = $this->customerService->upsertCustomer($this->shop->id, (string) Str::uuid7(), 'Selam Tesfaye', '+251966778899')['customer'];
        Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'customer_id' => $customer->id,
            'total_cents' => 20000, // 200.00 ETB
            'due_date' => Carbon::now()->addDays(5)->toDateString(),
            'created_at_ms' => 1000,
            'updated_at_ms' => 1000,
        ]);

        // Tap Custom Amount
        $this->postCallback($this->cashier, "dube:pay_amt:{$customer->id}:custom")->assertOk();

        // Try entering 300.00 ETB (> 200.00 total debt)
        $this->postMessage($this->cashier, '300')->assertOk();

        // Overpayment should be blocked: no payment recorded!
        $this->assertSame(0, Payment::withoutGlobalScope(ShopScope::class)->where('customer_id', $customer->id)->count());

        $lastMsg = end($this->sentMessages);
        $this->assertStringContainsString('cannot exceed total debt', $lastMsg['data']['text']);
    }

    public function test_dube_repayment_with_bank_provider_and_reference(): void
    {
        $customer = $this->customerService->upsertCustomer($this->shop->id, (string) Str::uuid7(), 'Yonas Mamo', '+251977889900')['customer'];
        Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'customer_id' => $customer->id,
            'total_cents' => 40000, // 400.00 ETB
            'due_date' => Carbon::now()->addDays(5)->toDateString(),
            'created_at_ms' => 1000,
            'updated_at_ms' => 1000,
        ]);

        // Pay full balance via Bank
        $this->postCallback($this->cashier, "dube:pay_m:{$customer->id}:bank:40000")->assertOk();

        // Choose CBE
        $this->postCallback($this->cashier, "dube:rpay_prov:{$customer->id}:bank:40000:bank.cbe")->assertOk();

        // Enter reference
        $this->postMessage($this->cashier, 'FT2600112233')->assertOk();

        $payment = Payment::withoutGlobalScope(ShopScope::class)->where('customer_id', $customer->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('bank', $payment->method);
        $this->assertSame('bank.cbe', $payment->provider_key);
        $this->assertSame('FT2600112233', $payment->reference);
        $this->assertSame(40000, $payment->amount_cents);
    }

    public function test_extend_due_date_per_sale_and_appends_to_changes(): void
    {
        $customer = $this->customerService->upsertCustomer($this->shop->id, (string) Str::uuid7(), 'Meron Fikre', '+251988990011')['customer'];
        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'customer_id' => $customer->id,
            'total_cents' => 15000,
            'due_date' => '2026-11-10',
            'created_at_ms' => 1000,
            'updated_at_ms' => 1000,
        ]);

        // Extend +7 days
        $this->postCallback($this->cashier, "dube:ext_do:{$sale->id}:7:{$customer->id}")->assertOk();

        $sale->refresh();
        $expectedDate = Carbon::now('Africa/Addis_Ababa')->addDays(7)->toDateString();
        $this->assertSame($expectedDate, $sale->due_date);

        // Appended to changes
        $this->assertTrue(Change::withoutGlobalScope(ShopScope::class)->where('entity', 'sale')->where('entity_id', $sale->id)->exists());
    }

    public function test_add_and_edit_customer_with_ethiopian_phone_normalization(): void
    {
        // 1. Add customer
        $this->postCallback($this->cashier, 'dube:add_cust:0')->assertOk();
        $this->postMessage($this->cashier, 'Dawit Tsige')->assertOk();

        // Enter local format 0911556677
        $this->postMessage($this->cashier, '0911556677')->assertOk();

        $customer = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('name', 'Dawit Tsige')
            ->first();
        $this->assertNotNull($customer);
        $this->assertSame('+251911556677', $customer->phone);

        // 2. Edit customer
        $this->postCallback($this->cashier, "dube:edit:{$customer->id}")->assertOk();
        $this->postMessage($this->cashier, 'Dawit Tsige Updated')->assertOk();
        $this->postMessage($this->cashier, '0711223344')->assertOk(); // Safaricom format

        $customer->refresh();
        $this->assertSame('Dawit Tsige Updated', $customer->name);
        $this->assertSame('+251711223344', $customer->phone);
    }

    public function test_permission_gates_reject_forged_callbacks(): void
    {
        // 1. View-only staff cannot see Dube debtors list
        $this->postCallback($this->viewOnlyStaff, 'dube:page:1')->assertOk();
        $lastMsg = end($this->sentMessages);
        $this->assertStringContainsString('Owner permission required', $lastMsg['data']['text'] ?? '');

        // 2. View-only staff cannot start Dube sale
        $this->postCallback($this->viewOnlyStaff, 'sale:pay_m:dube')->assertOk();

        // 3. View-only staff cannot receive repayment
        $customer = $this->customerService->upsertCustomer($this->shop->id, (string) Str::uuid7(), 'Anon Customer', '+251911000000')['customer'];
        $this->postCallback($this->viewOnlyStaff, "dube:pay_start:{$customer->id}")->assertOk();

        // 4. View-only staff cannot add customer
        $this->postCallback($this->viewOnlyStaff, 'dube:add_cust:0')->assertOk();

        // 5. View-only staff cannot extend due date
        $this->postCallback($this->viewOnlyStaff, "dube:ext_do:some_sale:7:{$customer->id}")->assertOk();
    }

    public function test_payment_settings_toggles_and_provider_favorites(): void
    {
        // 1. Open payment settings menu
        $this->postCallback($this->owner, 'settings:pay:menu')->assertOk();

        // 2. Toggle require reference
        $this->postCallback($this->owner, 'settings:pay:tog_ref:0')->assertOk();
        $this->shop->refresh();
        $this->assertTrue($this->shop->require_payment_reference);

        // 3. Toggle payment method (disable dube)
        $this->postCallback($this->owner, 'settings:pay:tog_m:dube')->assertOk();
        $this->shop->refresh();
        $this->assertNotContains('dube', $this->shop->payment_methods_enabled);

        // 4. Set default dube days to 30
        $this->postCallback($this->owner, 'settings:pay:d_days:30')->assertOk();
        $this->shop->refresh();
        $this->assertSame(30, $this->shop->dube_default_days);

        // 5. Toggle favorite bank provider
        $this->postCallback($this->owner, 'settings:pay:tog_fav:bank:bank.cbe:1')->assertOk();
        $prov = PaymentProvider::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('provider_key', 'bank.cbe')
            ->first();
        $this->assertNotNull($prov);
        $this->assertTrue($prov->favorite);
    }

    public function test_reports_payments_and_cash_drawer_report(): void
    {
        // Record cash sale: 100 ETB
        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'total_cents' => 10000,
            'created_at_ms' => (int) (microtime(true) * 1000),
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);
        Payment::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'sale_id' => $sale->id,
            'method' => 'cash',
            'amount_cents' => 10000,
            'received_at_ms' => (int) (microtime(true) * 1000),
            'created_at_ms' => (int) (microtime(true) * 1000),
            'server_seq' => 1,
        ]);

        $this->postCallback($this->owner, 'rep:pay:today')->assertOk();

        $lastMsg = end($this->sentMessages);
        $this->assertStringContainsString('Payments, Cash Drawer & Dube Report', $lastMsg['data']['text']);
        $this->assertStringContainsString('100.00 ETB', $lastMsg['data']['text']);
    }

    public function test_daily_evening_summary_and_dube_due_reminders_idempotency(): void
    {
        $customer = $this->customerService->upsertCustomer($this->shop->id, (string) Str::uuid7(), 'Kassa Hailu', '+251911998877')['customer'];
        Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'customer_id' => $customer->id,
            'total_cents' => 50000,
            'due_date' => Carbon::now('Africa/Addis_Ababa')->toDateString(), // due today
            'created_at_ms' => (int) (microtime(true) * 1000),
            'updated_at_ms' => (int) (microtime(true) * 1000),
        ]);

        $this->ledger->record(new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'product_id' => $this->teff->id,
            'type' => 'sale',
            'qty_milli' => -1000,
            'unit_price_cents' => 12500,
            'created_at_ms' => (int) (microtime(true) * 1000),
        ]));

        $dailyService = app(DailySummaryService::class);

        // 1. Send reminders
        $sent1 = $dailyService->sendDubeRemindersForShop($this->shop);
        $this->assertTrue($sent1);

        $this->shop->refresh();
        $this->assertNotNull($this->shop->dube_reminder_last_sent_date);

        // 2. Second send on same date is skipped (idempotent)
        $sent2 = $dailyService->sendDubeRemindersForShop($this->shop);
        $this->assertFalse($sent2);

        // 3. Evening summary
        $sumSent1 = $dailyService->sendSummaryForShop($this->shop);
        $this->assertTrue($sumSent1);

        $this->shop->refresh();
        $this->assertNotNull($this->shop->daily_summary_last_sent_date);

        // 4. Second summary on same date is skipped
        $sumSent2 = $dailyService->sendSummaryForShop($this->shop);
        $this->assertFalse($sumSent2);
    }

    public function test_dube_sale_rejects_missing_customer_or_missing_due_date(): void
    {
        $saleHandler = app(\App\Services\Telegram\Handlers\SaleHandler::class);

        $cart = [
            [
                'product_id' => $this->teff->id,
                'name' => 'Magna Teff',
                'qty_milli' => 1000,
                'qty_formatted' => '1',
                'unit' => 'kg',
                'unit_price_cents' => 12500,
                'total_cents' => 12500,
            ],
        ];

        // 1. Missing customer
        $saleHandler->completeSale(
            chatId: $this->cashier->telegram_id,
            messageId: null,
            cart: $cart,
            totalCents: 12500,
            method: 'cash',
            paidNowCents: 0,
            providerKey: null,
            reference: null,
            customerId: null, // missing!
            dueDate: '2026-11-20',
            user: $this->cashier
        );

        $this->assertSame(0, Sale::withoutGlobalScope(ShopScope::class)->where('shop_id', $this->shop->id)->count());
        $lastMsg = end($this->sentMessages);
        $this->assertStringContainsString('Customer is required', $lastMsg['data']['text'] ?? '');

        // 2. Missing due date
        $customer = $this->customerService->upsertCustomer($this->shop->id, (string) Str::uuid7(), 'Test Cust', '+251911000111')['customer'];

        $saleHandler->completeSale(
            chatId: $this->cashier->telegram_id,
            messageId: null,
            cart: $cart,
            totalCents: 12500,
            method: 'cash',
            paidNowCents: 0,
            providerKey: null,
            reference: null,
            customerId: $customer->id,
            dueDate: null, // missing!
            user: $this->cashier
        );

        $this->assertSame(0, Sale::withoutGlobalScope(ShopScope::class)->where('shop_id', $this->shop->id)->count());
        $lastMsg = end($this->sentMessages);
        $this->assertStringContainsString('Due date is required', $lastMsg['data']['text'] ?? '');
    }

    public function test_dube_repayment_via_bank_and_wallet_with_short_callbacks_under_64_bytes(): void
    {
        $customer = $this->customerService->upsertCustomer($this->shop->id, (string) Str::uuid7(), 'Almaz Kebede', '+251911998877')['customer'];

        Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'customer_id' => $customer->id,
            'total_cents' => 50000, // 500.00 ETB
            'due_date' => Carbon::now()->addDays(10)->toDateString(),
            'created_at_ms' => 1000,
            'updated_at_ms' => 1000,
        ]);

        // 1. Choose Full Balance
        $this->postCallback($this->cashier, "dube:pay_amt:{$customer->id}:full")->assertOk();

        // Check that callback_data in method buttons are under 64 bytes
        $lastMsg = end($this->sentMessages);
        $markup = is_array($lastMsg['data']['reply_markup'] ?? null)
            ? $lastMsg['data']['reply_markup']
            : json_decode($lastMsg['data']['reply_markup'] ?? '{}', true);
        $this->assertNotEmpty($markup['inline_keyboard']);
        foreach ($markup['inline_keyboard'] as $row) {
            foreach ($row as $btn) {
                $this->assertLessThanOrEqual(64, strlen($btn['callback_data']), "Button callback exceeds 64 bytes: {$btn['callback_data']}");
            }
        }

        // 2. Tap Bank
        $this->postCallback($this->cashier, 'dube:pay_m:bank')->assertOk();

        // Check that all provider buttons are under 64 bytes
        $lastMsg = end($this->sentMessages);
        $markup = is_array($lastMsg['data']['reply_markup'] ?? null)
            ? $lastMsg['data']['reply_markup']
            : json_decode($lastMsg['data']['reply_markup'] ?? '{}', true);
        $this->assertNotEmpty($markup['inline_keyboard']);
        foreach ($markup['inline_keyboard'] as $row) {
            foreach ($row as $btn) {
                $this->assertLessThanOrEqual(64, strlen($btn['callback_data']), "Provider button callback exceeds 64 bytes: {$btn['callback_data']}");
            }
        }

        // 3. Pick CBE Bank
        $this->postCallback($this->cashier, 'dube:rpay_prov:bank.cbe')->assertOk();

        // Check that skip reference button is under 64 bytes
        $lastMsg = end($this->sentMessages);
        $markup = is_array($lastMsg['data']['reply_markup'] ?? null)
            ? $lastMsg['data']['reply_markup']
            : json_decode($lastMsg['data']['reply_markup'] ?? '{}', true);
        $this->assertNotEmpty($markup['inline_keyboard']);
        foreach ($markup['inline_keyboard'] as $row) {
            foreach ($row as $btn) {
                $this->assertLessThanOrEqual(64, strlen($btn['callback_data']), "Skip button callback exceeds 64 bytes: {$btn['callback_data']}");
            }
        }

        // 4. Tap Skip Reference
        $this->postCallback($this->cashier, 'dube:rpay_skip_ref')->assertOk();

        // Verify Bank payment recorded successfully
        $bankPayment = Payment::withoutGlobalScope(ShopScope::class)
            ->where('customer_id', $customer->id)
            ->where('method', 'bank')
            ->first();
        $this->assertNotNull($bankPayment);
        $this->assertSame('bank.cbe', $bankPayment->provider_key);
        $this->assertSame(50000, $bankPayment->amount_cents);
        $this->assertNull($bankPayment->reference);

        // 5. Test Wallet Repayment with reference
        Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shop->id,
            'location_id' => $this->location->id,
            'customer_id' => $customer->id,
            'total_cents' => 30000, // 300.00 ETB
            'due_date' => Carbon::now()->addDays(5)->toDateString(),
            'created_at_ms' => 2000,
            'updated_at_ms' => 2000,
        ]);

        $this->postCallback($this->cashier, "dube:pay_amt:{$customer->id}:full")->assertOk();
        $this->postCallback($this->cashier, 'dube:pay_m:wallet')->assertOk();
        $this->postCallback($this->cashier, 'dube:rpay_prov:wallet.telebirr')->assertOk();
        $this->postMessage($this->cashier, 'TXN99887766')->assertOk();

        $walletPayment = Payment::withoutGlobalScope(ShopScope::class)
            ->where('customer_id', $customer->id)
            ->where('method', 'wallet')
            ->first();
        $this->assertNotNull($walletPayment);
        $this->assertSame('wallet.telebirr', $walletPayment->provider_key);
        $this->assertSame(30000, $walletPayment->amount_cents);
        $this->assertSame('TXN99887766', $walletPayment->reference);
    }
}
