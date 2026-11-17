<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\CashFlowEntry;
use App\Models\Change;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\LicenseEnforcer;
use App\Services\PaymentReportService;
use App\Services\PermissionService;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use App\Support\EthiopicCalendar;
use Carbon\Carbon;
use Illuminate\Support\Str;

class CashFlowHandler
{
    public const CATEGORIES_INCOME = [
        'capital' => ['am' => '💼 ተጨማሪ ካፒታል', 'en' => '💼 Additional Capital'],
        'refund' => ['am' => '🔄 ተመላሽ ገንዘብ', 'en' => '🔄 Refund / Returns'],
        'commission' => ['am' => '💡 ኮሚሽን / አገልግሎት', 'en' => '💡 Commission / Service'],
        'gift' => ['am' => '🎁 ስጦታ / ጉርሻ', 'en' => '🎁 Gift / Bonus'],
        'other_inc' => ['am' => '🏷️ ሌላ ገቢ', 'en' => '🏷️ Other Income'],
    ];

    public const CATEGORIES_EXPENSE = [
        'rent' => ['am' => '🏠 ኪራይ', 'en' => '🏠 Rent'],
        'utilities' => ['am' => '💡 መብራትና ውሃ', 'en' => '💡 Utilities'],
        'transport' => ['am' => '🚚 ትራንስፖርት', 'en' => '🚚 Transport'],
        'food' => ['am' => '🍽️ ምሳና ሻይ', 'en' => '🍽️ Food / Lunch'],
        'packaging' => ['am' => '📦 ማሸጊያ', 'en' => '📦 Packaging'],
        'salary' => ['am' => '👥 ደመወዝ', 'en' => '👥 Salary'],
        'other_exp' => ['am' => '🏷️ ሌላ ወጪ', 'en' => '🏷️ Other Expense'],
    ];

    public const METHODS = [
        'cash' => ['am' => '💵 ጥሬ ገንዘብ', 'en' => '💵 Cash'],
        'bank' => ['am' => '🏦 የባንክ ሂሳብ', 'en' => '🏦 Bank Transfer'],
        'wallet' => ['am' => '📱 ቴሌብር / ዋሌት', 'en' => '📱 Telebirr / Wallet'],
    ];

    public function __construct(
        protected TelegramClient $telegram,
        protected BotStateService $botState,
        protected PermissionService $permissionService,
        protected PaymentReportService $reportService,
        protected LicenseEnforcer $licenseEnforcer,
        protected LicenseBotHandler $licenseBotHandler
    ) {}

    /**
     * Top-level Cash Flow Menu.
     */
    public function handleMenu(int|string $chatId, ?User $user, ?string $cqId = null, ?int $messageId = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($cqId !== null) {
            $this->telegram->answerCallbackQuery($cqId);
        }

        if ($user === null || empty($user->shop_id)) {
            $this->telegram->sendMessage($chatId, __('unknown_command'));
            return;
        }

        if (!$this->canView($user)) {
            $this->telegram->sendMessage($chatId, __('permission_denied'), [
                'reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user),
            ]);
            return;
        }

        $shop = Shop::find($user->shop_id);
        $shopName = $shop?->name ?? 'Shop';
        $tz = $shop?->timezone ?: 'Africa/Addis_Ababa';
        $now = Carbon::now($tz);

        $startMs = $now->copy()->startOfDay()->timestamp * 1000;
        $endMs = $now->copy()->endOfDay()->timestamp * 1000;

        $summary = $this->getSummary((string) $user->shop_id, $startMs, $endMs);

        $incF = number_format($summary['income_cents'] / 100, 2) . ' ETB';
        $expF = number_format($summary['expense_cents'] / 100, 2) . ' ETB';
        $net = $summary['income_cents'] - $summary['expense_cents'];
        $netSign = $net >= 0 ? '+' : '-';
        $netF = $netSign . number_format(abs($net) / 100, 2) . ' ETB';

        $dateFormatted = $shop?->use_ethiopian_calendar
            ? EthiopicCalendar::format($now, $locale)
            : $now->format('M j, Y');

        if ($locale === 'en') {
            $text = "💸 <b>Income & Expense Management</b>\n"
                . "🏪 <b>{$shopName}</b>\n"
                . "📅 Date: <b>{$dateFormatted} (Today)</b>\n\n"
                . "🟢 Extra Income: <b>+{$incF}</b> ({$summary['income_count']})\n"
                . "🔴 Extra Expenses: <b>-{$expF}</b> ({$summary['expense_count']})\n"
                . "⚖️ Net Balance: <b>{$netF}</b>\n\n"
                . "Select an action below:";
        } else {
            $text = "💸 <b>የገቢና ወጪ አስተዳደር</b>\n"
                . "🏪 <b>{$shopName}</b>\n"
                . "📅 ቀን: <b>{$dateFormatted} (ዛሬ)</b>\n\n"
                . "🟢 ተጨማሪ ገቢ: <b>+{$incF}</b> ({$summary['income_count']})\n"
                . "🔴 ተጨማሪ ወጪ: <b>-{$expF}</b> ({$summary['expense_count']})\n"
                . "⚖️ የተጣራ (Net): <b>{$netF}</b>\n\n"
                . "ከታች ካሉት አማራጮች አንዱን ይምረጡ:";
        }

        $buttons = [
            [
                ['text' => $locale === 'en' ? '➕ Add Income' : '➕ ገቢ መዝግብ', 'callback_data' => 'cf:add:income'],
                ['text' => $locale === 'en' ? '➖ Add Expense' : '➖ ወጪ መዝግብ', 'callback_data' => 'cf:add:expense'],
            ],
            [
                ['text' => $locale === 'en' ? '📋 Today\'s Entries' : '📋 የዛሬ መዝገቦች', 'callback_data' => 'cf:list:today'],
                ['text' => $locale === 'en' ? '🗓 This Month' : '🗓 የዚህ ወር', 'callback_data' => 'cf:list:month'],
            ],
            [
                ['text' => $locale === 'en' ? '🔙 Main Menu' : '🔙 ዋና ማውጫ', 'callback_data' => 'menu:main:0'],
            ],
        ];

        if ($messageId !== null) {
            $this->telegram->editMessageText($chatId, $messageId, $text, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
        } else {
            $this->telegram->sendMessage($chatId, $text, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
        }
    }

    /**
     * Start recording income or expense.
     */
    public function startAdd(int|string $chatId, string $type, ?User $user, ?string $cqId = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($cqId !== null) {
            $this->telegram->answerCallbackQuery($cqId);
        }

        if ($user === null || empty($user->shop_id)) {
            $this->telegram->sendMessage($chatId, __('unknown_command'));
            return;
        }

        $shop = Shop::find($user->shop_id);
        if ($shop && !$this->licenseEnforcer->canWrite($shop)) {
            $this->licenseBotHandler->sendRenewalBlockNotice($chatId, $user);
            return;
        }

        if (!$this->canCreate($user)) {
            $this->telegram->sendMessage($chatId, __('permission_denied'), [
                'reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user),
            ]);
            return;
        }

        $type = in_array($type, ['income', 'expense'], true) ? $type : 'expense';

        $this->botState->set($chatId, 'cash_flow', 'await_amount', [
            'type' => $type,
        ]);

        $isIncome = $type === 'income';
        if ($locale === 'en') {
            $title = $isIncome ? '🟢 <b>Record Extra Income</b>' : '🔴 <b>Record Extra Expense</b>';
            $prompt = "{$title}\n\nPlease enter the amount in ETB (e.g. 250 or 1500.50):";
        } else {
            $title = $isIncome ? '🟢 <b>ተጨማሪ ገቢ መመዝገቢያ</b>' : '🔴 <b>ተጨማሪ ወጪ መመዝገቢያ</b>';
            $prompt = "{$title}\n\nእባክዎ መጠኑን በብር ያስገቡ (ምሳሌ፡ 250 ወይም 1500.50):";
        }

        $cancelBtn = [
            [['text' => $locale === 'en' ? '❌ Cancel' : '❌ ሰርዝ', 'callback_data' => 'cf:cancel:0']],
        ];

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($cancelBtn),
        ]);
    }

    /**
     * Handle amount input.
     */
    public function handleAmountEntered(int|string $chatId, string $text, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $parsed = TelegramUi::parseNumber($text);
        if ($parsed === null || $parsed <= 0) {
            $this->telegram->sendMessage($chatId, __('invalid_number'));
            return;
        }

        $amountCents = (int) round($parsed * 100);

        $state = $this->botState->get($chatId);
        $type = $state?->data['type'] ?? 'expense';

        $this->botState->next($chatId, 'await_category', [
            'amount_cents' => $amountCents,
        ]);

        $this->promptCategoryPicker($chatId, $type, $amountCents, $locale);
    }

    /**
     * Present category options.
     */
    protected function promptCategoryPicker(int|string $chatId, string $type, int $amountCents, string $locale): void
    {
        $categories = $type === 'income' ? self::CATEGORIES_INCOME : self::CATEGORIES_EXPENSE;
        $amountF = number_format($amountCents / 100, 2) . ' ETB';

        if ($locale === 'en') {
            $prompt = "💰 Amount: <b>{$amountF}</b>\n\nSelect a category (or type a custom category name):";
        } else {
            $prompt = "💰 መጠን: <b>{$amountF}</b>\n\nምድብ ይምረጡ (ወይም የተለየ ምድብ ስም ለመጻፍ በጽሁፍ ይላኩ):";
        }

        $buttons = [];
        $row = [];
        foreach ($categories as $key => $labels) {
            $label = $labels[$locale] ?? $labels['en'];
            $row[] = ['text' => $label, 'callback_data' => "cf:cat:{$key}"];
            if (count($row) === 2) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $buttons[] = $row;
        }

        $buttons[] = [
            ['text' => $locale === 'en' ? '❌ Cancel' : '❌ ሰርዝ', 'callback_data' => 'cf:cancel:0'],
        ];

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Handle category chosen via callback or custom text.
     */
    public function handleCategoryChosen(
        int|string $chatId,
        string $categoryInput,
        ?User $user,
        ?string $cqId = null,
        ?int $messageId = null
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($cqId !== null) {
            $this->telegram->answerCallbackQuery($cqId);
        }

        $state = $this->botState->get($chatId);
        $type = $state?->data['type'] ?? 'expense';

        // Resolve category key to display label if known, else use raw input
        $categories = $type === 'income' ? self::CATEGORIES_INCOME : self::CATEGORIES_EXPENSE;
        $resolvedCategory = $categories[$categoryInput][$locale]
            ?? ($categories[$categoryInput]['en'] ?? trim($categoryInput));

        if (empty($resolvedCategory)) {
            $resolvedCategory = $type === 'income' ? 'Other Income' : 'Other Expense';
        }

        $this->botState->next($chatId, 'await_method', [
            'category' => $resolvedCategory,
        ]);

        $this->promptMethodPicker($chatId, $resolvedCategory, $locale, $messageId);
    }

    /**
     * Present payment method options.
     */
    protected function promptMethodPicker(int|string $chatId, string $category, string $locale, ?int $messageId = null): void
    {
        if ($locale === 'en') {
            $prompt = "📂 Category: <b>{$category}</b>\n\nSelect payment method:";
        } else {
            $prompt = "📂 ምድብ: <b>{$category}</b>\n\nየክፍያ ዘዴ ይምረጡ:";
        }

        $buttons = [
            [
                ['text' => self::METHODS['cash'][$locale], 'callback_data' => 'cf:meth:cash'],
                ['text' => self::METHODS['bank'][$locale], 'callback_data' => 'cf:meth:bank'],
            ],
            [
                ['text' => self::METHODS['wallet'][$locale], 'callback_data' => 'cf:meth:wallet'],
                ['text' => $locale === 'en' ? '❌ Cancel' : '❌ ሰርዝ', 'callback_data' => 'cf:cancel:0'],
            ],
        ];

        if ($messageId !== null) {
            $this->telegram->editMessageText($chatId, $messageId, $prompt, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
        } else {
            $this->telegram->sendMessage($chatId, $prompt, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
        }
    }

    /**
     * Handle payment method picked.
     */
    public function handleMethodChosen(
        int|string $chatId,
        string $method,
        ?User $user,
        ?string $cqId = null,
        ?int $messageId = null
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($cqId !== null) {
            $this->telegram->answerCallbackQuery($cqId);
        }

        $cleanMethod = in_array($method, ['cash', 'bank', 'wallet'], true) ? $method : 'cash';

        $this->botState->next($chatId, 'await_note', [
            'payment_method' => $cleanMethod,
        ]);

        if ($locale === 'en') {
            $prompt = "📝 Enter an optional note/description (or tap Skip):";
            $skipText = '⏭️ Skip Note';
            $cancelText = '❌ Cancel';
        } else {
            $prompt = "📝 ተጨማሪ ማስታወሻ ወይም ዝርዝር ይጻፉ (ወይም 'ዝለል' የሚለውን ይጫኑ):";
            $skipText = '⏭️ ዝለል (Skip)';
            $cancelText = '❌ ሰርዝ';
        }

        $buttons = [
            [
                ['text' => $skipText, 'callback_data' => 'cf:skip_note:0'],
                ['text' => $cancelText, 'callback_data' => 'cf:cancel:0'],
            ],
        ];

        if ($messageId !== null) {
            $this->telegram->editMessageText($chatId, $messageId, $prompt, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
        } else {
            $this->telegram->sendMessage($chatId, $prompt, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
        }
    }

    /**
     * Finalize and store the Cash Flow entry.
     */
    public function finalize(int|string $chatId, ?string $note, ?User $user, ?string $cqId = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($cqId !== null) {
            $this->telegram->answerCallbackQuery($cqId);
        }

        if ($user === null || empty($user->shop_id)) {
            $this->telegram->sendMessage($chatId, __('unknown_command'));
            return;
        }

        $shop = Shop::find($user->shop_id);
        if ($shop && !$this->licenseEnforcer->canWrite($shop)) {
            $this->licenseBotHandler->sendRenewalBlockNotice($chatId, $user);
            return;
        }

        $state = $this->botState->get($chatId);
        if ($state === null || empty($state->data['amount_cents'])) {
            $this->telegram->sendMessage($chatId, __('action_cancelled'), [
                'reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user),
            ]);
            return;
        }

        $data = $state->data;
        $this->botState->clear($chatId);

        $type = $data['type'] ?? 'expense';
        $amountCents = (int) $data['amount_cents'];
        $category = $data['category'] ?? ($type === 'income' ? 'Other Income' : 'Other Expense');
        $paymentMethod = $data['payment_method'] ?? 'cash';
        $cleanNote = !empty($note) ? trim($note) : null;
        $nowMs = (int) (microtime(true) * 1000);

        $entry = CashFlowEntry::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $user->shop_id,
            'user_id' => $user->id,
            'device_id' => null,
            'type' => $type,
            'amount_cents' => $amountCents,
            'category' => $category,
            'payment_method' => $paymentMethod,
            'note' => $cleanNote,
            'created_at_ms' => $nowMs,
        ]);

        // Record Change so all connected mobile apps pull this transaction
        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $user->shop_id,
            'device_id' => null,
            'entity' => 'cash_flow',
            'entity_id' => $entry->id,
        ]);

        $amountF = number_format($amountCents / 100, 2) . ' ETB';
        $methodLabel = self::METHODS[$paymentMethod][$locale] ?? $paymentMethod;

        if ($locale === 'en') {
            $header = $type === 'income' ? '✅ <b>Income Recorded!</b>' : '✅ <b>Expense Recorded!</b>';
            $typeLabel = $type === 'income' ? '🟢 Income' : '🔴 Expense';
            $msg = "{$header}\n\n"
                . "🏷️ Type: <b>{$typeLabel}</b>\n"
                . "💰 Amount: <b>{$amountF}</b>\n"
                . "📂 Category: <b>{$category}</b>\n"
                . "💳 Method: <b>{$methodLabel}</b>\n"
                . ($cleanNote ? "📝 Note: <i>{$cleanNote}</i>\n" : "")
                . "👤 By: <b>{$user->name}</b>";
        } else {
            $header = $type === 'income' ? '✅ <b>ተጨማሪ ገቢ ተመዝግቧል!</b>' : '✅ <b>ተጨማሪ ወጪ ተመዝግቧል!</b>';
            $typeLabel = $type === 'income' ? '🟢 ገቢ' : '🔴 ወጪ';
            $msg = "{$header}\n\n"
                . "🏷️ ዓይነት: <b>{$typeLabel}</b>\n"
                . "💰 መጠን: <b>{$amountF}</b>\n"
                . "📂 ምድብ: <b>{$category}</b>\n"
                . "💳 አከፋፈል: <b>{$methodLabel}</b>\n"
                . ($cleanNote ? "📝 ማስታወሻ: <i>{$cleanNote}</i>\n" : "")
                . "👤 መዝጋቢ: <b>{$user->name}</b>";
        }

        $buttons = [
            [
                ['text' => $locale === 'en' ? '➕ Record Another' : '➕ ሌላ መዝግብ', 'callback_data' => "cf:add:{$type}"],
                ['text' => $locale === 'en' ? '💸 Cash Flow Menu' : '💸 ገቢና ወጪ ማውጫ', 'callback_data' => 'cf:menu:0'],
            ],
            [
                ['text' => $locale === 'en' ? '🔙 Main Menu' : '🔙 ዋና ማውጫ', 'callback_data' => 'menu:main:0'],
            ],
        ];

        $this->telegram->sendMessage($chatId, $msg, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * List cash flow entries for today or current month.
     */
    public function handleList(
        int|string $chatId,
        string $period,
        ?User $user,
        ?string $cqId = null,
        ?int $messageId = null,
        int $page = 1
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($cqId !== null) {
            $this->telegram->answerCallbackQuery($cqId);
        }

        if ($user === null || empty($user->shop_id)) {
            $this->telegram->sendMessage($chatId, __('unknown_command'));
            return;
        }

        if (!$this->canView($user)) {
            $this->telegram->sendMessage($chatId, __('permission_denied'));
            return;
        }

        $shop = Shop::find($user->shop_id);
        $tz = $shop?->timezone ?: 'Africa/Addis_Ababa';
        $now = Carbon::now($tz);

        if ($period === 'month') {
            $startMs = $now->copy()->startOfMonth()->timestamp * 1000;
            $endMs = $now->copy()->endOfDay()->timestamp * 1000;
            $periodLabel = $locale === 'en' ? 'This Month' : 'የዚህ ወር';
        } else {
            $period = 'today';
            $startMs = $now->copy()->startOfDay()->timestamp * 1000;
            $endMs = $now->copy()->endOfDay()->timestamp * 1000;
            $periodLabel = $locale === 'en' ? 'Today' : 'ዛሬ';
        }

        $query = CashFlowEntry::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->whereNull('deleted_at_ms')
            ->whereBetween('created_at_ms', [$startMs, $endMs])
            ->orderByDesc('created_at_ms');

        $totalCount = $query->count();
        $perPage = 5;
        $totalPages = max(1, (int) ceil($totalCount / $perPage));
        $page = max(1, min($page, $totalPages));

        $entries = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        $summary = $this->getSummary((string) $user->shop_id, $startMs, $endMs);
        $incF = number_format($summary['income_cents'] / 100, 2) . ' ETB';
        $expF = number_format($summary['expense_cents'] / 100, 2) . ' ETB';
        $net = $summary['income_cents'] - $summary['expense_cents'];
        $netSign = $net >= 0 ? '+' : '-';
        $netF = $netSign . number_format(abs($net) / 100, 2) . ' ETB';

        if ($locale === 'en') {
            $text = "📋 <b>Cash Flow Entries — {$periodLabel}</b>\n"
                . "🟢 Income: <b>+{$incF}</b> | 🔴 Expense: <b>-{$expF}</b>\n"
                . "⚖️ Net: <b>{$netF}</b>\n"
                . "Total Entries: <b>{$totalCount}</b> (Page {$page}/{$totalPages})\n\n";
        } else {
            $text = "📋 <b>የገቢና ወጪ መዝገቦች — {$periodLabel}</b>\n"
                . "🟢 ገቢ: <b>+{$incF}</b> | 🔴 ወጪ: <b>-{$expF}</b>\n"
                . "⚖️ የተጣራ: <b>{$netF}</b>\n"
                . "ጠቅላላ መዝገቦች: <b>{$totalCount}</b> (ገጽ {$page}/{$totalPages})\n\n";
        }

        $buttons = [];

        if ($entries->isEmpty()) {
            $emptyMsg = $locale === 'en'
                ? "<i>No entries recorded for this period.</i>"
                : "<i>በዚህ ጊዜ የተመዘገበ ምንም ገቢ ወይም ወጪ የለም።</i>";
            $text .= $emptyMsg;
        } else {
            foreach ($entries as $idx => $e) {
                $icon = $e->type === 'income' ? '🟢' : '🔴';
                $sign = $e->type === 'income' ? '+' : '-';
                $amtF = $sign . number_format($e->amount_cents / 100, 2) . ' ETB';
                $time = Carbon::createFromTimestampMs($e->created_at_ms, $tz)->format('H:i');
                $methodLabel = self::METHODS[$e->payment_method][$locale] ?? $e->payment_method;

                $text .= "{$icon} <b>{$amtF}</b> — {$e->category}\n"
                    . "   🕒 {$time} | {$methodLabel}\n"
                    . ($e->note ? "   📝 <i>{$e->note}</i>\n" : "")
                    . "\n";

                // Delete button for this entry
                $btnLabel = $locale === 'en'
                    ? "🗑️ Delete {$sign}{$amtF}"
                    : "🗑️ ሰርዝ: {$sign}{$amtF}";
                $buttons[] = [
                    ['text' => $btnLabel, 'callback_data' => "cf:del:{$e->id}"],
                ];
            }
        }

        // Pagination
        if ($totalPages > 1) {
            $navRow = [];
            if ($page > 1) {
                $prevPage = $page - 1;
                $navRow[] = ['text' => '⬅️ ' . ($locale === 'en' ? 'Prev' : 'ወደኋላ'), 'callback_data' => "cf:p:{$period}:{$prevPage}"];
            }
            $navRow[] = ['text' => "{$page}/{$totalPages}", 'callback_data' => 'cf:noop:0'];
            if ($page < $totalPages) {
                $nextPage = $page + 1;
                $navRow[] = ['text' => ($locale === 'en' ? 'Next' : 'ቀጣይ') . ' ➡️', 'callback_data' => "cf:p:{$period}:{$nextPage}"];
            }
            $buttons[] = $navRow;
        }

        // Action row
        $buttons[] = [
            ['text' => $locale === 'en' ? '➕ Income' : '➕ ገቢ', 'callback_data' => 'cf:add:income'],
            ['text' => $locale === 'en' ? '➖ Expense' : '➖ ወጪ', 'callback_data' => 'cf:add:expense'],
            ['text' => $locale === 'en' ? '🔙 Menu' : '🔙 ማውጫ', 'callback_data' => 'cf:menu:0'],
        ];

        if ($messageId !== null) {
            $this->telegram->editMessageText($chatId, $messageId, $text, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
        } else {
            $this->telegram->sendMessage($chatId, $text, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
        }
    }

    /**
     * Ask confirmation to delete a Cash Flow entry.
     */
    public function promptDelete(
        string $cqId,
        int|string $chatId,
        int $messageId,
        string $entryId,
        ?User $user
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($cqId);

        if ($user === null || empty($user->shop_id)) {
            return;
        }

        if (!$this->canCreate($user)) {
            $this->telegram->sendMessage($chatId, __('permission_denied'));
            return;
        }

        $entry = CashFlowEntry::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $entryId)
            ->first();

        if ($entry === null || $entry->deleted_at_ms !== null) {
            $this->telegram->sendMessage($chatId, $locale === 'en' ? 'Entry not found or already deleted.' : 'መዝገቡ አልተገኘም ወይም አስቀድሞ ተሰርዟል።');
            return;
        }

        $amtF = number_format($entry->amount_cents / 100, 2) . ' ETB';
        $typeLabel = $entry->type === 'income' ? ($locale === 'en' ? 'Income' : 'ገቢ') : ($locale === 'en' ? 'Expense' : 'ወጪ');

        if ($locale === 'en') {
            $text = "⚠️ <b>Are you sure you want to delete this entry?</b>\n\n"
                . "🏷️ Type: <b>{$typeLabel}</b>\n"
                . "💰 Amount: <b>{$amtF}</b>\n"
                . "📂 Category: <b>{$entry->category}</b>\n"
                . ($entry->note ? "📝 Note: <i>{$entry->note}</i>\n" : "");
            $confirmText = '🗑️ Yes, Delete';
            $cancelText = '❌ Cancel';
        } else {
            $text = "⚠️ <b>ይህን መዝገብ ለመሰረዝ እርግጠኛ ነዎት?</b>\n\n"
                . "🏷️ ዓይነት: <b>{$typeLabel}</b>\n"
                . "💰 መጠን: <b>{$amtF}</b>\n"
                . "📂 ምድብ: <b>{$entry->category}</b>\n"
                . ($entry->note ? "📝 ማስታወሻ: <i>{$entry->note}</i>\n" : "");
            $confirmText = '🗑️ አዎ ሰርዝ';
            $cancelText = '❌ ተመለስ';
        }

        $buttons = [
            [
                ['text' => $confirmText, 'callback_data' => "cf:del_conf:{$entryId}"],
                ['text' => $cancelText, 'callback_data' => 'cf:list:today'],
            ],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Execute soft-deletion of Cash Flow entry.
     */
    public function executeDelete(
        string $cqId,
        int|string $chatId,
        int $messageId,
        string $entryId,
        ?User $user
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($cqId);

        if ($user === null || empty($user->shop_id) || !$this->canCreate($user)) {
            $this->telegram->sendMessage($chatId, __('permission_denied'));
            return;
        }

        $entry = CashFlowEntry::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $entryId)
            ->first();

        if ($entry !== null) {
            $nowMs = (int) (microtime(true) * 1000);
            $entry->deleted_at_ms = $nowMs;
            $entry->updated_at_ms = $nowMs;
            $entry->save();

            // Record Change for sync deletion
            Change::withoutGlobalScope(ShopScope::class)->create([
                'shop_id' => $user->shop_id,
                'device_id' => null,
                'entity' => 'cash_flow',
                'entity_id' => $entry->id,
            ]);
        }

        $msg = $locale === 'en'
            ? '🗑️ Entry deleted successfully.'
            : '🗑️ መዝገቡ በተሳካ ሁኔታ ተሰርዟል።';

        $buttons = [
            [
                ['text' => $locale === 'en' ? '📋 Back to Entries' : '📋 ወደ መዝገቦች', 'callback_data' => 'cf:list:today'],
                ['text' => $locale === 'en' ? '💸 Menu' : '💸 ማውጫ', 'callback_data' => 'cf:menu:0'],
            ],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $msg, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Cancel active cash flow flow.
     */
    public function cancel(int|string $chatId, ?User $user, ?string $cqId = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($cqId !== null) {
            $this->telegram->answerCallbackQuery($cqId);
        }

        $this->botState->clear($chatId);

        $this->telegram->sendMessage($chatId, __('action_cancelled'), [
            'reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user),
        ]);
    }

    /**
     * Compute cash flow summary for a shop in a time window.
     */
    public function getSummary(string $shopId, int $startMs, int $endMs): array
    {
        $entries = CashFlowEntry::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereNull('deleted_at_ms')
            ->whereBetween('created_at_ms', [$startMs, $endMs])
            ->get();

        $incomeCents = 0;
        $expenseCents = 0;
        $incomeCount = 0;
        $expenseCount = 0;
        $byMethod = ['cash' => 0, 'bank' => 0, 'wallet' => 0];

        foreach ($entries as $e) {
            $amt = (int) $e->amount_cents;
            $m = in_array($e->payment_method, ['cash', 'bank', 'wallet'], true) ? $e->payment_method : 'cash';

            if ($e->type === 'income') {
                $incomeCents += $amt;
                $incomeCount++;
                $byMethod[$m] = ($byMethod[$m] ?? 0) + $amt;
            } else {
                $expenseCents += $amt;
                $expenseCount++;
                $byMethod[$m] = ($byMethod[$m] ?? 0) - $amt;
            }
        }

        return [
            'income_cents' => $incomeCents,
            'expense_cents' => $expenseCents,
            'income_count' => $incomeCount,
            'expense_count' => $expenseCount,
            'by_method' => $byMethod,
        ];
    }

    protected function canView(User $user): bool
    {
        return $user->role === 'owner'
            || $this->permissionService->can($user, 'cash_flow.view')
            || $this->permissionService->can($user, 'reports.view')
            || $this->permissionService->can($user, 'sale.create');
    }

    protected function canCreate(User $user): bool
    {
        return $user->role === 'owner'
            || $this->permissionService->can($user, 'cash_flow.create')
            || $this->permissionService->can($user, 'sale.create');
    }
}
