<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use App\Services\LedgerService;
use App\Services\PairingService;
use App\Services\PaymentReportService;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class PaymentsAndDubeTest extends TestCase
{
    use RefreshDatabase;

    protected PairingService $pairing;
    protected LedgerService $ledger;
    protected BalanceService $balanceService;
    protected CustomerService $customerService;
    protected PaymentReportService $reportService;
    protected PermissionService $permissionService;

    protected array $shopA;
    protected array $shopB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pairing = app(PairingService::class);
        $this->ledger = app(LedgerService::class);
        $this->balanceService = app(BalanceService::class);
        $this->customerService = app(CustomerService::class);
        $this->reportService = app(PaymentReportService::class);
        $this->permissionService = app(PermissionService::class);

        // Setup Shop A with Owner (device 1) and Staff (device 2)
        $pairA1 = $this->pairing->startPairing('Bole Main Store', 'Owner Phone');
        $confirmA1 = $this->pairing->confirmPairing($pairA1['code'], 10001, 'Owner A');
        $tokenA1 = $this->pairing->getPairingStatus($pairA1['code'])['device_token'];

        $pairA2 = $this->pairing->startPairing(null, 'Staff Phone');
        $confirmA2 = $this->pairing->confirmPairing($pairA2['code'], 10001, 'Owner A');
        $tokenA2 = $this->pairing->getPairingStatus($pairA2['code'])['device_token'];

        // Assign separate salesperson user to device 2
        $staffUser = User::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'telegram_id' => 10002,
            'name' => 'Staff Member A2',
            'language' => 'en',
            'shop_id' => $confirmA1['shop']->id,
            'role' => 'salesperson',
            'is_active' => true,
        ]);
        $device2 = $confirmA2['device'];
        $device2->user_id = $staffUser->id;
        $device2->save();

        $this->shopA = [
            'shop' => $confirmA1['shop'],
            'owner_user' => $confirmA1['device']->user,
            'owner_device' => $confirmA1['device'],
            'owner_token' => $tokenA1,
            'staff_user' => $staffUser,
            'staff_device' => $device2,
            'staff_token' => $tokenA2,
            'location' => Location::withoutGlobalScope(ShopScope::class)->where('shop_id', $confirmA1['shop']->id)->first(),
        ];

        // Setup Shop B with Owner
        $pairB1 = $this->pairing->startPairing('Hawassa Store', 'Owner B Phone');
        $confirmB1 = $this->pairing->confirmPairing($pairB1['code'], 20002, 'Owner B');
        $tokenB1 = $this->pairing->getPairingStatus($pairB1['code'])['device_token'];

        $this->shopB = [
            'shop' => $confirmB1['shop'],
            'owner_user' => $confirmB1['device']->user,
            'owner_device' => $confirmB1['device'],
            'owner_token' => $tokenB1,
            'location' => Location::withoutGlobalScope(ShopScope::class)->where('shop_id', $confirmB1['shop']->id)->first(),
        ];
    }

    /**
     * Helper to create a product in Shop A.
     */
    protected function createProduct(string $name = 'Sugar 1kg', int $sellPrice = 150): Product
    {
        $id = (string) Str::uuid7();
        $p = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => $id,
            'shop_id' => $this->shopA['shop']->id,
            'name' => $name,
            'unit' => 'pcs',
            'cost_price_cents' => 100,
            'sell_price_cents' => $sellPrice,
            'is_active' => true,
            'updated_at_ms' => 1730000000000,
        ]);

        return $p;
    }

    /**
     * Helper to create a customer in Shop A.
     */
    protected function createCustomer(string $name = 'Abebe Kebede', string $phone = '0911223344'): Customer
    {
        $res = $this->customerService->upsertCustomer(
            (string) $this->shopA['shop']->id,
            (string) Str::uuid7(),
            $name,
            $phone,
            'VIP Customer'
        );

        return $res['customer'];
    }

    public function test_line_item_half_up_rounding_and_total_mismatch_rejection(): void
    {
        $p1 = $this->createProduct('Item A', 150);
        $p2 = $this->createProduct('Item B', 105);

        $saleId = (string) Str::uuid7();
        $mov1Id = (string) Str::uuid7();
        $mov2Id = (string) Str::uuid7();
        $payId = (string) Str::uuid7();

        // Line 1: 1.333 @ 150 = 199.95 -> 200 cents
        // Line 2: 0.500 @ 105 = 52.5 -> 53 cents (half-up)
        // Correct total = 253 cents.

        // First attempt with incorrect total (250 cents) -> should be rejected with total_mismatch
        $badRes = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $saleId,
                    'entity' => 'sale',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $saleId,
                        'location_id' => $this->shopA['location']->id,
                        'total_cents' => 250, // MISMATCH
                        'created_at' => 1730000000000,
                    ],
                ],
                [
                    'id' => $mov1Id,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $mov1Id,
                        'sale_id' => $saleId,
                        'product_id' => $p1->id,
                        'location_id' => $this->shopA['location']->id,
                        'type' => 'sale',
                        'qty' => '-1.333',
                        'unit_price_cents' => 150,
                    ],
                ],
                [
                    'id' => $mov2Id,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $mov2Id,
                        'sale_id' => $saleId,
                        'product_id' => $p2->id,
                        'location_id' => $this->shopA['location']->id,
                        'type' => 'sale',
                        'qty' => '-0.500',
                        'unit_price_cents' => 105,
                    ],
                ],
                [
                    'id' => $payId,
                    'entity' => 'payment',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $payId,
                        'sale_id' => $saleId,
                        'location_id' => $this->shopA['location']->id,
                        'method' => 'cash',
                        'amount_cents' => 250,
                    ],
                ],
            ],
        ]);

        $badRes->assertStatus(200);
        $rejected = collect($badRes->json('rejected'));
        $this->assertTrue($rejected->contains('id', $saleId));
        $saleRejection = $rejected->firstWhere('id', $saleId);
        $this->assertSame('total_mismatch', $saleRejection['reason']);

        // Second attempt with exact rounded total (253 cents) and cash payment
        $saleId2 = (string) Str::uuid7();
        $mov1Id2 = (string) Str::uuid7();
        $mov2Id2 = (string) Str::uuid7();
        $payId2 = (string) Str::uuid7();

        $goodRes = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $saleId2,
                    'entity' => 'sale',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $saleId2,
                        'location_id' => $this->shopA['location']->id,
                        'total_cents' => 253, // EXACT
                        'created_at' => 1730000000000,
                    ],
                ],
                [
                    'id' => $mov1Id2,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $mov1Id2,
                        'sale_id' => $saleId2,
                        'product_id' => $p1->id,
                        'location_id' => $this->shopA['location']->id,
                        'type' => 'sale',
                        'qty' => '-1.333',
                        'unit_price_cents' => 150,
                    ],
                ],
                [
                    'id' => $mov2Id2,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $mov2Id2,
                        'sale_id' => $saleId2,
                        'product_id' => $p2->id,
                        'location_id' => $this->shopA['location']->id,
                        'type' => 'sale',
                        'qty' => '-0.500',
                        'unit_price_cents' => 105,
                    ],
                ],
                [
                    'id' => $payId2,
                    'entity' => 'payment',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $payId2,
                        'sale_id' => $saleId2,
                        'location_id' => $this->shopA['location']->id,
                        'method' => 'cash',
                        'amount_cents' => 253,
                    ],
                ],
            ],
        ]);

        $goodRes->assertStatus(200);
        $this->assertContains($saleId2, $goodRes->json('accepted'));
        $this->assertContains($mov1Id2, $goodRes->json('accepted'));
        $this->assertContains($mov2Id2, $goodRes->json('accepted'));
        $this->assertContains($payId2, $goodRes->json('accepted'));

        $this->assertSame(0, $this->balanceService->saleBalance($saleId2));
    }

    public function test_partial_payment_plus_dube_creation(): void
    {
        $product = $this->createProduct('Cooking Oil 5L', 100000);
        $customer = $this->createCustomer('Fatuma Ahmed', '0922334455');

        $saleId = (string) Str::uuid7();
        $movId = (string) Str::uuid7();
        $payId = (string) Str::uuid7();

        // 100,000 cents sale with 40,000 upfront payment via Telebirr
        $res = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $saleId,
                    'entity' => 'sale',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $saleId,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'total_cents' => 100000,
                        'due_date' => '2026-11-20',
                        'created_at' => 1730000000000,
                    ],
                ],
                [
                    'id' => $movId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $movId,
                        'sale_id' => $saleId,
                        'product_id' => $product->id,
                        'location_id' => $this->shopA['location']->id,
                        'type' => 'sale',
                        'qty' => '-1.000',
                        'unit_price_cents' => 100000,
                    ],
                ],
                [
                    'id' => $payId,
                    'entity' => 'payment',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $payId,
                        'sale_id' => $saleId,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'method' => 'wallet',
                        'provider_key' => 'wallet.telebirr',
                        'amount_cents' => 40000,
                    ],
                ],
            ],
        ]);

        $res->assertStatus(200);
        $this->assertContains($saleId, $res->json('accepted'));
        $this->assertContains($movId, $res->json('accepted'));
        $this->assertContains($payId, $res->json('accepted'));

        // Sale balance should be 60,000 cents
        $this->assertSame(60000, $this->balanceService->saleBalance($saleId));
        // Customer total balance should be 60,000 cents
        $this->assertSame(60000, $this->balanceService->customerBalance($customer));
    }

    public function test_dube_sale_validations_missing_customer_and_due_date(): void
    {
        $product = $this->createProduct('Item X', 50000);

        // 1. Missing customer on unpaid sale
        $saleId1 = (string) Str::uuid7();
        $res1 = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $saleId1,
                    'entity' => 'sale',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $saleId1,
                        'location_id' => $this->shopA['location']->id,
                        'total_cents' => 50000,
                        'due_date' => '2026-11-20',
                        // NO customer_id
                    ],
                ],
            ],
        ]);
        $res1->assertStatus(200);
        $this->assertSame('missing_customer', $res1->json('rejected.0.reason'));

        // 2. Missing due date on unpaid sale
        $customer = $this->createCustomer('Test Customer', '0911000000');
        $saleId2 = (string) Str::uuid7();
        $res2 = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $saleId2,
                    'entity' => 'sale',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $saleId2,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'total_cents' => 50000,
                        // NO due_date
                    ],
                ],
            ],
        ]);
        $res2->assertStatus(200);
        $this->assertSame('missing_due_date', $res2->json('rejected.0.reason'));

        // 3. Due date earlier than sale creation date
        $saleId3 = (string) Str::uuid7();
        $res3 = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $saleId3,
                    'entity' => 'sale',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000, // 2024-10-27
                    'data' => [
                        'id' => $saleId3,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'total_cents' => 50000,
                        'due_date' => '2020-01-01', // PAST DATE
                        'created_at' => 1730000000000,
                    ],
                ],
            ],
        ]);
        $res3->assertStatus(200);
        $this->assertSame('invalid_due_date', $res3->json('rejected.0.reason'));
    }

    public function test_dube_permission_check(): void
    {
        $customer = $this->createCustomer('Dube Perm Test', '0911555555');
        $product = $this->createProduct('Item Dube', 50000);
        $saleId = (string) Str::uuid7();
        $movId = (string) Str::uuid7();

        // Staff without sale.dube permission attempts to create a dube sale
        $res = $this->withToken($this->shopA['staff_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $saleId,
                    'entity' => 'sale',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $saleId,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'total_cents' => 50000,
                        'due_date' => '2026-11-20',
                        'created_at' => 1730000000000,
                    ],
                ],
            ],
        ]);

        $res->assertStatus(200);
        $this->assertSame('forbidden_permission', $res->json('rejected.0.reason'));

        // Grant sale.dube permission to staff user
        $this->permissionService->updatePermissions(
            $this->shopA['staff_user'],
            ['sale.dube' => 'allow'],
            null,
            null,
            $this->shopA['owner_user']
        );

        $nowMs = (int) (microtime(true) * 1000);
        $res2 = $this->withToken($this->shopA['staff_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $saleId,
                    'entity' => 'sale',
                    'op' => 'upsert',
                    'client_ts' => $nowMs,
                    'data' => [
                        'id' => $saleId,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'total_cents' => 50000,
                        'due_date' => '2026-11-20',
                        'created_at' => $nowMs,
                    ],
                ],
                [
                    'id' => $movId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => $nowMs,
                    'data' => [
                        'id' => $movId,
                        'sale_id' => $saleId,
                        'product_id' => $product->id,
                        'location_id' => $this->shopA['location']->id,
                        'type' => 'sale',
                        'qty' => '-1.000',
                        'unit_price_cents' => 50000,
                        'created_at' => $nowMs,
                    ],
                ],
            ],
        ]);

        $res2->assertStatus(200);
        $this->assertContains($saleId, $res2->json('accepted'));
        $this->assertContains($movId, $res2->json('accepted'));
    }

    public function test_fifo_allocation_across_multiple_sales(): void
    {
        $customer = $this->createCustomer('Chala Bekele', '0933445566');

        // Sale 1: 50,000 cents at t=1000
        $sale1 = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shopA['shop']->id,
            'location_id' => $this->shopA['location']->id,
            'customer_id' => $customer->id,
            'total_cents' => 50000,
            'due_date' => '2026-11-10',
            'created_at_ms' => 1730000010000,
            'updated_at_ms' => 1730000010000,
        ]);

        // Sale 2: 80,000 cents at t=2000
        $sale2 = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shopA['shop']->id,
            'location_id' => $this->shopA['location']->id,
            'customer_id' => $customer->id,
            'total_cents' => 80000,
            'due_date' => '2026-11-15',
            'created_at_ms' => 1730000020000,
            'updated_at_ms' => 1730000020000,
        ]);

        // Total initial debt = 130,000 cents
        $this->assertSame(130000, $this->balanceService->customerBalance($customer));

        // Ingest repayment of 70,000 cents (with customer_id, no sale_id)
        $payId = (string) Str::uuid7();
        $res = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $payId,
                    'entity' => 'payment',
                    'op' => 'upsert',
                    'client_ts' => 1730000030000,
                    'data' => [
                        'id' => $payId,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'method' => 'bank',
                        'provider_key' => 'bank.cbe',
                        'amount_cents' => 70000,
                    ],
                ],
            ],
        ]);

        $res->assertStatus(200);
        $this->assertContains($payId, $res->json('accepted'));

        // Under FIFO:
        // Sale 1 gets 50,000 allocated -> balance is 0
        // Sale 2 gets 20,000 allocated -> balance is 60,000
        // Customer total remaining balance is 60,000
        $this->assertSame(0, $this->balanceService->saleBalance($sale1));
        $this->assertSame(60000, $this->balanceService->saleBalance($sale2));
        $this->assertSame(60000, $this->balanceService->customerBalance($customer));

        $openSales = $this->balanceService->openSales($customer);
        $this->assertCount(1, $openSales);
        $this->assertSame($sale2->id, $openSales->first()['sale']->id);
        $this->assertSame(60000, $openSales->first()['remaining_balance_cents']);
    }

    public function test_overpayment_flag_and_negative_credit(): void
    {
        $customer = $this->createCustomer('Mulugeta Tesfaye', '0966778899');

        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shopA['shop']->id,
            'location_id' => $this->shopA['location']->id,
            'customer_id' => $customer->id,
            'total_cents' => 50000,
            'due_date' => '2026-11-10',
            'created_at_ms' => 1730000010000,
            'updated_at_ms' => 1730000010000,
        ]);

        // Repayment of 65,000 cents arrives via sync
        $payId = (string) Str::uuid7();
        $res = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $payId,
                    'entity' => 'payment',
                    'op' => 'upsert',
                    'client_ts' => 1730000030000,
                    'data' => [
                        'id' => $payId,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'method' => 'bank',
                        'provider_key' => 'bank.awash',
                        'amount_cents' => 65000,
                    ],
                ],
            ],
        ]);

        $res->assertStatus(200);
        $this->assertContains($payId, $res->json('accepted'));

        $credit = $this->balanceService->customerCredit($customer);
        $this->assertTrue($credit['has_overpayment']);
        $this->assertSame(15000, $credit['overpayment_cents']);
        $this->assertSame(-15000, $credit['net_balance_cents']);
        $this->assertSame(0, $credit['total_debt_cents']);
    }

    public function test_online_callers_blocked_from_paying_more_than_balance(): void
    {
        $customer = $this->createCustomer('Online Payer', '0911777777');

        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shopA['shop']->id,
            'location_id' => $this->shopA['location']->id,
            'customer_id' => $customer->id,
            'total_cents' => 50000,
            'due_date' => '2026-11-10',
            'created_at_ms' => 1730000010000,
            'updated_at_ms' => 1730000010000,
        ]);

        // Attempt to pay 60,000 on a 50,000 sale online -> should throw InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds sale balance');

        $this->balanceService->recordOnlinePayment(
            (string) $this->shopA['shop']->id,
            (string) $this->shopA['owner_user']->id,
            'cash',
            60000,
            saleId: $sale->id
        );
    }

    public function test_online_payment_succeeds_for_exact_or_partial_amount(): void
    {
        $customer = $this->createCustomer('Online Payer 2', '0911888888');

        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shopA['shop']->id,
            'location_id' => $this->shopA['location']->id,
            'customer_id' => $customer->id,
            'total_cents' => 50000,
            'due_date' => '2026-11-10',
            'created_at_ms' => 1730000010000,
            'updated_at_ms' => 1730000010000,
        ]);

        $payment = $this->balanceService->recordOnlinePayment(
            (string) $this->shopA['shop']->id,
            (string) $this->shopA['owner_user']->id,
            'cash',
            30000,
            saleId: $sale->id
        );

        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertSame(30000, $payment->amount_cents);
        $this->assertSame(20000, $this->balanceService->saleBalance($sale));
    }

    public function test_two_devices_repaying_concurrently(): void
    {
        $customer = $this->createCustomer('Concurrent Repayer', '0911999999');

        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shopA['shop']->id,
            'location_id' => $this->shopA['location']->id,
            'customer_id' => $customer->id,
            'total_cents' => 100000,
            'due_date' => '2026-11-10',
            'created_at_ms' => 1730000010000,
            'updated_at_ms' => 1730000010000,
        ]);

        $pay1Id = (string) Str::uuid7();
        $pay2Id = (string) Str::uuid7();

        // Grant payment.collect permission to staff user
        $this->permissionService->updatePermissions(
            $this->shopA['staff_user'],
            ['payment.collect' => 'allow'],
            null,
            null,
            $this->shopA['owner_user']
        );

        $nowMs = (int) (microtime(true) * 1000);

        // Device 1 syncs 40,000 repayment
        $res1 = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $pay1Id,
                    'entity' => 'payment',
                    'op' => 'upsert',
                    'client_ts' => $nowMs,
                    'data' => [
                        'id' => $pay1Id,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'method' => 'cash',
                        'amount_cents' => 40000,
                    ],
                ],
            ],
        ]);
        $res1->assertStatus(200);

        // Device 2 syncs 50,000 repayment
        $res2 = $this->withToken($this->shopA['staff_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $pay2Id,
                    'entity' => 'payment',
                    'op' => 'upsert',
                    'client_ts' => $nowMs,
                    'data' => [
                        'id' => $pay2Id,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'method' => 'cash',
                        'amount_cents' => 50000,
                    ],
                ],
            ],
        ]);
        $res2->assertStatus(200);

        // Both accepted into append-only ledger
        $this->assertContains($pay1Id, $res1->json('accepted'));
        $this->assertContains($pay2Id, $res2->json('accepted'));

        // Balance is 100,000 - 40,000 - 50,000 = 10,000
        $this->assertSame(10000, $this->balanceService->saleBalance($sale));
        $this->assertSame(10000, $this->balanceService->customerBalance($customer));
    }

    public function test_void_flow_reverses_payments_and_compensates_movements(): void
    {
        $product = $this->createProduct('Item To Void', 50000);
        $customer = $this->createCustomer('Void Customer', '0911001122');
        $saleId = (string) Str::uuid7();
        $movId = (string) Str::uuid7();
        $payId = (string) Str::uuid7();

        $nowMs = (int) (microtime(true) * 1000);

        // Create sale with movement and payment
        $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $saleId,
                    'entity' => 'sale',
                    'op' => 'upsert',
                    'client_ts' => $nowMs,
                    'data' => [
                        'id' => $saleId,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'total_cents' => 50000,
                        'created_at' => $nowMs,
                    ],
                ],
                [
                    'id' => $movId,
                    'entity' => 'movement',
                    'op' => 'upsert',
                    'client_ts' => $nowMs,
                    'data' => [
                        'id' => $movId,
                        'sale_id' => $saleId,
                        'product_id' => $product->id,
                        'location_id' => $this->shopA['location']->id,
                        'type' => 'sale',
                        'qty' => '-1.000',
                        'unit_price_cents' => 50000,
                    ],
                ],
                [
                    'id' => $payId,
                    'entity' => 'payment',
                    'op' => 'upsert',
                    'client_ts' => $nowMs,
                    'data' => [
                        'id' => $payId,
                        'sale_id' => $saleId,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'method' => 'cash',
                        'amount_cents' => 50000,
                    ],
                ],
            ],
        ]);

        $sale = Sale::withoutGlobalScope(ShopScope::class)->find($saleId);
        $this->assertFalse($sale->isVoided());

        // Perform void
        $this->balanceService->voidSale($sale, $this->shopA['owner_user']);

        $sale->refresh();
        $this->assertTrue($sale->isVoided());

        // Verify reversal payment created
        $reversalPayment = Payment::withoutGlobalScope(ShopScope::class)
            ->where('sale_id', $saleId)
            ->where('amount_cents', '<', 0)
            ->first();

        $this->assertNotNull($reversalPayment);
        $this->assertSame(-50000, $reversalPayment->amount_cents);

        // Verify compensating movement created
        $compensatingMov = Movement::withoutGlobalScope(ShopScope::class)
            ->where('sale_id', $saleId)
            ->where('type', 'adjust')
            ->first();

        $this->assertNotNull($compensatingMov);
        $this->assertSame(1000, $compensatingMov->qty_milli);

        // Balances clear
        $this->assertSame(0, $this->balanceService->saleBalance($sale));
        $this->assertSame(0, $this->balanceService->customerBalance($customer));
    }

    public function test_void_authorization_and_10_minute_window(): void
    {
        $customer = $this->createCustomer('Void Window Cust', '0911003344');

        // Sale created 15 minutes ago by staff
        $fifteenMinutesAgo = (int) (microtime(true) * 1000) - 900000;
        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shopA['shop']->id,
            'location_id' => $this->shopA['location']->id,
            'user_id' => $this->shopA['staff_user']->id,
            'customer_id' => $customer->id,
            'total_cents' => 50000,
            'due_date' => '2026-11-20',
            'created_at_ms' => $fifteenMinutesAgo,
            'updated_at_ms' => $fifteenMinutesAgo,
        ]);

        // Staff attempts to void after 10 minutes -> should fail
        try {
            $this->balanceService->voidSale($sale, $this->shopA['staff_user']);
            $this->fail('Expected exception for void window expired.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Void window expired', $e->getMessage());
        }

        // Owner can void anytime
        $this->balanceService->voidSale($sale, $this->shopA['owner_user']);
        $this->assertTrue($sale->fresh()->isVoided());
    }

    public function test_void_blocked_for_creator_if_payments_by_others_exist(): void
    {
        $customer = $this->createCustomer('Void Conflict Cust', '0911005566');

        $twoMinutesAgo = (int) (microtime(true) * 1000) - 120000;
        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shopA['shop']->id,
            'location_id' => $this->shopA['location']->id,
            'user_id' => $this->shopA['staff_user']->id,
            'customer_id' => $customer->id,
            'total_cents' => 50000,
            'due_date' => '2026-11-20',
            'created_at_ms' => $twoMinutesAgo,
            'updated_at_ms' => $twoMinutesAgo,
        ]);

        // Another user (owner) records a payment on this sale
        Payment::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shopA['shop']->id,
            'sale_id' => $sale->id,
            'customer_id' => $customer->id,
            'location_id' => $this->shopA['location']->id,
            'user_id' => $this->shopA['owner_user']->id, // OTHER USER
            'method' => 'cash',
            'amount_cents' => 20000,
            'received_at_ms' => $twoMinutesAgo,
            'created_at_ms' => $twoMinutesAgo,
            'server_seq' => 1,
        ]);

        // Staff creator attempts to void -> should fail because payment was made by other user
        try {
            $this->balanceService->voidSale($sale, $this->shopA['staff_user']);
            $this->fail('Expected exception for void with payments by others.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('payments have been recorded by other staff', $e->getMessage());
        }

        // Owner can still void
        $this->balanceService->voidSale($sale, $this->shopA['owner_user']);
        $this->assertTrue($sale->fresh()->isVoided());
    }

    public function test_provider_key_constraints_and_custom_provider(): void
    {
        $customer = $this->createCustomer('Provider Test Cust', '0911007788');

        // 1. Cash method with provider_key -> rejected
        $payId1 = (string) Str::uuid7();
        $res1 = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $payId1,
                    'entity' => 'payment',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $payId1,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'method' => 'cash',
                        'provider_key' => 'bank.cbe', // FORBIDDEN
                        'amount_cents' => 10000,
                    ],
                ],
            ],
        ]);
        $res1->assertStatus(200);
        $this->assertSame('cash_forbids_provider_key', $res1->json('rejected.0.reason'));

        // 2. Bank method without provider_key -> rejected
        $payId2 = (string) Str::uuid7();
        $res2 = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $payId2,
                    'entity' => 'payment',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $payId2,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'method' => 'bank',
                        // NO provider_key
                        'amount_cents' => 10000,
                    ],
                ],
            ],
        ]);
        $res2->assertStatus(200);
        $this->assertSame('missing_provider_key', $res2->json('rejected.0.reason'));

        // 3. Bank method with unknown provider_key -> rejected
        $payId3 = (string) Str::uuid7();
        $res3 = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $payId3,
                    'entity' => 'payment',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $payId3,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'method' => 'bank',
                        'provider_key' => 'bank.non_existent_bank_123',
                        'amount_cents' => 10000,
                    ],
                ],
            ],
        ]);
        $res3->assertStatus(200);
        $this->assertSame('unknown_reference', $res3->json('rejected.0.reason'));

        // 4. Custom provider created and used
        $customKey = 'custom.' . (string) Str::uuid7();
        $resProv = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $customKey,
                    'entity' => 'payment_provider',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'provider_key' => $customKey,
                        'kind' => 'bank',
                        'name' => 'My Microfinance S.C.',
                        'enabled' => true,
                        'favorite' => true,
                    ],
                ],
            ],
        ]);
        $resProv->assertStatus(200);
        $this->assertContains($customKey, $resProv->json('accepted'));

        $payId4 = (string) Str::uuid7();
        $res4 = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $payId4,
                    'entity' => 'payment',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $payId4,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'method' => 'bank',
                        'provider_key' => $customKey,
                        'amount_cents' => 10000,
                    ],
                ],
            ],
        ]);
        $res4->assertStatus(200);
        $this->assertContains($payId4, $res4->json('accepted'));
    }

    public function test_pull_filtering_without_dube_view(): void
    {
        $customer = $this->createCustomer('Secret Customer', '0911444444');

        // Owner creates Sale A
        $saleAId = (string) Str::uuid7();
        $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $saleAId,
                    'entity' => 'sale',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $saleAId,
                        'customer_id' => $customer->id,
                        'location_id' => $this->shopA['location']->id,
                        'total_cents' => 50000,
                        'due_date' => '2026-11-20',
                        'note' => 'Confidential note from owner',
                    ],
                ],
            ],
        ]);

        // Staff (who has NO dube.view permission) pulls changes
        $pullRes = $this->withToken($this->shopA['staff_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [],
        ]);

        $pullRes->assertStatus(200);
        $pulledChanges = collect($pullRes->json('changes'));

        // 1. Staff must NOT receive Sale A because they did not create it and lack dube.view
        $saleChanges = $pulledChanges->where('entity', 'sale');
        $this->assertFalse($saleChanges->contains('id', $saleAId));

        // 2. Staff receives customer with name and phone, but customer's note is stripped
        $customerChange = $pulledChanges->firstWhere('entity', 'customer');
        if ($customerChange !== null) {
            $this->assertArrayNotHasKey('note', $customerChange['data']);
        }
    }

    public function test_cross_shop_isolation(): void
    {
        $custB = Customer::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shopB['shop']->id,
            'name' => 'Shop B Customer',
            'phone' => '+251911998877',
            'created_at_ms' => 1730000000000,
            'updated_at_ms' => 1730000000000,
        ]);

        // Device in Shop A attempts to create a sale linked to Shop B's customer
        $saleId = (string) Str::uuid7();
        $res = $this->withToken($this->shopA['owner_token'])->postJson('/v1/sync', [
            'cursor' => 0,
            'changes' => [
                [
                    'id' => $saleId,
                    'entity' => 'sale',
                    'op' => 'upsert',
                    'client_ts' => 1730000000000,
                    'data' => [
                        'id' => $saleId,
                        'customer_id' => $custB->id, // Shop B customer!
                        'location_id' => $this->shopA['location']->id,
                        'total_cents' => 50000,
                        'due_date' => '2026-11-20',
                    ],
                ],
            ],
        ]);

        $res->assertStatus(200);
        $this->assertSame('unknown_reference', $res->json('rejected.0.reason'));
    }

    public function test_customer_phone_normalization_and_soft_delete(): void
    {
        $shopId = (string) $this->shopA['shop']->id;

        // Verify normalization formats
        $this->assertSame('+251911223344', CustomerService::normalizePhone('0911223344'));
        $this->assertSame('+251711223344', CustomerService::normalizePhone('0711223344'));
        $this->assertSame('+251911223344', CustomerService::normalizePhone('+251911223344'));
        $this->assertSame('+251911223344', CustomerService::normalizePhone('251911223344'));
        $this->assertSame('+251911223344', CustomerService::normalizePhone('00251911223344'));
        $this->assertSame('+251911223344', CustomerService::normalizePhone('0911-22-33-44'));

        // Duplicate phone check
        $c1 = $this->customerService->upsertCustomer($shopId, (string) Str::uuid7(), 'User One', '0911223344');
        $this->assertFalse($c1['duplicate_phone']);

        $c2 = $this->customerService->upsertCustomer($shopId, (string) Str::uuid7(), 'User Two', '0911223344');
        $this->assertTrue($c2['duplicate_phone']); // duplicate warning is true, but not blocked!

        // Soft delete allowed only when balance is zero
        // 1. Create open sale for c1
        Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $shopId,
            'location_id' => $this->shopA['location']->id,
            'customer_id' => $c1['customer']->id,
            'total_cents' => 50000,
            'due_date' => '2026-11-20',
            'created_at_ms' => 1730000000000,
            'updated_at_ms' => 1730000000000,
        ]);

        try {
            $this->customerService->softDeleteCustomer($c1['customer']);
            $this->fail('Expected exception for deleting customer with non-zero balance.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('outstanding balance is 50000 cents', $e->getMessage());
        }

        // c2 has zero balance -> deletion succeeds
        $this->customerService->softDeleteCustomer($c2['customer']);
        $this->assertNotNull($c2['customer']->fresh()->deleted_at_ms);
    }

    public function test_payment_reports(): void
    {
        $shop = $this->shopA['shop'];
        $customer = $this->createCustomer('Report Customer', '0911333333');

        $nowMs = (int) (microtime(true) * 1000);
        $oneDayAgoMs = $nowMs - 86400000;
        $range = [$oneDayAgoMs - 1000, $nowMs + 1000];

        // 1. Sale with partial Telebirr upfront payment
        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $shop->id,
            'location_id' => $this->shopA['location']->id,
            'customer_id' => $customer->id,
            'total_cents' => 100000,
            'due_date' => Carbon::now()->subDays(2)->toDateString(), // OVERDUE
            'created_at_ms' => $nowMs,
            'updated_at_ms' => $nowMs,
        ]);

        Payment::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $shop->id,
            'sale_id' => $sale->id,
            'customer_id' => $customer->id,
            'location_id' => $this->shopA['location']->id,
            'method' => 'wallet',
            'provider_key' => 'wallet.telebirr',
            'amount_cents' => 40000,
            'received_at_ms' => $nowMs,
            'created_at_ms' => $nowMs,
            'server_seq' => 1,
        ]);

        // 2. Separate cash payment
        Payment::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $shop->id,
            'sale_id' => null,
            'customer_id' => $customer->id,
            'location_id' => $this->shopA['location']->id,
            'method' => 'cash',
            'provider_key' => null,
            'amount_cents' => 20000,
            'received_at_ms' => $nowMs,
            'created_at_ms' => $nowMs,
            'server_seq' => 2,
        ]);

        // Test receivedByMethod
        $byMethod = $this->reportService->receivedByMethod($shop, $range);
        $this->assertSame(20000, $byMethod['by_method']['cash']);
        $this->assertSame(40000, $byMethod['by_method']['wallet']);
        $this->assertSame(60000, $byMethod['grand_total_cents']);

        // Test salesTotals
        $salesTotals = $this->reportService->salesTotals($shop, $range);
        $this->assertSame(100000, $salesTotals['revenue_cents']);
        $this->assertSame(60000, $salesTotals['money_received_cents']);
        $this->assertSame(60000, $salesTotals['new_dube_cents']);

        // Test outstandingDube
        $outstanding = $this->reportService->outstandingDube($shop);
        $this->assertSame(40000, $outstanding['total_outstanding_cents']);
        $this->assertSame(1, $outstanding['total_customers_with_dube']);

        // Test overdueList
        $overdue = $this->reportService->overdueList($shop);
        $this->assertCount(1, $overdue);
        $this->assertSame(40000, $overdue->first()['remaining_balance_cents']);
        $this->assertGreaterThanOrEqual(1, $overdue->first()['days_overdue']);

        // Test customerStatement
        $statement = $this->reportService->customerStatement($customer);
        $this->assertSame(100000, $statement['total_sales_cents']);
        $this->assertSame(60000, $statement['total_payments_cents']);
        $this->assertSame(40000, $statement['net_balance_cents']);
        $this->assertCount(3, $statement['transactions']);

        // Test cashExpected
        $cashExp = $this->reportService->cashExpected($shop, Carbon::now());
        $this->assertSame(20000, $cashExp['expected_cash_cents']);
    }

    public function test_legacy_sales_backfill_and_treatment_as_fully_paid(): void
    {
        $shop = $this->shopA['shop'];
        $product = $this->createProduct('Legacy Item', 200);

        // Insert legacy sale movements without sale_id in minute bucket 1000
        $t1 = 60000 * 1000 + 5000;
        $m1Id = (string) Str::uuid7();
        $m2Id = (string) Str::uuid7();

        DB::table('movements')->insert([
            [
                'id' => $m1Id,
                'shop_id' => $shop->id,
                'product_id' => $product->id,
                'location_id' => $this->shopA['location']->id,
                'user_id' => $this->shopA['owner_user']->id,
                'type' => 'sale',
                'qty_milli' => -2000,
                'unit_price_cents' => 200,
                'sale_id' => null,
                'server_seq' => 1,
                'created_at_ms' => $t1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $m2Id,
                'shop_id' => $shop->id,
                'product_id' => $product->id,
                'location_id' => $this->shopA['location']->id,
                'user_id' => $this->shopA['owner_user']->id,
                'type' => 'sale',
                'qty_milli' => -3000,
                'unit_price_cents' => 200,
                'sale_id' => null,
                'server_seq' => 2,
                'created_at_ms' => $t1 + 1000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Run the backfill migration logic
        $migration = require database_path('migrations/0001_01_01_000015_create_payments_customers_and_dube_tables.php');
        $reflection = new \ReflectionClass($migration);
        $method = $reflection->getMethod('backfillLegacySales');
        $method->setAccessible(true);
        $method->invoke($migration);

        // Verify movement linked to newly created legacy sale
        $m1 = Movement::withoutGlobalScope(ShopScope::class)->find($m1Id);
        $this->assertNotNull($m1->sale_id);

        $legacySale = Sale::withoutGlobalScope(ShopScope::class)->find($m1->sale_id);
        $this->assertNotNull($legacySale);
        $this->assertTrue((bool) $legacySale->legacy);
        $this->assertSame(1000, $legacySale->total_cents); // (2000*200/1000) + (3000*200/1000) = 400 + 600 = 1000
        $this->assertSame('Legacy sale backfill', $legacySale->note);

        // Legacy sales are treated as fully paid in balance calculations
        $this->assertSame(0, $this->balanceService->saleBalance($legacySale));
    }

    public function test_shared_dube_cases_fixture_matches_balance_service(): void
    {
        $fixturePath = base_path('../docs/fixtures/dube_cases.json');
        if (!file_exists($fixturePath)) {
            $fixturePath = dirname(base_path()) . '/docs/fixtures/dube_cases.json';
        }
        $this->assertFileExists($fixturePath);

        $data = json_decode(file_get_contents($fixturePath), true);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data['cases']);

        $shop = $this->shopA['shop'];

        foreach ($data['cases'] as $case) {
            // Create customer if present
            $customer = null;
            if (!empty($case['customer'])) {
                $customer = Customer::withoutGlobalScope(ShopScope::class)->updateOrCreate(
                    ['id' => $case['customer']['id']],
                    [
                        'shop_id' => $shop->id,
                        'name' => $case['customer']['name'],
                        'phone' => $case['customer']['phone'] ?? null,
                        'created_at_ms' => 1730000000000,
                        'updated_at_ms' => 1730000000000,
                    ]
                );
            }

            // Create sales
            foreach ($case['sales'] as $saleData) {
                Sale::withoutGlobalScope(ShopScope::class)->updateOrCreate(
                    ['id' => $saleData['id']],
                    [
                        'shop_id' => $shop->id,
                        'location_id' => $this->shopA['location']->id,
                        'customer_id' => $saleData['customer_id'] ?? null,
                        'total_cents' => $saleData['total_cents'],
                        'due_date' => $saleData['due_date'] ?? null,
                        'created_at_ms' => $saleData['created_at_ms'],
                        'updated_at_ms' => $saleData['created_at_ms'],
                        'voided_at_ms' => $saleData['voided_at_ms'] ?? null,
                        'legacy' => false,
                    ]
                );
            }

            // Create payments
            foreach ($case['payments'] as $idx => $payData) {
                Payment::withoutGlobalScope(ShopScope::class)->updateOrCreate(
                    ['id' => $payData['id']],
                    [
                        'shop_id' => $shop->id,
                        'sale_id' => $payData['sale_id'] ?? null,
                        'customer_id' => $payData['customer_id'] ?? null,
                        'location_id' => $this->shopA['location']->id,
                        'method' => $payData['method'],
                        'provider_key' => $payData['provider_key'] ?? null,
                        'amount_cents' => $payData['amount_cents'],
                        'received_at_ms' => $payData['received_at_ms'],
                        'created_at_ms' => $payData['received_at_ms'],
                        'server_seq' => 1000 + $idx,
                    ]
                );
            }

            // Verify expected sale balances
            foreach ($case['expected']['sale_balances'] as $saleId => $expectedBal) {
                $actualBal = $this->balanceService->saleBalance($saleId);
                $this->assertSame(
                    $expectedBal,
                    $actualBal,
                    "Failed saleBalance for sale {$saleId} in {$case['id']}"
                );
            }

            // Verify customer balance and overpayment if customer present
            if ($customer !== null) {
                $netBal = $this->balanceService->customerBalance($customer);
                $this->assertSame(
                    $case['expected']['customer_net_balance_cents'],
                    $netBal,
                    "Failed customerBalance in {$case['id']}"
                );

                $credit = $this->balanceService->customerCredit($customer);
                $this->assertSame(
                    $case['expected']['has_overpayment'],
                    $credit['has_overpayment'],
                    "Failed has_overpayment in {$case['id']}"
                );
                $this->assertSame(
                    $case['expected']['overpayment_cents'],
                    $credit['overpayment_cents'],
                    "Failed overpayment_cents in {$case['id']}"
                );

                if (!empty($case['expected']['fifo_allocations'])) {
                    $allocations = $this->balanceService->computeCustomerAllocations($customer);
                    foreach ($case['expected']['fifo_allocations'] as $sId => $expectedAlloc) {
                        $actualAlloc = $allocations['sale_allocations'][$sId] ?? 0;
                        $this->assertSame(
                            $expectedAlloc,
                            $actualAlloc,
                            "Failed fifo allocation for sale {$sId} in {$case['id']}"
                        );
                    }
                }
            }
        }
    }
}
