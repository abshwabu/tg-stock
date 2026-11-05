<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Jobs\GenerateExportJob;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\LedgerService;
use App\Services\PaymentReportService;
use App\Services\PermissionService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use App\Support\EthiopicCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected LedgerService $ledger,
        protected PermissionService $permissionService,
        protected LocationHandler $locationHandler,
        protected PaymentReportService $paymentReportService
    ) {}

    /**
     * Show main reports menu based on user role and permissions.
     */
    public function handleMenu(int|string $chatId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || empty($user->shop_id)) {
            $this->telegram->sendMessage($chatId, __('unknown_command'));
            return;
        }

        $canViewAll = $this->permissionService->can($user, 'reports.view');
        $canViewOwn = $this->permissionService->can($user, 'reports.own_sales');

        if (!$canViewAll && !$canViewOwn) {
            $this->telegram->sendMessage($chatId, __('permission_denied'));
            return;
        }

        $shop = Shop::find($user->shop_id);
        $shopName = $shop?->name ?? 'Shop';

        // Staff sees only their own today's sales if they don't have full reports.view
        if (!$canViewAll && $canViewOwn) {
            $this->showStaffReport($chatId, $user, $shop, $locale);
            return;
        }

        // Full reports menu
        $text = $locale === 'en'
            ? "📊 <b>Reports & Analytics — {$shopName}</b>\nChoose a report period or action:"
            : "📊 <b>ሪፖርት እና ትንታኔ — {$shopName}</b>\nየሪፖርት ጊዜውን ወይም ዓይነት ይምረጡ:";

        $buttons = [
            [
                ['text' => '📅 ' . ($locale === 'en' ? 'Today' : 'ዛሬ'), 'callback_data' => 'rep:p:today'],
                ['text' => '📅 ' . ($locale === 'en' ? 'Yesterday' : 'ትናንት'), 'callback_data' => 'rep:p:yesterday'],
            ],
            [
                ['text' => '🗓 ' . ($locale === 'en' ? 'Last 7 Days' : 'ያለፉት 7 ቀናት'), 'callback_data' => 'rep:p:week'],
                ['text' => '🗓 ' . ($locale === 'en' ? 'This Month' : 'የዚህ ወር'), 'callback_data' => 'rep:p:month'],
            ],
            [
                ['text' => '🏢 ' . ($locale === 'en' ? 'Stock by Location' : 'ስቶክ በየቦታው'), 'callback_data' => 'rep:loc:stock'],
                ['text' => '🚚 ' . ($locale === 'en' ? 'In Transit' : 'በመንገድ ላይ'), 'callback_data' => 'rep:loc:transit'],
            ],
            [
                ['text' => '💳 ' . ($locale === 'en' ? 'Payments & Cash Drawer' : 'ክፍያዎችና ካዝና'), 'callback_data' => 'rep:pay:today'],
                ['text' => '⚠️ ' . ($locale === 'en' ? 'Transfer Discrepancies' : 'የዝውውር ልዩነቶች'), 'callback_data' => 'rep:loc:discrepancies'],
            ],
        ];

        $extraRow = [];
        if ($this->permissionService->can($user, 'cost.view')) {
            $extraRow[] = ['text' => '📦 ' . ($locale === 'en' ? 'Stock Valuation' : 'የስቶክ ዋጋ ግምት'), 'callback_data' => 'rep:stock:val'];
        }
        if ($this->permissionService->can($user, 'export')) {
            $extraRow[] = ['text' => '📥 ' . ($locale === 'en' ? 'Export CSV' : 'በኤክሴል አውርድ'), 'callback_data' => 'rep:exp:menu'];
        }
        if (!empty($extraRow)) {
            $buttons[] = $extraRow;
        }

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
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
            'reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user),
        ]);
    }

    /**
     * Handle period report callback with location filtering support.
     */
    public function handlePeriodReport(
        string $callbackId,
        int|string $chatId,
        int $messageId,
        string $period,
        ?User $user,
        ?string $locationId = null
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'reports.view')) {
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

        // Query sales movements with optional location filter
        $salesQuery = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('type', 'sale')
            ->whereBetween('created_at_ms', [$startMs, $endMs]);

        $locationName = null;
        if (!empty($locationId) && $locationId !== 'all') {
            $salesQuery->where('location_id', $locationId);
            $locModel = Location::withoutGlobalScope(ShopScope::class)->find($locationId);
            $locationName = $locModel?->name;
        }

        $salesRows = $salesQuery->get();
        $salesCount = $salesRows->count();
        $totalRevenueCents = 0;
        $totalCostCents = 0;
        $itemsWithCost = 0;
        $canSeeCost = $this->permissionService->can($user, 'cost.view');

        foreach ($salesRows as $s) {
            $qtySold = abs($s->qty_milli) / 1000;
            $saleRevenue = (int) round($qtySold * $s->unit_price_cents);
            $totalRevenueCents += $saleRevenue;

            if ($canSeeCost) {
                $prod = Product::withoutGlobalScope(ShopScope::class)->find($s->product_id);
                if ($prod) {
                    if ($prod->isStockable() || $prod->isService()) {
                        if ($prod->cost_price_cents > 0) {
                            $totalCostCents += (int) round($qtySold * $prod->cost_price_cents);
                            $itemsWithCost++;
                        }
                    } elseif ($prod->isConsumable()) {
                        $consumes = Movement::withoutGlobalScope(ShopScope::class)
                            ->where('shop_id', $shopId)
                            ->where('type', 'consume')
                            ->where('source_movement_id', $s->id)
                            ->get();

                        foreach ($consumes as $c) {
                            $matProd = Product::withoutGlobalScope(ShopScope::class)->find($c->product_id);
                            if ($matProd && $matProd->cost_price_cents > 0) {
                                $matQty = abs($c->qty_milli) / 1000;
                                $totalCostCents += (int) round($matQty * $matProd->cost_price_cents);
                                $itemsWithCost++;
                            }
                        }
                    }
                }
            }
        }

        $estProfitCents = ($canSeeCost && $itemsWithCost > 0) ? ($totalRevenueCents - $totalCostCents) : null;

        // Top 5 items
        $topQuery = Movement::withoutGlobalScope(ShopScope::class)
            ->select('product_id', DB::raw('SUM(-qty_milli) as total_qty'), DB::raw('SUM(-qty_milli * unit_price_cents / 1000) as total_revenue'))
            ->where('shop_id', $shopId)
            ->where('type', 'sale')
            ->whereBetween('created_at_ms', [$startMs, $endMs]);

        if (!empty($locationId) && $locationId !== 'all') {
            $topQuery->where('location_id', $locationId);
        }

        $topItems = $topQuery->groupBy('product_id')
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
        $profitText = $canSeeCost
            ? ($estProfitCents !== null ? EthiopicCalendar::formatMoney($estProfitCents) : ($locale === 'en' ? '— (Cost price missing)' : '— (የመግዣ ዋጋ አልተሞላም)'))
            : '—';

        $locHeader = $locationName
            ? "\n📍 " . ($locale === 'en' ? "Location: <b>{$locationName}</b>" : "ቦታ: <b>{$locationName}</b>")
            : "\n📍 " . ($locale === 'en' ? "Location: <b>All Locations</b>" : "ቦታ: <b>ሁሉም ቦታዎች</b>");

        if ($locale === 'en') {
            $text = "📊 <b>Sales Report: {$label}</b>\n"
                . "🏪 {$shop->name}{$locHeader}\n\n"
                . "💰 Revenue: <b>{$revenueFormatted}</b>\n"
                . "🧾 Sales Count: <b>{$salesCount} transactions</b>\n"
                . "📈 Estimated Profit: <b>{$profitText}</b>\n\n"
                . "🏆 <b>Top Items:</b>\n{$topText}";
        } else {
            $text = "📊 <b>የሽያጭ ሪፖርት: {$label}</b>\n"
                . "🏪 {$shop->name}{$locHeader}\n\n"
                . "💰 የሽያጭ ገቢ: <b>{$revenueFormatted}</b>\n"
                . "🧾 የሽያጭ ብዛት: <b>{$salesCount} ግብይቶች</b>\n"
                . "📈 የተጣራ ትርፍ (ግምት): <b>{$profitText}</b>\n\n"
                . "🏆 <b>ተመራጭ ዕቃዎች:</b>\n{$topText}";
        }

        if ($period === 'today') {
            $receivedData = $this->paymentReportService->receivedByMethod($shop, [$startMs, $endMs], $locationId, $locale);
            $byMethod = $receivedData['by_method'];
            $cashF = number_format(($byMethod['cash'] ?? 0) / 100, 2) . ' ETB';
            $bankF = number_format(($byMethod['bank'] ?? 0) / 100, 2) . ' ETB';
            $walletF = number_format(($byMethod['wallet'] ?? 0) / 100, 2) . ' ETB';

            $cashExp = $this->paymentReportService->cashExpected($shop, Carbon::now($tz)->toDateString(), $locationId);
            $cashExpF = number_format($cashExp['expected_cash_cents'] / 100, 2) . ' ETB';

            $totals = $this->paymentReportService->salesTotals($shop, [$startMs, $endMs], $locationId);
            $recTotalF = number_format($totals['money_received_cents'] / 100, 2) . ' ETB';
            $newDubeF = number_format($totals['new_dube_cents'] / 100, 2) . ' ETB';

            $outDube = $this->paymentReportService->outstandingDube($shop);
            $outDubeF = number_format($outDube['total_outstanding_cents'] / 100, 2) . ' ETB';
            $overdueList = $this->paymentReportService->overdueList($shop);
            $overdueCount = $overdueList->count();

            if ($locale === 'en') {
                $text .= "\n\n💳 <b>Money & Dube Today:</b>\n"
                    . "  • Money Received: <b>{$recTotalF}</b> (Cash: {$cashF}, Bank: {$bankF}, Wallet: {$walletF})\n"
                    . "  • Cash in Drawer: <b>{$cashExpF}</b>\n"
                    . "  • New Dube: <b>{$newDubeF}</b>\n"
                    . "  • Outstanding Dube: <b>{$outDubeF}</b> ({$overdueCount} overdue)";
            } else {
                $text .= "\n\n💳 <b>የዛሬ ገንዘብ እና ዱቤ:</b>\n"
                    . "  • የተሰበሰበ ገንዘብ: <b>{$recTotalF}</b> (ጥሬ: {$cashF}, ባንክ: {$bankF}, ዋሌት: {$walletF})\n"
                    . "  • በካዝና ውስጥ ያለ ጥሬ ገንዘብ: <b>{$cashExpF}</b>\n"
                    . "  • አዲስ የተሰጠ ዱቤ: <b>{$newDubeF}</b>\n"
                    . "  • ያልተከፈለ ዱቤ: <b>{$outDubeF}</b> ({$overdueCount} ጊዜው ያለፈበት)";
            }
        }

        $buttons = [];

        // Location filter buttons if shop has multiple locations
        $allowedLocs = $this->locationHandler->getAllowedLocations($user);
        if ($allowedLocs->count() > 1) {
            $filterRow = [];
            $isAll = empty($locationId) || $locationId === 'all';
            $filterRow[] = [
                'text' => ($isAll ? '✓ ' : '') . ($locale === 'en' ? 'All' : 'ሁሉም'),
                'callback_data' => "rep:p:{$period}:all",
            ];

            foreach ($allowedLocs as $al) {
                $isCur = $locationId === (string) $al->id;
                $filterRow[] = [
                    'text' => ($isCur ? '✓ ' : '') . $al->name,
                    'callback_data' => "rep:p:{$period}:{$al->id}",
                ];
                if (count($filterRow) === 3) {
                    $buttons[] = $filterRow;
                    $filterRow = [];
                }
            }
            if (!empty($filterRow)) {
                $buttons[] = $filterRow;
            }
        }

        if ($this->permissionService->can($user, 'export')) {
            $locParam = !empty($locationId) ? $locationId : 'all';
            $buttons[] = [
                ['text' => '📥 ' . ($locale === 'en' ? 'Export CSV' : 'በኤክሴል አውርድ'), 'callback_data' => "rep:exp:p:{$period}:{$locParam}"],
            ];
        }

        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Reports' : 'ወደ ሪፖርቶች ተመለስ'), 'callback_data' => 'rep:menu'],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Stock by location report: shows balance for all authorized locations.
     */
    public function handleStockByLocation(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'reports.view')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $shop = Shop::find($user->shop_id);
        $allowedLocs = $this->locationHandler->getAllowedLocations($user);

        if ($allowedLocs->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'No authorized locations found.');
            return;
        }

        $lines = [];
        foreach ($allowedLocs as $loc) {
            $stockSummary = Movement::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $user->shop_id)
                ->where('location_id', $loc->id)
                ->select([
                    DB::raw('COUNT(DISTINCT product_id) as total_products'),
                    DB::raw('CAST(COALESCE(SUM(qty_milli), 0) AS INTEGER) as total_qty_milli'),
                ])
                ->first();

            $totalProds = (int) ($stockSummary->total_products ?? 0);
            $totalUnits = LedgerService::formatQty((int) ($stockSummary->total_qty_milli ?? 0));
            $kindIcon = $loc->kind === 'warehouse' ? '🏭' : '🏬';

            $lines[] = "{$kindIcon} <b>{$loc->name}</b>\n"
                . "• " . ($locale === 'en' ? 'Distinct Products:' : 'የተለያዩ ዕቃዎች:') . " <b>{$totalProds}</b>\n"
                . "• " . ($locale === 'en' ? 'Total Quantity Units:' : 'ጠቅላላ የዕቃ ብዛት:') . " <b>{$totalUnits}</b>";
        }

        $body = implode("\n\n", $lines);

        $text = $locale === 'en'
            ? "🏢 <b>Stock by Location — {$shop->name}</b>\n\n{$body}"
            : "🏢 <b>ስቶክ በየቦታው — {$shop->name}</b>\n\n{$body}";

        $buttons = [];
        if ($this->permissionService->can($user, 'export')) {
            $buttons[] = [
                ['text' => '📥 ' . ($locale === 'en' ? 'Export CSV' : 'በኤክሴል አውርድ'), 'callback_data' => 'rep:exp:stock_by_loc'],
            ];
        }
        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Reports' : 'ወደ ሪፖርቶች ተመለስ'), 'callback_data' => 'rep:menu'],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * In-transit inventory report.
     */
    public function handleInTransitReport(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'reports.view')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $shop = Shop::find($user->shop_id);
        $transits = $this->ledger->inTransit($shop);

        if ($transits->isEmpty()) {
            $text = $locale === 'en'
                ? "🚚 <b>In-Transit Inventory — {$shop->name}</b>\n\n✅ <b>No transfers in transit!</b> All dispatched transfers have been received."
                : "🚚 <b>በመንገድ ላይ ያለ ዕቃ — {$shop->name}</b>\n\n✅ <b>በመንገድ ላይ ያለ ዕቃ የለም!</b> የተላኩ ዝውውሮች በሙሉ ደርሰው ተረጋግጠዋል።";
        } else {
            $text = $locale === 'en'
                ? "🚚 <b>In-Transit Inventory ({$transits->count()} active)</b>\n\n"
                : "🚚 <b>በመንገድ ላይ ያሉ ዝውውሮች ({$transits->count()})</b>\n\n";

            foreach ($transits as $t) {
                $shortId = substr($t['transfer_id'], 0, 8);
                $pName = $t['product_name'] ?? 'Product';
                $from = $t['from_location_name'] ?? 'Origin';
                $to = $t['to_location_name'] ?? 'Destination';
                $qty = $t['qty'];
                $sentBy = $t['sent_by'] ?? 'Staff';

                $text .= "• <code>[#{$shortId}]</code> <b>{$pName}</b> × <b>{$qty}</b>\n"
                    . "  ↳ {$from} ➔ {$to} (" . ($locale === 'en' ? 'Sent by:' : 'የላከው:') . " {$sentBy})\n";
            }
        }

        $buttons = [];
        if (!$transits->isEmpty() && $this->permissionService->can($user, 'export')) {
            $buttons[] = [
                ['text' => '📥 ' . ($locale === 'en' ? 'Export CSV' : 'በኤክሴል አውርድ'), 'callback_data' => 'rep:exp:transit'],
            ];
        }
        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Reports' : 'ወደ ሪፖርቶች ተመለስ'), 'callback_data' => 'rep:menu'],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Transfer discrepancies report.
     */
    public function handleDiscrepanciesReport(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'reports.view')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $shop = Shop::find($user->shop_id);
        $discrepancies = $this->ledger->discrepancies($shop);

        if ($discrepancies->isEmpty()) {
            $text = $locale === 'en'
                ? "⚠️ <b>Transfer Discrepancies — {$shop->name}</b>\n\n✅ <b>No discrepancies recorded!</b> All received transfers matched sent quantities perfectly."
                : "⚠️ <b>የዝውውር ልዩነቶች — {$shop->name}</b>\n\n✅ <b>ምንም የተመዘገበ ልዩነት የለም!</b> የተላለፉ ዕቃዎች ሙሉ በሙሉ በትክክል ደርሰዋል።";
        } else {
            $text = $locale === 'en'
                ? "⚠️ <b>Transfer Discrepancies ({$discrepancies->count()} incidents)</b>\n\n"
                : "⚠️ <b>የዝውውር ልዩነቶች ({$discrepancies->count()})</b>\n\n";

            foreach ($discrepancies as $d) {
                $shortId = substr($d['transfer_id'], 0, 8);
                $pName = $d['product_name'] ?? 'Product';
                $from = $d['from_location_name'] ?? 'Origin';
                $to = $d['to_location_name'] ?? 'Destination';
                $sent = $d['sent_qty'];
                $rcvd = $d['received_qty'];
                $diff = $d['difference_qty'];
                $diffSign = $d['difference_milli'] > 0 ? "+{$diff}" : $diff;

                $text .= "• <code>[#{$shortId}]</code> <b>{$pName}</b> ({$from} ➔ {$to})\n"
                    . "  ↳ " . ($locale === 'en' ? 'Sent:' : 'የተላከው:') . " <b>{$sent}</b> | "
                    . ($locale === 'en' ? 'Received:' : 'የደረሰው:') . " <b>{$rcvd}</b> ("
                    . ($locale === 'en' ? 'Diff:' : 'ልዩነት:') . " <b>{$diffSign}</b>)\n";
            }
        }

        $buttons = [];
        if (!$discrepancies->isEmpty() && $this->permissionService->can($user, 'export')) {
            $buttons[] = [
                ['text' => '📥 ' . ($locale === 'en' ? 'Export CSV' : 'በኤክሴል አውርድ'), 'callback_data' => 'rep:exp:discrepancies'],
            ];
        }
        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Reports' : 'ወደ ሪፖርቶች ተመለስ'), 'callback_data' => 'rep:menu'],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Stock valuation report: total inventory at cost and selling price.
     */
    public function handleStockValuation(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'cost.view')) {
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

        $buttons = [];
        if ($this->permissionService->can($user, 'export')) {
            $buttons[] = [
                ['text' => '📥 ' . ($locale === 'en' ? 'Export CSV' : 'በኤክሴል አውርድ'), 'callback_data' => 'rep:exp:valuation'],
            ];
        }
        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Reports' : 'ወደ ሪፖርቶች ተመለስ'), 'callback_data' => 'rep:menu'],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Show Export CSV menu.
     */
    public function handleExportMenu(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'export')) {
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
                ['text' => '🛒 ' . ($locale === 'en' ? 'Sales History' : 'የሽያጭ ታሪክ'), 'callback_data' => 'rep:exp:sales'],
            ],
            [
                ['text' => '🏢 ' . ($locale === 'en' ? 'Stock by Location' : 'ስቶክ በየቦታው'), 'callback_data' => 'rep:exp:stock_by_loc'],
                ['text' => '📦 ' . ($locale === 'en' ? 'Stock Valuation' : 'የስቶክ ዋጋ ግምት'), 'callback_data' => 'rep:exp:valuation'],
            ],
            [
                ['text' => '🚚 ' . ($locale === 'en' ? 'In Transit' : 'በመንገድ ላይ'), 'callback_data' => 'rep:exp:transit'],
                ['text' => '⚠️ ' . ($locale === 'en' ? 'Discrepancies' : 'የዝውውር ልዩነቶች'), 'callback_data' => 'rep:exp:discrepancies'],
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
    public function dispatchExport(
        string $callbackId,
        int|string $chatId,
        string $type,
        ?User $user,
        array $parts = []
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'export')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $period = null;
        $locationId = null;

        if ($type === 'p') {
            $type = 'period_sales';
            $period = $parts[3] ?? 'today';
            $locationId = $parts[4] ?? null;
            if ($locationId === 'all') {
                $locationId = null;
            }
        }

        $this->telegram->answerCallbackQuery(
            $callbackId,
            $locale === 'en' ? 'Generating export file...' : 'የኤክስፖርት ፋይል እየተዘጋጀ ነው...'
        );

        GenerateExportJob::dispatchSync(
            $type,
            (string) $user->shop_id,
            $chatId,
            $locale,
            $period,
            $locationId
        );
    }

    /**
     * Show dedicated payments, cash drawer, and Dube overview.
     */
    public function showPaymentsAndDubeReport(
        string $callbackId,
        int|string $chatId,
        int $messageId,
        ?User $user
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'reports.view')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $shop = Shop::find($user->shop_id);
        $tz = $shop?->timezone ?: 'Africa/Addis_Ababa';
        $now = Carbon::now($tz);
        $todayStr = $now->toDateString();
        $startMs = $now->copy()->startOfDay()->timestamp * 1000;
        $endMs = $now->copy()->endOfDay()->timestamp * 1000;

        $receivedData = $this->paymentReportService->receivedByMethod($shop, [$startMs, $endMs], null, $locale);
        $byMethod = $receivedData['by_method'];
        $cashF = number_format(($byMethod['cash'] ?? 0) / 100, 2) . ' ETB';
        $bankF = number_format(($byMethod['bank'] ?? 0) / 100, 2) . ' ETB';
        $walletF = number_format(($byMethod['wallet'] ?? 0) / 100, 2) . ' ETB';
        $totalRecF = number_format($receivedData['grand_total_cents'] / 100, 2) . ' ETB';

        $cashExp = $this->paymentReportService->cashExpected($shop, $todayStr);
        $cashExpF = number_format($cashExp['expected_cash_cents'] / 100, 2) . ' ETB';

        $totals = $this->paymentReportService->salesTotals($shop, [$startMs, $endMs]);
        $revF = number_format($totals['revenue_cents'] / 100, 2) . ' ETB';
        $newDubeF = number_format($totals['new_dube_cents'] / 100, 2) . ' ETB';

        $outDube = $this->paymentReportService->outstandingDube($shop);
        $outDubeF = number_format($outDube['total_outstanding_cents'] / 100, 2) . ' ETB';
        $debtorsCount = $outDube['total_customers_with_dube'];

        $overdueList = $this->paymentReportService->overdueList($shop);
        $overdueCount = $overdueList->count();
        $overdueF = number_format($overdueList->sum('remaining_balance_cents') / 100, 2) . ' ETB';

        // Provider breakdown
        $provLines = '';
        foreach ($receivedData['by_provider'] as $prov) {
            $amtF = number_format($prov['net_amount_cents'] / 100, 2) . ' ETB';
            $provLines .= "  • {$prov['provider_name']}: <b>{$amtF}</b> ({$prov['count']} txns)\n";
        }
        if (empty($provLines)) {
            $provLines = "  " . ($locale === 'en' ? '(None recorded today)' : '(ዛሬ አልተመዘገበም)') . "\n";
        }

        $text = $locale === 'en'
            ? "💳 <b>Payments, Cash Drawer & Dube Report — {$shop->name}</b>\n"
                . "📅 Today: <b>{$todayStr}</b>\n\n"
                . "💵 <b>Cash Expected in Drawer:</b> <b>{$cashExpF}</b>\n"
                . "<i>(Cash sales + Cash repayments - Reversals)</i>\n\n"
                . "💰 <b>Revenue vs Received vs Dube:</b>\n"
                . "  • Sales Revenue: <b>{$revF}</b>\n"
                . "  • Money Received: <b>{$totalRecF}</b>\n"
                . "  • New Dube Today: <b>{$newDubeF}</b>\n\n"
                . "💳 <b>Received by Payment Method:</b>\n"
                . "  • 💵 Cash: <b>{$cashF}</b>\n"
                . "  • 🏦 Bank: <b>{$bankF}</b>\n"
                . "  • 📱 Wallet: <b>{$walletF}</b>\n\n"
                . "🏦 <b>Received by Bank/Wallet Provider:</b>\n{$provLines}\n"
                . "📒 <b>Outstanding Dube:</b>\n"
                . "  • Total Outstanding: <b>{$outDubeF}</b> ({$debtorsCount} customers)\n"
                . "  • 🔴 Overdue: <b>{$overdueF}</b> ({$overdueCount} sales overdue)"
            : "💳 <b>የክፍያዎች፣ የካዝና እና የዱቤ ሪፖርት — {$shop->name}</b>\n"
                . "📅 ዛሬ: <b>{$todayStr}</b>\n\n"
                . "💵 <b>በካዝና ውስጥ የሚጠበቅ ጥሬ ገንዘብ:</b> <b>{$cashExpF}</b>\n"
                . "<i>(የጥሬ ሽያጭ + የጥሬ ዕዳ ክፍያ - የተመለሰ)</i>\n\n"
                . "💰 <b>ሽያጭ vs ገቢ የተደረገ vs ዱቤ:</b>\n"
                . "  • ጠቅላላ የሽያጭ ዋጋ: <b>{$revF}</b>\n"
                . "  • የተሰበሰበ ገንዘብ: <b>{$totalRecF}</b>\n"
                . "  • አዲስ የተሰጠ ዱቤ: <b>{$newDubeF}</b>\n\n"
                . "💳 <b>በክፍያ መንገድ:</b>\n"
                . "  • 💵 ጥሬ ገንዘብ: <b>{$cashF}</b>\n"
                . "  • 🏦 ባንክ: <b>{$bankF}</b>\n"
                . "  • 📱 ዋሌት: <b>{$walletF}</b>\n\n"
                . "🏦 <b>በየባንኩ/ዋሌቱ ዝርዝር:</b>\n{$provLines}\n"
                . "📒 <b>ያልተከፈለ ዱቤ:</b>\n"
                . "  • ጠቅላላ ያልተከፈለ: <b>{$outDubeF}</b> ({$debtorsCount} ደንበኞች)\n"
                . "  • 🔴 ጊዜው ያለፈበት: <b>{$overdueF}</b> ({$overdueCount} ሽያጮች)";

        $buttons = [
            [
                ['text' => '📒 ' . ($locale === 'en' ? 'Open Dube Menu' : 'ወደ ዱቤ ማውጫ'), 'callback_data' => 'dube:page:1'],
                ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Reports' : 'ወደ ሪፖርቶች'), 'callback_data' => 'rep:menu:0'],
            ],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }
}
