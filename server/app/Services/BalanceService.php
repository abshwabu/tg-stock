<?php

declare(strict_types=1);

namespace App\Services;

use App\Context\CurrentShopContext;
use App\Models\Change;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BalanceService
{
    public function __construct(
        protected ?LedgerService $ledger = null
    ) {}

    protected function getLedger(): LedgerService
    {
        return $this->ledger ?? app(LedgerService::class);
    }

    /**
     * Compute remaining balance of a specific sale.
     * Takes into account explicit payments and FIFO allocated customer repayments.
     */
    public function saleBalance(Sale|string $sale): int
    {
        $saleModel = $this->resolveSale($sale);
        if ($saleModel === null || $saleModel->isVoided() || $saleModel->legacy) {
            return 0;
        }

        $explicitPaid = (int) Payment::withoutGlobalScope(ShopScope::class)
            ->where('sale_id', $saleModel->id)
            ->sum('amount_cents');

        $initialRemaining = max(0, $saleModel->total_cents - $explicitPaid);
        if ($initialRemaining === 0 || empty($saleModel->customer_id)) {
            return $initialRemaining;
        }

        // Compute FIFO allocation for this customer
        $customer = Customer::withoutGlobalScope(ShopScope::class)->find($saleModel->customer_id);
        if ($customer === null) {
            return $initialRemaining;
        }

        $allocations = $this->computeCustomerAllocations($customer);
        $allocated = $allocations['sale_allocations'][$saleModel->id] ?? 0;

        return max(0, $initialRemaining - $allocated);
    }

    /**
     * Compute total net debt balance owed by a customer.
     * If customer has overpayment credit, returns negative cents.
     */
    public function customerBalance(Customer|string $customer): int
    {
        $custModel = $this->resolveCustomer($customer);
        if ($custModel === null) {
            return 0;
        }

        $allocations = $this->computeCustomerAllocations($custModel);

        return $allocations['net_balance_cents'];
    }

    /**
     * Retrieve all open (unvoided) sales for a customer where remaining balance > 0,
     * ordered oldest first with FIFO repayments applied.
     *
     * @return Collection<int, array{sale: Sale, total_cents: int, explicit_paid_cents: int, allocated_repayment_cents: int, remaining_balance_cents: int, due_date: ?string, is_overdue: bool}>
     */
    public function openSales(Customer|string $customer, ?string $asOfDate = null): Collection
    {
        $custModel = $this->resolveCustomer($customer);
        if ($custModel === null) {
            return collect();
        }

        $allocations = $this->computeCustomerAllocations($custModel);
        $shop = Shop::find($custModel->shop_id);
        $tz = $shop?->timezone ?: 'Africa/Addis_Ababa';
        $today = $asOfDate ?? Carbon::now($tz)->toDateString();

        $result = collect();
        foreach ($allocations['sales_breakdown'] as $item) {
            if ($item['remaining_balance_cents'] > 0) {
                $isOverdue = !empty($item['sale']->due_date) && $item['sale']->due_date < $today;
                $item['is_overdue'] = $isOverdue;
                $result->push($item);
            }
        }

        return $result;
    }

    /**
     * Retrieve all overdue sales for a shop.
     *
     * @return Collection<int, array{sale: Sale, customer: ?Customer, due_date: string, days_overdue: int, total_cents: int, remaining_balance_cents: int}>
     */
    public function overdue(Shop|string $shop, ?string $asOfDate = null): Collection
    {
        $shopModel = $shop instanceof Shop ? $shop : Shop::findOrFail($shop);
        $tz = $shopModel->timezone ?: 'Africa/Addis_Ababa';
        $today = $asOfDate ?? Carbon::now($tz)->toDateString();
        $todayCarbon = Carbon::parse($today, $tz);

        // Find candidate sales: unvoided, not legacy, has due_date < today
        $candidateSales = Sale::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopModel->id)
            ->whereNull('voided_at_ms')
            ->where('legacy', false)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->orderBy('due_date', 'asc')
            ->get();

        $overdueList = collect();

        // Group by customer to compute FIFO correctly
        $salesByCustomer = $candidateSales->groupBy('customer_id');

        foreach ($salesByCustomer as $customerId => $sales) {
            if (empty($customerId)) {
                // Anonymous sale with due date (unusual but handle gracefully)
                foreach ($sales as $s) {
                    $bal = $this->saleBalance($s);
                    if ($bal > 0) {
                        $dueCarbon = Carbon::parse($s->due_date, $tz);
                        $daysOverdue = max(0, (int) $dueCarbon->diffInDays($todayCarbon));
                        $overdueList->push([
                            'sale' => $s,
                            'customer' => null,
                            'due_date' => (string) $s->due_date,
                            'days_overdue' => $daysOverdue,
                            'total_cents' => $s->total_cents,
                            'remaining_balance_cents' => $bal,
                        ]);
                    }
                }
                continue;
            }

            $customer = Customer::withoutGlobalScope(ShopScope::class)->find($customerId);
            if ($customer === null) {
                continue;
            }

            $allocations = $this->computeCustomerAllocations($customer);
            foreach ($sales as $s) {
                $breakdown = $allocations['sales_breakdown'][$s->id] ?? null;
                $rem = $breakdown ? $breakdown['remaining_balance_cents'] : $this->saleBalance($s);
                if ($rem > 0) {
                    $dueCarbon = Carbon::parse($s->due_date, $tz);
                    $daysOverdue = max(0, (int) $dueCarbon->diffInDays($todayCarbon));
                    $overdueList->push([
                        'sale' => $s,
                        'customer' => $customer,
                        'due_date' => (string) $s->due_date,
                        'days_overdue' => $daysOverdue,
                        'total_cents' => $s->total_cents,
                        'remaining_balance_cents' => $rem,
                    ]);
                }
            }
        }

        return $overdueList->sortByDesc('days_overdue')->values();
    }

    /**
     * Comprehensive customer credit profile.
     *
     * @return array{customer_id: string, customer_name: string, total_debt_cents: int, overdue_cents: int, open_sales_count: int, has_overpayment: bool, overpayment_cents: int, net_balance_cents: int, oldest_due_date: ?string, max_days_overdue: int}
     */
    public function customerCredit(Customer|string $customer, ?string $asOfDate = null): array
    {
        $custModel = $this->resolveCustomer($customer);
        if ($custModel === null) {
            throw new InvalidArgumentException('Customer not found.');
        }

        $shop = Shop::find($custModel->shop_id);
        $tz = $shop?->timezone ?: 'Africa/Addis_Ababa';
        $today = $asOfDate ?? Carbon::now($tz)->toDateString();
        $todayCarbon = Carbon::parse($today, $tz);

        $allocations = $this->computeCustomerAllocations($custModel);
        $totalDebt = 0;
        $overdueCents = 0;
        $openCount = 0;
        $oldestDueDate = null;
        $maxDaysOverdue = 0;

        foreach ($allocations['sales_breakdown'] as $item) {
            $rem = $item['remaining_balance_cents'];
            if ($rem > 0) {
                $totalDebt += $rem;
                $openCount++;
                $dueDate = $item['sale']->due_date;
                if (!empty($dueDate)) {
                    if ($oldestDueDate === null || $dueDate < $oldestDueDate) {
                        $oldestDueDate = $dueDate;
                    }
                    if ($dueDate < $today) {
                        $overdueCents += $rem;
                        $dueCarbon = Carbon::parse($dueDate, $tz);
                        $days = max(0, (int) $dueCarbon->diffInDays($todayCarbon));
                        if ($days > $maxDaysOverdue) {
                            $maxDaysOverdue = $days;
                        }
                    }
                }
            }
        }

        $overpaymentCents = $allocations['overpayment_cents'];

        return [
            'customer_id' => $custModel->id,
            'customer_name' => $custModel->name,
            'total_debt_cents' => $totalDebt,
            'overdue_cents' => $overdueCents,
            'open_sales_count' => $openCount,
            'has_overpayment' => $allocations['has_overpayment'],
            'overpayment_cents' => $overpaymentCents,
            'net_balance_cents' => $totalDebt - $overpaymentCents,
            'oldest_due_date' => $oldestDueDate,
            'max_days_overdue' => $maxDaysOverdue,
        ];
    }

    /**
     * Record an online payment (e.g. from the bot).
     * Online callers are blocked from paying more than the balance.
     */
    public function recordOnlinePayment(
        string $shopId,
        string $userId,
        string $method,
        int $amountCents,
        ?string $saleId = null,
        ?string $customerId = null,
        ?string $locationId = null,
        ?string $providerKey = null,
        ?string $reference = null,
        ?string $deviceId = null
    ): Payment {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        if (empty($saleId) && empty($customerId)) {
            throw new InvalidArgumentException('Either sale_id or customer_id must be provided.');
        }

        if ($method === 'cash') {
            if ($providerKey !== null) {
                throw new InvalidArgumentException('Cash payments forbid provider_key.');
            }
        } else {
            if (empty($providerKey)) {
                throw new InvalidArgumentException(sprintf('%s payments require provider_key.', ucfirst($method)));
            }
        }

        // Online callers (the bot) are blocked from paying more than the balance
        if (!empty($saleId)) {
            $sale = Sale::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->findOrFail($saleId);
            $bal = $this->saleBalance($sale);
            if ($amountCents > $bal) {
                throw new InvalidArgumentException(sprintf('Payment amount %d cents exceeds sale balance %d cents.', $amountCents, $bal));
            }
            if (empty($customerId)) {
                $customerId = $sale->customer_id ? (string) $sale->customer_id : null;
            }
            if (empty($locationId)) {
                $locationId = (string) $sale->location_id;
            }
        } elseif (!empty($customerId)) {
            $customer = Customer::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->findOrFail($customerId);
            $bal = $this->customerBalance($customer);
            if ($amountCents > $bal) {
                throw new InvalidArgumentException(sprintf('Payment amount %d cents exceeds customer balance %d cents.', $amountCents, $bal));
            }
        }

        if (empty($locationId)) {
            $defaultLoc = DB::table('locations')->where('shop_id', $shopId)->where('is_default', 1)->first();
            $locationId = $defaultLoc?->id;
            if (empty($locationId)) {
                $anyLoc = DB::table('locations')->where('shop_id', $shopId)->first();
                $locationId = $anyLoc?->id;
            }
        }

        $nowMs = (int) (microtime(true) * 1000);
        $maxSeq = (int) Payment::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->max('server_seq');

        $payment = Payment::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $shopId,
            'sale_id' => $saleId,
            'customer_id' => $customerId,
            'location_id' => $locationId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'method' => $method,
            'provider_key' => $providerKey,
            'amount_cents' => $amountCents,
            'reference' => $reference,
            'received_at_ms' => $nowMs,
            'created_at_ms' => $nowMs,
            'server_seq' => $maxSeq + 1,
        ]);

        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $shopId,
            'device_id' => $deviceId,
            'entity' => 'payment',
            'entity_id' => $payment->id,
        ]);

        return $payment;
    }

    /**
     * Void a sale: mark voided, reverse payments, and compensate movements.
     */
    public function voidSale(Sale|string $sale, User|string $user): Sale
    {
        $saleModel = $this->resolveSale($sale);
        if ($saleModel === null) {
            throw new InvalidArgumentException('Sale not found.');
        }

        if ($saleModel->isVoided()) {
            throw new InvalidArgumentException(sprintf('Sale [%s] is already voided.', $saleModel->id));
        }

        $userModel = $user instanceof User ? $user : User::withoutGlobalScope(ShopScope::class)->findOrFail($user);
        $shopId = (string) $saleModel->shop_id;
        $nowMs = (int) (microtime(true) * 1000);

        // Authorization check:
        // Owner may always void.
        // Creating user may void only within 10 minutes and if no payments by others exist.
        $isOwner = $userModel->role === 'owner';
        $isCreator = $saleModel->user_id !== null && (string) $saleModel->user_id === (string) $userModel->id;

        if (!$isOwner && !$isCreator) {
            throw new InvalidArgumentException('Only the shop owner or the creating user can void this sale.');
        }

        if (!$isOwner && $isCreator) {
            // Check 10-minute window (600,000 ms)
            if (($nowMs - $saleModel->created_at_ms) > 600000) {
                throw new InvalidArgumentException('Void window expired: creating user can only void within 10 minutes. Contact owner.');
            }

            // Check if any payment by other users exists
            $otherPaymentExists = Payment::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('sale_id', $saleModel->id)
                ->where('user_id', '!=', $userModel->id)
                ->exists();

            if ($otherPaymentExists) {
                throw new InvalidArgumentException('Cannot void sale: payments have been recorded by other staff. Owner must void.');
            }
        }

        DB::transaction(function () use ($saleModel, $userModel, $shopId, $nowMs) {
            // 1. Mark sale voided
            $saleModel->voided_at_ms = $nowMs;
            $saleModel->updated_at_ms = $nowMs;
            $saleModel->save();

            Change::withoutGlobalScope(ShopScope::class)->create([
                'shop_id' => $shopId,
                'entity' => 'sale',
                'entity_id' => $saleModel->id,
            ]);

            // 2. Reverse all positive payments on this sale
            $payments = Payment::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('sale_id', $saleModel->id)
                ->where('amount_cents', '>', 0)
                ->get();

            foreach ($payments as $p) {
                $maxSeq = (int) Payment::withoutGlobalScope(ShopScope::class)
                    ->where('shop_id', $shopId)
                    ->max('server_seq');

                $reversal = Payment::withoutGlobalScope(ShopScope::class)->create([
                    'id' => (string) Str::uuid7(),
                    'shop_id' => $shopId,
                    'sale_id' => $saleModel->id,
                    'customer_id' => $p->customer_id,
                    'location_id' => $p->location_id,
                    'user_id' => $userModel->id,
                    'device_id' => null,
                    'method' => $p->method,
                    'provider_key' => $p->provider_key,
                    'amount_cents' => -$p->amount_cents,
                    'reference' => "Reversal for [{$p->id}] (void sale)",
                    'received_at_ms' => $nowMs,
                    'created_at_ms' => $nowMs,
                    'server_seq' => $maxSeq + 1,
                ]);

                Change::withoutGlobalScope(ShopScope::class)->create([
                    'shop_id' => $shopId,
                    'entity' => 'payment',
                    'entity_id' => $reversal->id,
                ]);
            }

            // 3. Compensate movements attached to this sale
            $movements = Movement::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('sale_id', $saleModel->id)
                ->where('type', 'sale')
                ->where('qty_milli', '<', 0)
                ->get();

            foreach ($movements as $m) {
                // Ensure movement not already compensated
                $alreadyCompensated = Movement::withoutGlobalScope(ShopScope::class)
                    ->where('shop_id', $shopId)
                    ->where('note', 'like', "%Compensating undo for [{$m->id}]%")
                    ->exists();

                if (!$alreadyCompensated) {
                    $compensating = new Movement([
                        'id' => (string) Str::uuid7(),
                        'shop_id' => $shopId,
                        'product_id' => $m->product_id,
                        'location_id' => $m->location_id,
                        'sale_id' => $saleModel->id,
                        'user_id' => $userModel->id,
                        'device_id' => null,
                        'type' => 'adjust',
                        'qty_milli' => abs($m->qty_milli), // Positive restores stock
                        'unit_price_cents' => $m->unit_price_cents,
                        'note' => "Compensating undo for [{$m->id}] (void sale {$saleModel->id})",
                        'created_at_ms' => $nowMs,
                    ]);

                    $this->getLedger()->record($compensating);
                }
            }
        });

        return $saleModel;
    }

    /**
     * Internal engine: FIFO allocation of customer repayments to open sales.
     *
     * @return array{
     *   sale_allocations: array<string, int>,
     *   sales_breakdown: array<string, array{sale: Sale, total_cents: int, explicit_paid_cents: int, allocated_repayment_cents: int, remaining_balance_cents: int, due_date: ?string}>,
     *   total_unpaid_cents: int,
     *   total_repayments_cents: int,
     *   net_balance_cents: int,
     *   has_overpayment: bool,
     *   overpayment_cents: int
     * }
     */
    public function computeCustomerAllocations(Customer $customer): array
    {
        $shopId = (string) $customer->shop_id;

        // 1. Fetch unvoided sales for customer in chronological FIFO order
        $sales = Sale::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->whereNull('voided_at_ms')
            ->orderBy('created_at_ms', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // 2. Fetch all repayments for customer (payments with customer_id but NO sale_id)
        $totalRepayments = (int) Payment::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->whereNull('sale_id')
            ->sum('amount_cents');

        $availableRepayments = max(0, $totalRepayments);
        $saleAllocations = [];
        $salesBreakdown = [];
        $totalInitialDebt = 0;

        foreach ($sales as $s) {
            $explicitPaid = (int) Payment::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('sale_id', $s->id)
                ->sum('amount_cents');

            $initialRemaining = $s->legacy ? 0 : max(0, $s->total_cents - $explicitPaid);
            $totalInitialDebt += $initialRemaining;

            $allocated = min($availableRepayments, $initialRemaining);
            $availableRepayments -= $allocated;
            $remaining = $initialRemaining - $allocated;

            $saleAllocations[$s->id] = $allocated;
            $salesBreakdown[$s->id] = [
                'sale' => $s,
                'total_cents' => $s->total_cents,
                'explicit_paid_cents' => $explicitPaid,
                'allocated_repayment_cents' => $allocated,
                'remaining_balance_cents' => $remaining,
                'due_date' => $s->due_date,
            ];
        }

        $overpaymentCents = $availableRepayments;
        $hasOverpayment = $overpaymentCents > 0;
        $netBalance = $totalInitialDebt - $totalRepayments;

        return [
            'sale_allocations' => $saleAllocations,
            'sales_breakdown' => $salesBreakdown,
            'total_unpaid_cents' => $totalInitialDebt,
            'total_repayments_cents' => $totalRepayments,
            'net_balance_cents' => $netBalance,
            'has_overpayment' => $hasOverpayment,
            'overpayment_cents' => $overpaymentCents,
        ];
    }

    protected function resolveSale(Sale|string $sale): ?Sale
    {
        return $sale instanceof Sale ? $sale : Sale::withoutGlobalScope(ShopScope::class)->find($sale);
    }

    protected function resolveCustomer(Customer|string $customer): ?Customer
    {
        return $customer instanceof Customer ? $customer : Customer::withoutGlobalScope(ShopScope::class)->find($customer);
    }
}
