<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\Telegram\TelegramClient;
use App\Support\EthiopicCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DailySummaryService
{
    public function __construct(
        protected TelegramClient $telegram,
        protected LedgerService $ledger,
        protected PaymentReportService $reportService,
        protected PermissionService $permissionService,
        protected BalanceService $balanceService
    ) {}

    /**
     * Process daily summaries and due reminders for all registered shops.
     * Tolerant of coarse 5-minute cron cadence.
     */
    public function processDueSummaries(): int
    {
        $sentCount = 0;
        $shops = Shop::all();

        foreach ($shops as $shop) {
            $tz = $shop->timezone ?: 'Africa/Addis_Ababa';
            $now = Carbon::now($tz);
            $todayDate = $now->format('Y-m-d');

            // 1. Process daily evening summary
            if ($shop->daily_summary_last_sent_date !== $todayDate) {
                $targetTime = $shop->daily_summary_time ?: '20:00';
                if ($now->format('H:i') >= $targetTime) {
                    if ($this->sendSummaryForShop($shop, $todayDate)) {
                        $sentCount++;
                    }
                }
            }

            // 2. Process dube due reminders
            if ($shop->dube_reminder_last_sent_date !== $todayDate) {
                $targetTime = $shop->daily_summary_time ?: '09:00';
                if ($now->format('H:i') >= $targetTime) {
                    $this->sendDubeRemindersForShop($shop, $todayDate);
                }
            }
        }

        return $sentCount;
    }

    /**
     * Send daily summary for a single shop.
     * Idempotent by shop + date.
     */
    public function sendSummaryForShop(Shop $shop, ?string $date = null, bool $force = false): bool
    {
        $tz = $shop->timezone ?: 'Africa/Addis_Ababa';
        $targetDate = $date ? Carbon::parse($date, $tz) : Carbon::now($tz);
        $dateStr = $targetDate->format('Y-m-d');

        if (!$force && $shop->daily_summary_last_sent_date === $dateStr) {
            return false;
        }

        $startOfDayMs = $targetDate->copy()->startOfDay()->timestamp * 1000;
        $endOfDayMs = $targetDate->copy()->endOfDay()->timestamp * 1000;

        // Check if any movements happened today
        $totalMovements = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->whereBetween('created_at_ms', [$startOfDayMs, $endOfDayMs])
            ->count();

        if ($totalMovements === 0 && !$force) {
            $shop->daily_summary_last_sent_date = $dateStr;
            $shop->save();
            return false;
        }

        $owner = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->first();

        if ($owner === null || empty($owner->telegram_id)) {
            return false;
        }

        $locale = $owner->language ?? 'am';

        // 1. Sales metrics
        $salesRows = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->where('type', 'sale')
            ->whereBetween('created_at_ms', [$startOfDayMs, $endOfDayMs])
            ->get();

        $salesCount = $salesRows->count();
        $totalRevenueCents = 0;
        foreach ($salesRows as $s) {
            $totalRevenueCents += (int) round((abs($s->qty_milli) * $s->unit_price_cents) / 1000);
        }

        // 2. Payments received today by method
        $receivedData = $this->reportService->receivedByMethod($shop, [$startOfDayMs, $endOfDayMs], null, $locale);
        $byMethod = $receivedData['by_method'];
        $cashRecF = number_format(($byMethod['cash'] ?? 0) / 100, 2) . ' ETB';
        $bankRecF = number_format(($byMethod['bank'] ?? 0) / 100, 2) . ' ETB';
        $walletRecF = number_format(($byMethod['wallet'] ?? 0) / 100, 2) . ' ETB';

        // 3. New Dube today
        $salesTotals = $this->reportService->salesTotals($shop, [$startOfDayMs, $endOfDayMs]);
        $newDubeF = number_format(($salesTotals['new_dube_cents'] ?? 0) / 100, 2) . ' ETB';

        // 4. Overdue count and top 3 debtors
        $outstandingData = $this->reportService->outstandingDube($shop);
        $debtors = $outstandingData['customers'];
        $overdueCount = $debtors->filter(fn($c) => $c['overdue_cents'] > 0)->count();

        $topDebtorsText = '';
        $top3Debtors = $debtors->take(3);
        foreach ($top3Debtors as $idx => $d) {
            $num = $idx + 1;
            $amtF = number_format($d['total_debt_cents'] / 100, 2) . ' ETB';
            $overdueTag = $d['overdue_cents'] > 0 ? ' [🔴 Overdue]' : '';
            $topDebtorsText .= "  {$num}. {$d['customer_name']}: {$amtF}{$overdueTag}\n";
        }
        if (empty($topDebtorsText)) {
            $topDebtorsText = "  " . ($locale === 'en' ? '(No outstanding debt)' : '(ምንም ያልተከፈለ ዕዳ የለም)') . "\n";
        }

        // 5. Top 3 items sold today
        $topItems = Movement::withoutGlobalScope(ShopScope::class)
            ->select('product_id', DB::raw('SUM(-qty_milli) as total_qty'), DB::raw('SUM(-qty_milli * unit_price_cents / 1000) as total_revenue'))
            ->where('shop_id', $shop->id)
            ->where('type', 'sale')
            ->whereBetween('created_at_ms', [$startOfDayMs, $endOfDayMs])
            ->groupBy('product_id')
            ->orderByDesc('total_revenue')
            ->limit(3)
            ->get();

        $topItemsText = '';
        foreach ($topItems as $idx => $ti) {
            $prod = Product::withoutGlobalScope(ShopScope::class)->find($ti->product_id);
            if ($prod) {
                $num = $idx + 1;
                $qty = LedgerService::formatQty((int) $ti->total_qty);
                $rev = EthiopicCalendar::formatMoney((int) $ti->total_revenue);
                $topItemsText .= "  {$num}. {$prod->name} — {$qty} {$prod->unit} ({$rev})\n";
            }
        }
        if (empty($topItemsText)) {
            $topItemsText = $locale === 'en' ? "  (No sales today)\n" : "  (ዛሬ የተሸጠ ዕቃ የለም)\n";
        }

        // Format Date (Ethiopian calendar if enabled)
        $dateFormatted = $shop->use_ethiopian_calendar
            ? EthiopicCalendar::format($targetDate, $locale)
            : $targetDate->format('M j, Y');

        $revenueFormatted = EthiopicCalendar::formatMoney($totalRevenueCents);

        if ($locale === 'en') {
            $msg = "🌙 <b>Daily Evening Summary — {$shop->name}</b>\n"
                . "📅 Date: <b>{$dateFormatted}</b>\n\n"
                . "💰 Total Sales Revenue: <b>{$revenueFormatted}</b>\n"
                . "🧾 Total Sales Count: <b>{$salesCount} transactions</b>\n\n"
                . "💳 <b>Payments Received Today:</b>\n"
                . "  • Cash: <b>{$cashRecF}</b>\n"
                . "  • Bank: <b>{$bankRecF}</b>\n"
                . "  • Wallet: <b>{$walletRecF}</b>\n\n"
                . "📒 New Dube Today: <b>{$newDubeF}</b>\n"
                . "⚠️ Overdue Customers: <b>{$overdueCount}</b>\n\n"
                . "👥 <b>Top Debtors:</b>\n{$topDebtorsText}\n"
                . "🏆 <b>Top Items Sold Today:</b>\n{$topItemsText}";
        } else {
            $msg = "🌙 <b>የዕለቱ ማጠቃለያ — {$shop->name}</b>\n"
                . "📅 ቀን: <b>{$dateFormatted}</b>\n\n"
                . "💰 የዕለቱ ሽያጭ ገቢ: <b>{$revenueFormatted}</b>\n"
                . "🧾 የሽያጭ ብዛት: <b>{$salesCount} ግብይቶች</b>\n\n"
                . "💳 <b>ዛሬ የተቀበሉት ገንዘብ:</b>\n"
                . "  • ጥሬ ገንዘብ: <b>{$cashRecF}</b>\n"
                . "  • ባንክ: <b>{$bankRecF}</b>\n"
                . "  • ዋሌት: <b>{$walletRecF}</b>\n\n"
                . "📒 አዲስ የተሰጠ ዱቤ: <b>{$newDubeF}</b>\n"
                . "⚠️ ጊዜያቸው ያለፈባቸው ባለዕዳዎች: <b>{$overdueCount}</b>\n\n"
                . "👥 <b>ከፍተኛ ባለዕዳዎች:</b>\n{$topDebtorsText}\n"
                . "🏆 <b>ተመራጭ የተሸጡ ዕቃዎች:</b>\n{$topItemsText}";
        }

        $this->telegram->sendMessage($owner->telegram_id, $msg);

        $shop->daily_summary_last_sent_date = $dateStr;
        $shop->save();

        return true;
    }

    /**
     * Send daily Dube due reminders to owner and staff with dube.view permission.
     * Idempotent per shop and date.
     */
    public function sendDubeRemindersForShop(Shop $shop, ?string $date = null, bool $force = false): bool
    {
        $tz = $shop->timezone ?: 'Africa/Addis_Ababa';
        $targetDate = $date ? Carbon::parse($date, $tz) : Carbon::now($tz);
        $dateStr = $targetDate->format('Y-m-d');

        if (!$force && $shop->dube_reminder_last_sent_date === $dateStr) {
            return false;
        }

        // Find overdue sales and sales due today
        $overdueList = $this->reportService->overdueList($shop, $dateStr);

        $dueTodaySales = Sale::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->whereNull('voided_at_ms')
            ->where('legacy', false)
            ->where('due_date', $dateStr)
            ->get()
            ->filter(fn($s) => $this->balanceService->saleBalance($s) > 0)
            ->values();

        if ($overdueList->isEmpty() && $dueTodaySales->isEmpty() && !$force) {
            $shop->dube_reminder_last_sent_date = $dateStr;
            $shop->save();
            return false;
        }

        // Find all recipients: owner + active users with dube.view permission
        $recipients = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->where('is_active', true)
            ->whereNotNull('telegram_id')
            ->get()
            ->filter(fn($u) => $u->role === 'owner' || $this->permissionService->can($u, 'dube.view'));

        if ($recipients->isEmpty()) {
            return false;
        }

        $totalOverdueCents = $overdueList->sum('remaining_balance_cents');
        $overdueCount = $overdueList->count();

        $dueTodayCount = $dueTodaySales->count();
        $dueTodayCents = $dueTodaySales->sum(fn($s) => $this->balanceService->saleBalance($s));

        $totalOverdueF = number_format($totalOverdueCents / 100, 2) . ' ETB';
        $dueTodayF = number_format($dueTodayCents / 100, 2) . ' ETB';

        // Format listings
        $overdueText = '';
        foreach ($overdueList->take(5) as $idx => $od) {
            $num = $idx + 1;
            $custName = $od['customer']?->name ?? 'Anonymous';
            $amtF = number_format($od['remaining_balance_cents'] / 100, 2) . ' ETB';
            $days = $od['days_overdue'];
            $overdueText .= "  {$num}. {$custName}: {$amtF} (🔴 {$days}d overdue)\n";
        }

        $dueTodayText = '';
        foreach ($dueTodaySales->take(5) as $idx => $ds) {
            $num = $idx + 1;
            $cust = Customer::withoutGlobalScope(ShopScope::class)->find($ds->customer_id);
            $custName = $cust?->name ?? 'Customer';
            $bal = $this->balanceService->saleBalance($ds);
            $amtF = number_format($bal / 100, 2) . ' ETB';
            $dueTodayText .= "  {$num}. {$custName}: {$amtF}\n";
        }

        foreach ($recipients as $recipient) {
            $locale = $recipient->language ?? 'am';
            $dateFormatted = $shop->use_ethiopian_calendar
                ? EthiopicCalendar::format($targetDate, $locale)
                : $targetDate->format('M j, Y');

            if ($locale === 'en') {
                $msg = "⏰ <b>Dube Due & Overdue Reminder — {$shop->name}</b>\n"
                    . "📅 Date: <b>{$dateFormatted}</b>\n\n"
                    . "🔴 <b>Overdue:</b> {$overdueCount} sales (<b>{$totalOverdueF}</b>)\n"
                    . ($overdueText ?: "  (None)\n") . "\n"
                    . "⏳ <b>Due Today:</b> {$dueTodayCount} sales (<b>{$dueTodayF}</b>)\n"
                    . ($dueTodayText ?: "  (None)\n");
            } else {
                $msg = "⏰ <b>የዱቤ መክፈያ ቀን ማስታወሻ — {$shop->name}</b>\n"
                    . "📅 ቀን: <b>{$dateFormatted}</b>\n\n"
                    . "🔴 <b>ጊዜያቸው ያለፈባቸው:</b> {$overdueCount} ሽያጮች (<b>{$totalOverdueF}</b>)\n"
                    . ($overdueText ?: "  (ምንም የለም)\n") . "\n"
                    . "⏳ <b>ዛሬ የሚከፈሉ:</b> {$dueTodayCount} ሽያጮች (<b>{$dueTodayF}</b>)\n"
                    . ($dueTodayText ?: "  (ምንም የለም)\n");
            }

            $this->telegram->sendMessage($recipient->telegram_id, $msg);
        }

        $shop->dube_reminder_last_sent_date = $dateStr;
        $shop->save();

        return true;
    }
}
