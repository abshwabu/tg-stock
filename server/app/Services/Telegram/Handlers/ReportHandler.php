<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Jobs\GenerateExportJob;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\LedgerService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use App\Support\EthiopicCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected LedgerService $ledger
    ) {}

    /**
     * Show main reports menu based on user role.
     */
    public function handleMenu(int|string $chatId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || empty($user->shop_id)) {
            $this->telegram->sendMessage($chatId, __('unknown_command'));
            return;
        }

        $shop = Shop::find($user->shop_id);
        $shopName = $shop?->name ?? 'Shop';

        // Staff sees only their own today's sales
        if ($user->role !== 'owner') {
            $this->showStaffReport($chatId, $user, $shop, $locale);
            return;
        }

        // Owner menu
        $text = $locale === 'en'
            ? "📊 <b>Reports & Analytics — {$shopName}</b>\nChoose a report period or action:"
            : "📊 <b>ሪፖርት እና ትንታኔ — {$shopName}</b>\nየሪፖርት ጊዜውን ወይም ዓይነት ይምረጡ:";

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => '📅 ' . ($locale === 'en' ? 'Today' : 'ዛሬ'), 'callback_data' => 'rep:p:today'],
                ['text' => '📅 ' . ($locale === 'en' ? 'Yesterday' : 'ትናንት'), 'callback_data' => 'rep:p:yesterday'],
            ],
            [
                ['text' => '🗓 ' . ($locale === 'en' ? 'Last 7 Days' : 'ያለፉት 7 ቀናት'), 'callback_data' => 'rep:p:week'],
                ['text' => '🗓 ' . ($locale === 'en' ? 'This Month' : 'የዚህ ወር'), 'callback_data' => 'rep:p:month'],
            ],
            [
                ['text' => '📦 ' . ($locale === 'en' ? 'Stock Valuation' : 'የስቶክ ዋጋ ግምት'), 'callback_data' => 'rep:stock:val'],
                ['text' => '📥 ' . ($locale === 'en' ? 'Export CSV' : 'በኤክሴል አውርድ'), 'callback_data' => 'rep:exp:menu'],
            ],
        ]);

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Staff report: shows only the staff member's own sales today.
     */
    public function showStaffReport(int|string $chatId, User $user, ?Shop $shop, string $locale): void
    {
        $tz = $shop?->timezone ?: 'Africa/Addis_Ababa';
        $now = Carbon::now($tz);
        $startMs = $now->copy()->startOfDay()->timestamp * 1000;
        $endMs = $now->copy()->endOfDay()->timestamp * 1000;

        $sales = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('user_id', $user->id)
            ->where('type', 'sale')
            ->whereBetween('created_at_ms', [$startMs, $endMs])
            ->get();

        $count = $sales->count();
        $totalCents = 0;
        foreach ($sales as $s) {
            $totalCents += (int) round((abs($s->qty_milli) * $s->unit_price_cents) / 1000);
        }

        $revFormatted = EthiopicCalendar::formatMoney($totalCents);
        $dateFormatted = $shop?->use_ethiopian_calendar
            ? EthiopicCalendar::format($now, $locale)
            : $now->format('M j, Y');

        if ($locale === 'en') {
            $msg = "📊 <b>Your Sales Today — {$user->name}</b>\n"
                . "📅 Date: <b>{$dateFormatted}</b>\n\n"
                . "💰 Your Sales Total: <b>{$revFormatted}</b>\n"
                . "🧾 Transactions Recorded: <b>{$count}</b>";
        } else {
            $msg = "📊 <b>የዛሬ ሽያጭዎ — {$user->name}</b>\n"
                . "📅 ቀን: <b>{$dateFormatted}</b>\n\n"
                . "💰 የሽያጭ ድምር: <b>{$revFormatted}</b>\n"
                . "🧾 የተመዘገቡ ግብይቶች: <b>{$count}</b>";
        }

        $this->telegram->sendMessage($chatId, $msg, [
            'reply_markup' => TelegramUi::mainMenuKeyboard($locale),
        ]);
    }

    /**
     * Handle period report callback for Owner.
     */
    public function handlePeriodReport(string $callbackId, int|string $chatId, int $messageId, string $period, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $shop = Shop::find($user->shop_id);
        $tz = $shop?->timezone ?: 'Africa/Addis_Ababa';
        $now = Carbon::now($tz);

        [$startMs, $endMs, $label] = match ($period) {
            'yesterday' => [
                $now->copy()->subDay()->startOfDay()->timestamp * 1000,
                $now->copy()->subDay()->endOfDay()->timestamp * 1000,
                $locale === 'en' ? 'Yesterday' : 'ትናንት',
            ],
            'week' => [
                $now->copy()->subDays(6)->startOfDay()->timestamp * 1000,
                $now->copy()->endOfDay()->timestamp * 1000,
                $locale === 'en' ? 'Last 7 Days' : 'ያለፉት 7 ቀናት',
            ],
            'month' => [
                $now->copy()->startOfMonth()->timestamp * 1000,
                $now->copy()->endOfDay()->timestamp * 1000,
                $locale === 'en' ? 'This Month' : 'የዚህ ወር',
            ],
            default => [
                $now->copy()->startOfDay()->timestamp * 1000,
                $now->copy()->endOfDay()->timestamp * 1000,
                $locale === 'en' ? 'Today' : 'ዛሬ',
            ],
        };

        $shopId = (string) $user->shop_id;

        // 1. Sales and Revenue Aggregation
        $salesRows = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('type', 'sale')
            ->whereBetween('created_at_ms', [$startMs, $endMs])
            ->get();

        $salesCount = $salesRows->count();
        $totalRevenueCents = 0;
        $totalCostCents = 0;
        $itemsWithCost = 0;

        foreach ($salesRows as $s) {
            $qtySold = abs($s->qty_milli) / 1000;
            $saleRevenue = (int) round($qtySold * $s->unit_price_cents);
            $totalRevenueCents += $saleRevenue;

            $prod = Product::withoutGlobalScope(ShopScope::class)->find($s->product_id);
            if ($prod && $prod->cost_price_cents > 0) {
                $totalCostCents += (int) round($qtySold * $prod->cost_price_cents);
                $itemsWithCost++;
            }
        }

        $estProfitCents = $itemsWithCost > 0 ? ($totalRevenueCents - $totalCostCents) : null;

        // 2. Top 5 items by revenue & quantity
        $topItems = Movement::withoutGlobalScope(ShopScope::class)
            ->select('product_id', DB::raw('SUM(-qty_milli) as total_qty'), DB::raw('SUM(-qty_milli * unit_price_cents / 1000) as total_revenue'))
            ->where('shop_id', $shopId)
            ->where('type', 'sale')
            ->whereBetween('created_at_ms', [$startMs, $endMs])
            ->groupBy('product_id')
            ->orderByDesc('total_revenue')
            ->limit(5)
            ->get();

        $topText = '';
        foreach ($topItems as $idx => $ti) {
            $p = Product::withoutGlobalScope(ShopScope::class)->find($ti->product_id);
            if ($p) {
                $num = $idx + 1;
                $qty = LedgerService::formatQty((int) $ti->total_qty);
                $rev = EthiopicCalendar::formatMoney((int) $ti->total_revenue);
                $topText .= "  {$num}. {$p->name} — {$qty} {$p->unit} ({$rev})\n";
            }
        }

        if (empty($topText)) {
            $topText = "  " . ($locale === 'en' ? '(No sales recorded in this period)' : '(በዚህ ጊዜ የተመዘገበ ሽያጭ የለም)') . "\n";
        }

        $revenueFormatted = EthiopicCalendar::formatMoney($totalRevenueCents);
        $profitText = $estProfitCents !== null
            ? EthiopicCalendar::formatMoney($estProfitCents)
            : ($locale === 'en' ? '— (Cost price not configured)' : '— (የመግዣ ዋጋ አልተሞላም)');

        if ($locale === 'en') {
            $text = "📊 <b>Sales Report: {$label}</b>\n"
                . "🏪 {$shop->name}\n\n"
                . "💰 Revenue: <b>{$revenueFormatted}</b>\n"
                . "🧾 Sales Count: <b>{$salesCount} transactions</b>\n"
                . "📈 Estimated Profit: <b>{$profitText}</b>\n\n"
                . "🏆 <b>Top 5 Items:</b>\n{$topText}";
        } else {
            $text = "📊 <b>የሽያጭ ሪፖርት: {$label}</b>\n"
                . "🏪 {$shop->name}\n\n"
                . "💰 የሽያጭ ገቢ: <b>{$revenueFormatted}</b>\n"
                . "🧾 የሽያጭ ብዛት: <b>{$salesCount} ግብይቶች</b>\n"
                . "📈 የተጣራ ትርፍ (ግምት): <b>{$profitText}</b>\n\n"
                . "🏆 <b>ተመራጭ 5 ዕቃዎች:</b>\n{$topText}";
        }

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Reports' : 'ወደ ሪፖርቶች ተመለስ'), 'callback_data' => 'rep:menu'],
            ],
        ]);

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Stock valuation report: total inventory at cost and selling price.
     */
    public function handleStockValuation(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $shop = Shop::find($user->shop_id);
        $stockList = $this->ledger->stockList((string) $user->shop_id);

        $totalProducts = $stockList->count();
        $totalCostCents = 0;
        $totalSellCents = 0;
        $totalUnits = 0.0;

        foreach ($stockList as $item) {
            $stockQty = $item->stock_milli / 1000;
            if ($stockQty > 0) {
                $totalUnits += $stockQty;
                $totalCostCents += (int) round($stockQty * $item->cost_price_cents);
                $totalSellCents += (int) round($stockQty * $item->sell_price_cents);
            }
        }

        $costFormatted = EthiopicCalendar::formatMoney($totalCostCents);
        $sellFormatted = EthiopicCalendar::formatMoney($totalSellCents);
        $potentialMarginCents = max(0, $totalSellCents - $totalCostCents);
        $marginFormatted = EthiopicCalendar::formatMoney($potentialMarginCents);

        if ($locale === 'en') {
            $text = "📦 <b>Stock Valuation — {$shop->name}</b>\n\n"
                . "• Total Active Products: <b>{$totalProducts}</b>\n"
                . "• Total Physical Units: <b>" . number_format($totalUnits, 1) . "</b>\n\n"
                . "💰 Value at Cost Price: <b>{$costFormatted}</b>\n"
                . "🏷 Value at Selling Price: <b>{$sellFormatted}</b>\n"
                . "📈 Potential Gross Margin: <b>{$marginFormatted}</b>";
        } else {
            $text = "📦 <b>የስቶክ ካፒታል ግምት — {$shop->name}</b>\n\n"
                . "• ጠቅላላ ዕቃዎች ብዛት: <b>{$totalProducts}</b>\n"
                . "• አጠቃላይ የስቶክ ብዛት: <b>" . number_format($totalUnits, 1) . "</b>\n\n"
                . "💰 ዋጋ በመግዣ ዋጋ: <b>{$costFormatted}</b>\n"
                . "🏷 ዋጋ በመሸጫ ዋጋ: <b>{$sellFormatted}</b>\n"
                . "📈 የሚጠበቅ ጠቅላላ ትርፍ: <b>{$marginFormatted}</b>";
        }

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Reports' : 'ወደ ሪፖርቶች ተመለስ'), 'callback_data' => 'rep:menu'],
            ],
        ]);

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Show Export CSV menu.
     */
    public function handleExportMenu(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $text = $locale === 'en'
            ? "📥 <b>Export Data to Excel/CSV</b>\nFiles are encoded with UTF-8 BOM for perfect Amharic Excel display:"
            : "📥 <b>መረጃዎችን በኤክሴል (CSV) አውርድ</b>\nየአማርኛ ጽሑፍ በኤክሴል እንዳይበላሽ በ UTF-8 የተዘጋጀ:";

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => '📦 ' . ($locale === 'en' ? 'Products List' : 'የዕቃዎች ዝርዝር'), 'callback_data' => 'rep:exp:products'],
            ],
            [
                ['text' => '🛒 ' . ($locale === 'en' ? 'Sales History' : 'የሽያጭ ታሪክ'), 'callback_data' => 'rep:exp:sales'],
            ],
            [
                ['text' => '📜 ' . ($locale === 'en' ? 'Complete Ledger Movements' : 'ሙሉ የስቶክ እንቅስቃሴ መዝገብ'), 'callback_data' => 'rep:exp:movements'],
            ],
            [
                ['text' => '◀️ ' . ($locale === 'en' ? 'Back' : 'ተመለስ'), 'callback_data' => 'rep:menu'],
            ],
        ]);

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Dispatch CSV export generation job.
     */
    public function dispatchExport(string $callbackId, int|string $chatId, string $type, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery(
            $callbackId,
            $locale === 'en' ? 'Generating export file...' : 'የኤክስፖርት ፋይል እየተዘጋጀ ነው...'
        );

        GenerateExportJob::dispatch(
            $type,
            (string) $user->shop_id,
            $chatId,
            $locale
        );
    }
}
