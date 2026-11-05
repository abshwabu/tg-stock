<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Scopes\ShopScope;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentReportService
{
    public function __construct(
        protected BalanceService $balanceService
    ) {}

    /**
     * Resolve provider display name.
     */
    public function getProviderName(string $shopId, string $method, ?string $providerKey, string $locale = 'en'): string
    {
        if ($method === 'cash' || empty($providerKey)) {
            return $locale === 'am' ? 'ጥሬ ገንዘብ' : 'Cash';
        }

        // Check catalog config first
        $catalogBanks = config('payment_catalog.banks', []);
        $catalogWallets = config('payment_catalog.wallets', []);
        $item = $catalogBanks[$providerKey] ?? ($catalogWallets[$providerKey] ?? null);

        if ($item !== null) {
            if ($locale === 'am' && !empty($item['name_am'])) {
                return (string) $item['name_am'];
            }
            return (string) ($item['name_en'] ?? $providerKey);
        }

        // Check custom provider in shop
        /** @var PaymentProvider|null $custom */
        $custom = PaymentProvider::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('provider_key', $providerKey)
            ->first();

        if ($custom !== null && !empty($custom->name)) {
            return $custom->name;
        }

        return $providerKey;
    }

    /**
     * Report: Payments received grouped by method and provider (net of reversals).
     *
     * @param array{0: int, 1: int} $range [startMs, endMs]
     * @return array{by_method: array<string, int>, by_provider: array<int, array{method: string, provider_key: ?string, provider_name: string, count: int, net_amount_cents: int}>, grand_total_cents: int}
     */
    public function receivedByMethod(
        Shop|string $shop,
        array $range,
        ?string $locationId = null,
        string $locale = 'en'
    ): array {
        $shopModel = $shop instanceof Shop ? $shop : Shop::findOrFail($shop);
        $shopId = (string) $shopModel->id;
        [$startMs, $endMs] = $range;

        $query = Payment::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereBetween('received_at_ms', [$startMs, $endMs]);

        if (!empty($locationId) && $locationId !== 'all') {
            $query->where('location_id', $locationId);
        }

        $payments = $query->get();

        $byMethod = [
            'cash' => 0,
            'bank' => 0,
            'wallet' => 0,
        ];

        $providersGrouped = [];
        $grandTotal = 0;

        foreach ($payments as $p) {
            $amt = (int) $p->amount_cents;
            $grandTotal += $amt;

            if (isset($byMethod[$p->method])) {
                $byMethod[$p->method] += $amt;
            }

            $groupKey = "{$p->method}_" . ($p->provider_key ?? 'none');
            if (!isset($providersGrouped[$groupKey])) {
                $providersGrouped[$groupKey] = [
                    'method' => $p->method,
                    'provider_key' => $p->provider_key,
                    'provider_name' => $this->getProviderName($shopId, $p->method, $p->provider_key, $locale),
                    'count' => 0,
                    'net_amount_cents' => 0,
                ];
            }

            $providersGrouped[$groupKey]['count']++;
            $providersGrouped[$groupKey]['net_amount_cents'] += $amt;
        }

        return [
            'by_method' => $byMethod,
            'by_provider' => array_values($providersGrouped),
            'grand_total_cents' => $grandTotal,
        ];
    }

    /**
     * Report: Sales totals (revenue vs money received vs new dube).
     *
     * @param array{0: int, 1: int} $range [startMs, endMs]
     * @return array{revenue_cents: int, money_received_cents: int, new_dube_cents: int, sales_count: int}
     */
    public function salesTotals(
        Shop|string $shop,
        array $range,
        ?string $locationId = null
    ): array {
        $shopModel = $shop instanceof Shop ? $shop : Shop::findOrFail($shop);
        $shopId = (string) $shopModel->id;
        [$startMs, $endMs] = $range;

        // 1. Sales in range
        $salesQuery = Sale::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereNull('voided_at_ms')
            ->whereBetween('created_at_ms', [$startMs, $endMs]);

        if (!empty($locationId) && $locationId !== 'all') {
            $salesQuery->where('location_id', $locationId);
        }

        $sales = $salesQuery->get();
        $totalRevenue = 0;
        $newDubeCents = 0;

        foreach ($sales as $s) {
            $totalRevenue += $s->total_cents;

            // Compute upfront payments on this sale (explicit payments created with or on the sale)
            $explicitPaid = (int) Payment::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('sale_id', $s->id)
                ->sum('amount_cents');

            $unpaid = max(0, $s->total_cents - $explicitPaid);
            if ($unpaid > 0 && !$s->legacy) {
                $newDubeCents += $unpaid;
            }
        }

        // 2. Total money received in range (any payments received in this timeframe)
        $paymentsQuery = Payment::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereBetween('received_at_ms', [$startMs, $endMs]);

        if (!empty($locationId) && $locationId !== 'all') {
            $paymentsQuery->where('location_id', $locationId);
        }

        $moneyReceived = (int) $paymentsQuery->sum('amount_cents');

        return [
            'revenue_cents' => $totalRevenue,
            'money_received_cents' => $moneyReceived,
            'new_dube_cents' => $newDubeCents,
            'sales_count' => $sales->count(),
        ];
    }

    /**
     * Report: Total outstanding dube across all customers in the shop.
     *
     * @return array{total_outstanding_cents: int, total_customers_with_dube: int, total_overpayment_cents: int, customers: Collection}
     */
    public function outstandingDube(Shop|string $shop): array
    {
        $shopModel = $shop instanceof Shop ? $shop : Shop::findOrFail($shop);
        $shopId = (string) $shopModel->id;

        $customers = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereNull('deleted_at_ms')
            ->get();

        $totalOutstanding = 0;
        $totalOverpayment = 0;
        $customersWithDube = 0;
        $list = collect();

        foreach ($customers as $c) {
            $credit = $this->balanceService->customerCredit($c);
            if ($credit['total_debt_cents'] > 0) {
                $totalOutstanding += $credit['total_debt_cents'];
                $customersWithDube++;
                $list->push($credit);
            }
            if ($credit['has_overpayment']) {
                $totalOverpayment += $credit['overpayment_cents'];
            }
        }

        return [
            'total_outstanding_cents' => $totalOutstanding,
            'total_customers_with_dube' => $customersWithDube,
            'total_overpayment_cents' => $totalOverpayment,
            'customers' => $list->sortByDesc('total_debt_cents')->values(),
        ];
    }

    /**
     * Report: Overdue list.
     *
     * @return Collection
     */
    public function overdueList(Shop|string $shop, ?string $asOfDate = null): Collection
    {
        return $this->balanceService->overdue($shop, $asOfDate);
    }

    /**
     * Report: Chronological statement of account for a customer.
     *
     * @return array{customer: Customer, transactions: array<int, array{date_ms: int, type: string, id: string, note: ?string, debit_cents: int, credit_cents: int, running_balance_cents: int}>, total_sales_cents: int, total_payments_cents: int, net_balance_cents: int, open_sales_count: int, overdue_cents: int}
     */
    public function customerStatement(
        Customer|string $customer,
        ?int $fromMs = null,
        ?int $toMs = null
    ): array {
        $custModel = $customer instanceof Customer
            ? $customer
            : Customer::withoutGlobalScope(ShopScope::class)->findOrFail($customer);

        $shopId = (string) $custModel->shop_id;

        // Fetch all unvoided sales
        $sales = Sale::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('customer_id', $custModel->id)
            ->whereNull('voided_at_ms')
            ->get();

        // Fetch all payments for this customer (explicit on sale or repayments)
        $payments = Payment::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('customer_id', $custModel->id)
            ->get();

        // Combine into chronological events
        $events = [];

        foreach ($sales as $s) {
            $events[] = [
                'date_ms' => (int) $s->created_at_ms,
                'type' => 'sale',
                'id' => $s->id,
                'note' => $s->note ?? 'Sale',
                'debit_cents' => (int) $s->total_cents,
                'credit_cents' => 0,
            ];
        }

        foreach ($payments as $p) {
            $amt = (int) $p->amount_cents;
            $events[] = [
                'date_ms' => (int) $p->received_at_ms,
                'type' => $amt < 0 ? 'reversal' : 'payment',
                'id' => $p->id,
                'note' => $p->reference ?? ($amt < 0 ? 'Payment Reversal' : ucfirst($p->method) . ' Payment'),
                'debit_cents' => 0,
                'credit_cents' => $amt,
            ];
        }

        // Sort events chronologically
        usort($events, function ($a, $b) {
            if ($a['date_ms'] === $b['date_ms']) {
                // Sales come before payments on identical timestamps
                return $a['type'] === 'sale' ? -1 : 1;
            }
            return $a['date_ms'] <=> $b['date_ms'];
        });

        // Compute running balance
        $runningBalance = 0;
        $totalSales = 0;
        $totalPayments = 0;
        $transactions = [];

        foreach ($events as $e) {
            $runningBalance += $e['debit_cents'];
            $runningBalance -= $e['credit_cents'];
            $totalSales += $e['debit_cents'];
            $totalPayments += $e['credit_cents'];

            $e['running_balance_cents'] = $runningBalance;

            // Filter by date range if provided
            $include = true;
            if ($fromMs !== null && $e['date_ms'] < $fromMs) {
                $include = false;
            }
            if ($toMs !== null && $e['date_ms'] > $toMs) {
                $include = false;
            }

            if ($include) {
                $transactions[] = $e;
            }
        }

        $credit = $this->balanceService->customerCredit($custModel);

        return [
            'customer' => $custModel,
            'transactions' => $transactions,
            'total_sales_cents' => $totalSales,
            'total_payments_cents' => $totalPayments,
            'net_balance_cents' => $runningBalance,
            'open_sales_count' => $credit['open_sales_count'],
            'overdue_cents' => $credit['overdue_cents'],
        ];
    }

    /**
     * Report: Expected cash for a specific calendar date (cash sales + cash repayments - cash refunds/voids).
     *
     * @return array{expected_cash_cents: int, cash_sales_cents: int, cash_repayments_cents: int, cash_reversals_cents: int, count: int}
     */
    public function cashExpected(
        Shop|string $shop,
        string|Carbon $date,
        ?string $locationId = null,
        ?string $userId = null
    ): array {
        $shopModel = $shop instanceof Shop ? $shop : Shop::findOrFail($shop);
        $shopId = (string) $shopModel->id;
        $tz = $shopModel->timezone ?: 'Africa/Addis_Ababa';

        $carbonDate = is_string($date) ? Carbon::parse($date, $tz) : $date->copy()->setTimezone($tz);
        $startMs = $carbonDate->copy()->startOfDay()->timestamp * 1000;
        $endMs = $carbonDate->copy()->endOfDay()->timestamp * 1000;

        $query = Payment::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('method', 'cash')
            ->whereBetween('received_at_ms', [$startMs, $endMs]);

        if (!empty($locationId) && $locationId !== 'all') {
            $query->where('location_id', $locationId);
        }

        if (!empty($userId)) {
            $query->where('user_id', $userId);
        }

        $payments = $query->get();

        $cashSales = 0;
        $cashRepayments = 0;
        $cashReversals = 0;

        foreach ($payments as $p) {
            $amt = (int) $p->amount_cents;
            if ($amt < 0) {
                $cashReversals += abs($amt);
            } elseif (!empty($p->sale_id)) {
                $cashSales += $amt;
            } else {
                $cashRepayments += $amt;
            }
        }

        $expected = $cashSales + $cashRepayments - $cashReversals;

        return [
            'expected_cash_cents' => $expected,
            'cash_sales_cents' => $cashSales,
            'cash_repayments_cents' => $cashRepayments,
            'cash_reversals_cents' => $cashReversals,
            'count' => $payments->count(),
        ];
    }

    /**
     * Report: Sales broken down by product kind (stockable, service, consumable).
     *
     * @param Shop|string $shop
     * @param array{0: int, 1: int} $range [startMs, endMs]
     * @param string|null $locationId
     * @return array{
     *     by_kind: array<string, array{sales_count: int, units_sold_milli: int, units_sold: string, revenue_cents: int}>,
     *     total_revenue_cents: int,
     *     total_units_sold_milli: int,
     *     total_units_sold: string
     * }
     */
    public function salesByKind(
        Shop|string $shop,
        array $range,
        ?string $locationId = null
    ): array {
        $shopModel = $shop instanceof Shop ? $shop : Shop::findOrFail($shop);
        $shopId = (string) $shopModel->id;
        [$startMs, $endMs] = $range;

        $query = Movement::withoutGlobalScope(ShopScope::class)
            ->join('products', 'products.id', '=', 'movements.product_id')
            ->where('movements.shop_id', $shopId)
            ->where('movements.type', 'sale')
            ->whereBetween('movements.created_at_ms', [$startMs, $endMs]);

        if (!empty($locationId) && $locationId !== 'all') {
            $query->where('movements.location_id', $locationId);
        }

        $rows = $query->select([
            'movements.id',
            'movements.qty_milli',
            'movements.unit_price_cents',
            'products.kind',
        ])->get();

        $byKind = [
            'stockable' => ['sales_count' => 0, 'units_sold_milli' => 0, 'units_sold' => '0.000', 'revenue_cents' => 0],
            'service' => ['sales_count' => 0, 'units_sold_milli' => 0, 'units_sold' => '0.000', 'revenue_cents' => 0],
            'consumable' => ['sales_count' => 0, 'units_sold_milli' => 0, 'units_sold' => '0.000', 'revenue_cents' => 0],
        ];

        $totalRevenue = 0;
        $totalUnitsMilli = 0;

        foreach ($rows as $row) {
            $kind = $row->kind ?: 'stockable';
            if (!isset($byKind[$kind])) {
                $byKind[$kind] = ['sales_count' => 0, 'units_sold_milli' => 0, 'units_sold' => '0.000', 'revenue_cents' => 0];
            }

            $unitsMilli = abs((int) $row->qty_milli);
            $revCents = (int) round(($unitsMilli * (int) $row->unit_price_cents) / 1000);

            $byKind[$kind]['sales_count']++;
            $byKind[$kind]['units_sold_milli'] += $unitsMilli;
            $byKind[$kind]['revenue_cents'] += $revCents;

            $totalRevenue += $revCents;
            $totalUnitsMilli += $unitsMilli;
        }

        foreach ($byKind as $k => $data) {
            $byKind[$k]['units_sold'] = LedgerService::formatQty($data['units_sold_milli']);
        }

        return [
            'by_kind' => $byKind,
            'total_revenue_cents' => $totalRevenue,
            'total_units_sold_milli' => $totalUnitsMilli,
            'total_units_sold' => LedgerService::formatQty($totalUnitsMilli),
        ];
    }

    /**
     * Report: Raw materials consumed by consumable sales in the range.
     *
     * @param Shop|string $shop
     * @param array{0: int, 1: int} $range [startMs, endMs]
     * @param string|null $locationId
     * @return array{
     *     materials: array<int, array{product_id: string, product_name: string, sku: ?string, unit: string, qty_milli: int, qty: string, cost_price_cents: int, total_cost_cents: int}>,
     *     total_cost_cents: int,
     *     count: int
     * }
     */
    public function materialsUsed(
        Shop|string $shop,
        array $range,
        ?string $locationId = null
    ): array {
        $shopModel = $shop instanceof Shop ? $shop : Shop::findOrFail($shop);
        $shopId = (string) $shopModel->id;
        [$startMs, $endMs] = $range;

        $query = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('type', 'consume')
            ->whereBetween('created_at_ms', [$startMs, $endMs]);

        if (!empty($locationId) && $locationId !== 'all') {
            $query->where('location_id', $locationId);
        }

        $consumeRows = $query->get();

        $grouped = [];
        $totalCost = 0;

        foreach ($consumeRows as $row) {
            $pid = (string) $row->product_id;
            if (!isset($grouped[$pid])) {
                $prod = Product::withoutGlobalScope(ShopScope::class)->find($pid);
                $grouped[$pid] = [
                    'product_id' => $pid,
                    'product_name' => $prod?->name ?? 'Unknown',
                    'sku' => $prod?->sku,
                    'unit' => $prod?->unit ?? 'pcs',
                    'qty_milli' => 0,
                    'cost_price_cents' => (int) ($prod?->cost_price_cents ?? 0),
                    'total_cost_cents' => 0,
                ];
            }

            $deductMilli = abs((int) $row->qty_milli);
            $grouped[$pid]['qty_milli'] += $deductMilli;
        }

        $materialsList = [];
        foreach ($grouped as $item) {
            $itemCost = (int) round(($item['qty_milli'] * $item['cost_price_cents']) / 1000);
            $item['total_cost_cents'] = $itemCost;
            $item['qty'] = LedgerService::formatQty($item['qty_milli']);
            $totalCost += $itemCost;
            $materialsList[] = $item;
        }

        usort($materialsList, fn($a, $b) => $b['total_cost_cents'] <=> $a['total_cost_cents']);

        return [
            'materials' => $materialsList,
            'total_cost_cents' => $totalCost,
            'count' => count($materialsList),
        ];
    }

    /**
     * Compute estimated profit for a range considering stockable, service, and consumable lines.
     */
    public function estimatedProfit(
        Shop|string $shop,
        array $range,
        ?string $locationId = null
    ): array {
        $shopModel = $shop instanceof Shop ? $shop : Shop::findOrFail($shop);
        $shopId = (string) $shopModel->id;
        [$startMs, $endMs] = $range;

        $salesQuery = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('type', 'sale')
            ->whereBetween('created_at_ms', [$startMs, $endMs]);

        if (!empty($locationId) && $locationId !== 'all') {
            $salesQuery->where('location_id', $locationId);
        }

        $salesRows = $salesQuery->get();
        $totalRevenueCents = 0;
        $totalCostCents = 0;

        foreach ($salesRows as $s) {
            $qtySold = abs($s->qty_milli) / 1000;
            $saleRevenue = (int) round($qtySold * $s->unit_price_cents);
            $totalRevenueCents += $saleRevenue;

            $prod = Product::withoutGlobalScope(ShopScope::class)->find($s->product_id);
            if ($prod === null) {
                continue;
            }

            if ($prod->isStockable()) {
                // Stockable lines use cost price
                if ($prod->cost_price_cents > 0) {
                    $totalCostCents += (int) round($qtySold * $prod->cost_price_cents);
                }
            } elseif ($prod->isService()) {
                // Service lines use optional cost_price_cents (0 if empty)
                if ($prod->cost_price_cents > 0) {
                    $totalCostCents += (int) round($qtySold * $prod->cost_price_cents);
                }
            } elseif ($prod->isConsumable()) {
                // Consumable lines use materials consumed x material current cost price
                $consumes = Movement::withoutGlobalScope(ShopScope::class)
                    ->where('shop_id', $shopId)
                    ->where('type', 'consume')
                    ->where('source_movement_id', $s->id)
                    ->get();

                foreach ($consumes as $c) {
                    $matProd = Product::withoutGlobalScope(ShopScope::class)->find($c->product_id);
                    if ($matProd !== null && $matProd->cost_price_cents > 0) {
                        $matQty = abs($c->qty_milli) / 1000;
                        $totalCostCents += (int) round($matQty * $matProd->cost_price_cents);
                    }
                }
            }
        }

        $profit = $totalRevenueCents - $totalCostCents;

        return [
            'revenue_cents' => $totalRevenueCents,
            'cost_cents' => $totalCostCents,
            'profit_cents' => $profit,
        ];
    }
}
