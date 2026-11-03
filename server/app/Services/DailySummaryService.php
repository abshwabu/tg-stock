<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Movement;
use App\Models\Product;
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
        protected LedgerService $ledger
    ) {}

    /**
     * Process daily summaries for all registered shops where summary is due.
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

            // Skip if already sent today
            if ($shop->daily_summary_last_sent_date === $todayDate) {
                continue;
            }

            // Check if due (current time >= scheduled time, e.g. "20:00")
            $targetTime = $shop->daily_summary_time ?: '20:00';
            if ($now->format('H:i') >= $targetTime) {
                if ($this->sendSummaryForShop($shop, $todayDate)) {
                    $sentCount++;
                }
            }
        }

        return $sentCount;
    }

    /**
     * Send daily summary for a single shop.
     * Idempotent by shop + date. Skips if nothing happened today.
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
            // Nothing happened: mark sent for today and skip
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

        // 2. Top 3 items sold today
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

        // 3. Low stock / out of stock items
        $products = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->whereNull('deleted_at_ms')
            ->get();

        $lowStockText = '';
        $lowThreshold = $shop->low_stock_default_milli ?? 5000;
        $alertCount = 0;

        foreach ($products as $p) {
            $stockMilli = $this->ledger->stockOf($p);
            $limit = $p->low_stock_at_milli ?? $lowThreshold;
            if ($stockMilli <= $limit) {
                $alertCount++;
                $mark = $stockMilli <= 0 ? '🚨' : '🔴';
                $stockQty = LedgerService::formatQty($stockMilli);
                $lowStockText .= "  {$mark} {$p->name}: {$stockQty} {$p->unit}\n";
                if ($alertCount >= 5) {
                    break;
                }
            }
        }

        if (empty($lowStockText)) {
            $lowStockText = $locale === 'en' ? "  🟢 All products in good stock\n" : "  🟢 ሁሉም ዕቃዎች በበቂ ስቶክ ላይ ናቸው\n";
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
                . "🏆 <b>Top Items Today:</b>\n{$topItemsText}\n"
                . "⚠️ <b>Low & Out of Stock:</b>\n{$lowStockText}";
        } else {
            $msg = "🌙 <b>የዕለቱ ማጠቃለያ — {$shop->name}</b>\n"
                . "📅 ቀን: <b>{$dateFormatted}</b>\n\n"
                . "💰 የዕለቱ ሽያጭ ገቢ: <b>{$revenueFormatted}</b>\n"
                . "🧾 የሽያጭ ብዛት: <b>{$salesCount} ግብይቶች</b>\n\n"
                . "🏆 <b>ተመራጭ የተሸጡ ዕቃዎች:</b>\n{$topItemsText}\n"
                . "⚠️ <b>አነስተኛ እና ያለቁ ዕቃዎች:</b>\n{$lowStockText}";
        }

        $this->telegram->sendMessage($owner->telegram_id, $msg);

        $shop->daily_summary_last_sent_date = $dateStr;
        $shop->save();

        return true;
    }
}
