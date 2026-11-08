<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\Change;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\BalanceService;
use App\Services\CustomerService;
use App\Services\PaymentProviderService;
use App\Services\PaymentReportService;
use App\Services\PermissionService;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Carbon\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DubeHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected BotStateService $botState,
        protected BalanceService $balanceService,
        protected CustomerService $customerService,
        protected PaymentProviderService $providerService,
        protected PaymentReportService $reportService,
        protected PermissionService $permissionService
    ) {}

    /**
     * Start Dube menu: show debtors list.
     */
    public function start(int|string $chatId, ?User $user, int $page = 1): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'dube.view')) {
            $this->telegram->sendMessage($chatId, __('permission_denied'), [
                'reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user),
            ]);
            return;
        }

        $shop = Shop::find($user->shop_id);
        if ($shop === null) {
            $this->telegram->sendMessage($chatId, __('shop_not_found'));
            return;
        }

        $dubeReport = $this->reportService->outstandingDube($shop);
        $debtors = $dubeReport['customers'];

        $perPage = 5;
        $totalItems = $debtors->count();
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = max(1, min($page, $totalPages));
        $pagedItems = $debtors->slice(($page - 1) * $perPage, $perPage);

        $totalDebtCents = (int) ($dubeReport['total_outstanding_cents'] ?? 0);
        $overdueCount = $debtors->filter(fn($d) => $d['overdue_cents'] > 0)->count();

        $totalDebtF = number_format($totalDebtCents / 100, 2) . ' ETB';

        $title = $locale === 'en'
            ? "📒 <b>Dube & Debtors Management</b>\n\n"
                . "Total Outstanding: <b>{$totalDebtF}</b>\n"
                . "Overdue Debtors: <b>{$overdueCount}</b>\n\n"
                . "Select a customer to view statement, receive payment, or extend due date:"
            : "📒 <b>የዱቤ እና ባለዕዳዎች አስተዳደር</b>\n\n"
                . "ያልተሰበሰበ ጠቅላላ ዕዳ: <b>{$totalDebtF}</b>\n"
                . "ጊዜያቸው ያለፈባቸው ባለዕዳዎች: <b>{$overdueCount}</b>\n\n"
                . "የሂሳብ መግለጫ ለማየት፣ ክፍያ ለመቀበል ወይም ቀን ለማራዘም ደንበኛ ይምረጡ:";

        $buttons = [];

        // Customer debtor buttons
        foreach ($pagedItems as $d) {
            $marker = $d['overdue_cents'] > 0 ? '🔴 ' : '⏳ ';
            $amt = number_format($d['net_balance_cents'] / 100, 2) . ' ETB';
            $label = "{$marker}{$d['customer_name']} — {$amt}";
            $buttons[] = [
                ['text' => $label, 'callback_data' => "dube:cust:{$d['customer_id']}"],
            ];
        }

        if ($debtors->isEmpty()) {
            $emptyText = $locale === 'en' ? '✨ No customers currently have outstanding debt.' : '✨ በአሁኑ ጊዜ ምንም ያልተከፈለ የዱቤ ዕዳ የለም።';
            $title .= "\n\n<i>{$emptyText}</i>";
        }

        // Pagination row
        if ($totalPages > 1) {
            $navRow = [];
            if ($page > 1) {
                $prev = $page - 1;
                $navRow[] = ['text' => '◀️ ' . ($locale === 'en' ? 'Prev' : 'ወደኋላ'), 'callback_data' => "dube:page:{$prev}"];
            }
            $navRow[] = ['text' => "{$page}/{$totalPages}", 'callback_data' => 'dube:page:' . $page];
            if ($page < $totalPages) {
                $next = $page + 1;
                $navRow[] = ['text' => ($locale === 'en' ? 'Next' : 'ወደፊት') . ' ▶️', 'callback_data' => "dube:page:{$next}"];
            }
            $buttons[] = $navRow;
        }

        // Action buttons
        $actions = [];
        if ($this->permissionService->can($user, 'customers.manage')) {
            $actions[] = ['text' => '➕ ' . ($locale === 'en' ? 'New Customer' : 'አዲስ ደንበኛ'), 'callback_data' => 'dube:add_cust:0'];
        }
        $actions[] = ['text' => '🔍 ' . ($locale === 'en' ? 'Search' : 'ፈልግ'), 'callback_data' => 'dube:search:0'];
        $actions[] = ['text' => '🔄 ' . ($locale === 'en' ? 'Refresh' : 'አድስ'), 'callback_data' => 'dube:page:1'];
        $buttons[] = $actions;

        $this->telegram->sendMessage($chatId, $title, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Show customer card with full statement, debt summary, and action buttons.
     */
    public function showCustomerCard(string $callbackId, int|string $chatId, int $messageId, string $customerId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'dube.view')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $customer = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $customerId)
            ->first();

        if ($customer === null) {
            $this->telegram->sendMessage($chatId, 'Customer not found.');
            return;
        }

        $credit = $this->balanceService->customerCredit($customer);
        $statement = $this->reportService->customerStatement($customer);

        $totalDebt = number_format($credit['total_debt_cents'] / 100, 2) . ' ETB';
        $overdue = number_format($credit['overdue_cents'] / 100, 2) . ' ETB';
        $creditAmt = number_format($credit['overpayment_cents'] / 100, 2) . ' ETB';

        $phoneStr = $customer->phone ? " ({$customer->phone})" : '';

        $statusHeader = $credit['overdue_cents'] > 0
            ? "🔴 <b>OVERDUE DUBE: {$overdue}</b>"
            : ($credit['total_debt_cents'] > 0 ? "⏳ <b>Current Dube</b>" : "🟢 <b>Fully Paid</b>");

        if ($credit['has_overpayment']) {
            $statusHeader .= " | 💳 Credit: <b>{$creditAmt}</b>";
        }

        $text = "👤 <b>{$customer->name}</b>{$phoneStr}\n"
            . "{$statusHeader}\n"
            . "Total Debt: <b>{$totalDebt}</b>\n"
            . ($credit['oldest_due_date'] ? "Oldest Due Date: <b>{$credit['oldest_due_date']}</b>\n" : "")
            . "\n🧾 <b>Open Sales / Transactions:</b>\n";

        $openSales = $statement['open_sales'] ?? collect();
        if ($openSales->isEmpty()) {
            $text .= $locale === 'en' ? "  (No unpaid sales)\n" : "  (ምንም ያልተከፈለ ሽያጭ የለም)\n";
        } else {
            foreach ($openSales->take(5) as $idx => $s) {
                $num = $idx + 1;
                $rem = number_format($s['remaining_cents'] / 100, 2) . ' ETB';
                $due = $s['sale']->due_date ?? 'No date';
                $overdueTag = !empty($s['is_overdue']) ? ' [🔴 Overdue]' : '';
                $text .= "  {$num}. {$rem} (Due: {$due}){$overdueTag}\n";
            }
        }

        $recentPayments = $statement['recent_payments'] ?? collect();
        if ($recentPayments->isNotEmpty()) {
            $text .= "\n💳 <b>Recent Payments:</b>\n";
            foreach ($recentPayments->take(3) as $p) {
                $pAmt = number_format($p->amount_cents / 100, 2) . ' ETB';
                $pDate = Carbon::createFromTimestampMs($p->received_at_ms)->format('M j, Y');
                $methodStr = ucfirst($p->method);
                $text .= "  • {$pAmt} via {$methodStr} ({$pDate})\n";
            }
        }

        $buttons = [];

        // Receive payment (payment.collect)
        if ($credit['total_debt_cents'] > 0 && $this->permissionService->can($user, 'payment.collect')) {
            $buttons[] = [
                ['text' => '💵 ' . ($locale === 'en' ? 'Receive Payment' : 'ክፍያ ተቀበል'), 'callback_data' => "dube:pay_start:{$customerId}"],
            ];
        }

        // Extend due date (sale.dube)
        if ($credit['total_debt_cents'] > 0 && $this->permissionService->can($user, 'sale.dube')) {
            $buttons[] = [
                ['text' => '📅 ' . ($locale === 'en' ? 'Extend Due Date' : 'የመክፈያ ቀን አራዝም'), 'callback_data' => "dube:ext_menu:{$customerId}"],
            ];
        }

        // Reminder text
        if ($credit['total_debt_cents'] > 0) {
            $buttons[] = [
                ['text' => '📤 ' . ($locale === 'en' ? 'Reminder Text' : 'የማስታወሻ መልዕክት'), 'callback_data' => "dube:remind:{$customerId}"],
            ];
        }

        // Edit customer (customers.manage)
        if ($this->permissionService->can($user, 'customers.manage')) {
            $buttons[] = [
                ['text' => '✏️ ' . ($locale === 'en' ? 'Edit Customer' : 'ደንበኛ አስተካክል'), 'callback_data' => "dube:edit:{$customerId}"],
            ];
        }

        // Back button
        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Debtors' : 'ወደ ዱቤ ዝርዝር'), 'callback_data' => 'dube:page:1'],
        ];

        if ($messageId > 0) {
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
     * Send copyable reminder text.
     */
    public function sendReminderText(string $callbackId, int|string $chatId, string $customerId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'dube.view')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $customer = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $customerId)
            ->first();

        if ($customer === null) {
            return;
        }

        $shop = Shop::find($user->shop_id);
        $shopName = $shop?->name ?? 'Inventory';

        $credit = $this->balanceService->customerCredit($customer);
        if ($credit['total_debt_cents'] <= 0) {
            $this->telegram->sendMessage($chatId, $locale === 'en' ? 'Customer has no outstanding debt.' : 'ደንበኛው ምንም ያልተከፈለ ዕዳ የለበትም።');
            return;
        }

        $amountFormatted = number_format($credit['total_debt_cents'] / 100, 2) . ' ETB';
        $dueDateFormatted = $credit['oldest_due_date'] ?? 'soon';

        if ($locale === 'en') {
            $reminder = "Dear {$customer->name}, this is a gentle reminder from {$shopName}. "
                . "You have an outstanding balance of {$amountFormatted} due on {$dueDateFormatted}. "
                . "Please arrange payment at your earliest convenience. Thank you!";
            $header = "📤 <b>Copyable Reminder Text</b>\n\nTap the text below to copy and forward to the customer:";
        } else {
            $reminder = "ሰላም {$customer->name}፣ ከ{$shopName} የተላከ ማስታወሻ። "
                . "ያለብዎት የዱቤ ሂሳብ {$amountFormatted} ሲሆን የመክፈያ ቀኑ {$dueDateFormatted} ነው። "
                . "እባክዎ በተጠቀሰው ቀን እንዲከፍሉ በትህትና እናሳስባለን። እናመሰግናለን!";
            $header = "📤 <b>የሚያስተላልፉት የማስታወሻ መልዕክት</b>\n\nየሚከተለውን ጽሁፍ ኮፒ በማድረግ ለደንበኛው በቴሌግራም ወይም በኤስኤምኤስ ይላኩ:";
        }

        $copyableBox = "<code>" . htmlspecialchars($reminder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</code>";

        $buttons = [
            [
                ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Customer' : 'ወደ ደንበኛው ተመለስ'), 'callback_data' => "dube:cust:{$customerId}"],
            ],
        ];

        $this->telegram->sendMessage($chatId, "{$header}\n\n{$copyableBox}", [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Repayment: Start repayment flow.
     */
    public function startRepayment(string $callbackId, int|string $chatId, int $messageId, string $customerId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'payment.collect')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $customer = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $customerId)
            ->first();

        if ($customer === null) {
            return;
        }

        $credit = $this->balanceService->customerCredit($customer);
        $debtCents = $credit['total_debt_cents'];

        if ($debtCents <= 0) {
            $this->telegram->sendMessage($chatId, $locale === 'en' ? 'Customer has no outstanding debt.' : 'ደንበኛው ምንም ዕዳ የለበትም።');
            return;
        }

        $debtFormatted = number_format($debtCents / 100, 2) . ' ETB';

        $text = $locale === 'en'
            ? "💵 <b>Receive Repayment — {$customer->name}</b>\n"
                . "Outstanding Balance: <b>{$debtFormatted}</b>\n\n"
                . "Select repayment amount (repayments apply to oldest open sales first):"
            : "💵 <b>የዱቤ ክፍያ መቀበል — {$customer->name}</b>\n"
                . "ያልተከፈለ ዕዳ: <b>{$debtFormatted}</b>\n\n"
                . "የክፍያ መጠን ይምረጡ (ክፍያው በቀዳሚነት የቆየውን ዱቤ ይሸፍናል):";

        $buttons = [
            [
                ['text' => "💰 " . ($locale === 'en' ? "Full Balance ({$debtFormatted})" : "ሙሉ ዕዳ ({$debtFormatted})"), 'callback_data' => "dube:pay_amt:{$customerId}:full"],
            ],
            [
                ['text' => "✏️ " . ($locale === 'en' ? "Custom Amount" : "የተወሰነ መጠን"), 'callback_data' => "dube:pay_amt:{$customerId}:custom"],
            ],
            [
                ['text' => "❌ " . ($locale === 'en' ? "Cancel" : "ሰርዝ"), 'callback_data' => "dube:cust:{$customerId}"],
            ],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Repayment: Prompt custom repayment amount.
     */
    public function promptCustomRepaymentAmount(string $callbackId, int|string $chatId, int $messageId, string $customerId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($callbackId);

        $customer = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user?->shop_id)
            ->where('id', $customerId)
            ->first();

        if ($customer === null) {
            return;
        }

        $credit = $this->balanceService->customerCredit($customer);
        $debtFormatted = number_format($credit['total_debt_cents'] / 100, 2) . ' ETB';

        $this->botState->start($chatId, 'dube_repay', 'await_amount', [
            'customer_id' => $customerId,
            'max_debt_cents' => $credit['total_debt_cents'],
        ]);

        $prompt = $locale === 'en'
            ? "✏️ <b>Enter Repayment Amount in ETB</b>\nOutstanding Debt: <b>{$debtFormatted}</b>\n(e.g. 250 or 500.50):"
            : "✏️ <b>የክፍያ መጠን በብር ያስገቡ</b>\nያልተከፈለ ዕዳ: <b>{$debtFormatted}</b>\n(ለምሳሌ 250 ወይም 500.50):";

        $buttons = [
            [['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => "dube:cust:{$customerId}"]],
        ];

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Repayment: Handle custom amount entered, guarding against overpayment.
     */
    public function handleCustomRepaymentAmountEntered(int|string $chatId, string $text, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $customerId = $data['customer_id'] ?? null;
        $maxCents = (int) ($data['max_debt_cents'] ?? 0);

        $num = TelegramUi::parseNumber($text);
        if ($num === null || $num <= 0) {
            $this->telegram->sendMessage($chatId, __('invalid_number'));
            return;
        }

        $amountCents = (int) round($num * 100);
        if ($amountCents > $maxCents) {
            $maxFormatted = number_format($maxCents / 100, 2) . ' ETB';
            $this->telegram->sendMessage(
                $chatId,
                $locale === 'en'
                    ? "⚠️ Amount cannot exceed total debt (<b>{$maxFormatted}</b>). Overpayments are not allowed."
                    : "⚠️ የክፍያ መጠኑ ከጠቅላላ ዕዳው (<b>{$maxFormatted}</b>) መብለጥ አይችልም። ተጨማሪ ክፍያ አይፈቀድም።"
            );
            return;
        }

        $this->botState->clear($chatId);
        $this->promptRepaymentMethod($chatId, $customerId, $amountCents, $user);
    }

    /**
     * Repayment: Prompt payment method after amount is chosen.
     */
    public function promptRepaymentMethod(
        int|string $chatId,
        string $customerId,
        int $amountCents,
        ?User $user,
        ?int $messageId = null
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $customer = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user?->shop_id)
            ->where('id', $customerId)
            ->first();

        if ($customer === null) {
            return;
        }

        $shop = Shop::find($user?->shop_id);
        $enabledMethods = $shop?->payment_methods_enabled ?? ['cash', 'bank', 'wallet', 'dube'];

        $amtFormatted = number_format($amountCents / 100, 2) . ' ETB';

        $text = $locale === 'en'
            ? "💵 <b>Receive Repayment — {$customer->name}</b>\n"
                . "Amount to receive: <b>{$amtFormatted}</b>\n\n"
                . "Choose payment method:"
            : "💵 <b>የዱቤ ክፍያ መቀበል — {$customer->name}</b>\n"
                . "የሚቀበሉት ገንዘብ: <b>{$amtFormatted}</b>\n\n"
                . "የክፍያ መንገድ ይምረጡ:";

        $methodButtons = [];
        $row = [];
        if (in_array('cash', $enabledMethods, true)) {
            $row[] = ['text' => '💵 ' . ($locale === 'en' ? 'Cash' : 'ጥሬ ገንዘብ'), 'callback_data' => "dube:pay_m:{$customerId}:cash:{$amountCents}"];
        }
        if (in_array('bank', $enabledMethods, true)) {
            $row[] = ['text' => '🏦 ' . ($locale === 'en' ? 'Bank' : 'ባንክ'), 'callback_data' => "dube:pay_m:{$customerId}:bank:{$amountCents}"];
        }
        if (in_array('wallet', $enabledMethods, true)) {
            $row[] = ['text' => '📱 ' . ($locale === 'en' ? 'Wallet' : 'ዋሌት'), 'callback_data' => "dube:pay_m:{$customerId}:wallet:{$amountCents}"];
        }
        if (!empty($row)) {
            $methodButtons[] = $row;
        }

        $methodButtons[] = [
            ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => "dube:cust:{$customerId}"],
        ];

        if ($messageId !== null && $messageId > 0) {
            $this->telegram->editMessageText($chatId, $messageId, $text, [
                'reply_markup' => TelegramUi::inlineKeyboard($methodButtons),
            ]);
        } else {
            $this->telegram->sendMessage($chatId, $text, [
                'reply_markup' => TelegramUi::inlineKeyboard($methodButtons),
            ]);
        }
    }

    /**
     * Repayment: Prompt provider selection for Bank or Wallet.
     */
    public function promptRepaymentProvider(
        string $callbackId,
        int|string $chatId,
        int $messageId,
        string $customerId,
        string $kind,
        int $amountCents,
        int $page,
        ?User $user
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($callbackId);

        $shopId = (string) $user?->shop_id;
        $all = $this->providerService->listProviders($shopId, $kind, $locale);

        $perPage = 6;
        $totalPages = max(1, (int) ceil($all->count() / $perPage));
        $page = max(1, min($page, $totalPages));
        $items = $all->slice(($page - 1) * $perPage, $perPage);

        $kindTitle = $kind === 'bank'
            ? ($locale === 'en' ? 'Bank' : 'ባንክ')
            : ($locale === 'en' ? 'Wallet' : 'ዋሌት');

        $amtF = number_format($amountCents / 100, 2) . ' ETB';

        $text = $locale === 'en'
            ? "🏦 <b>Select {$kindTitle} Provider</b>\nRepayment: <b>{$amtF}</b>\n\nChoose provider:"
            : "🏦 <b>የ{$kindTitle} ተቋም ይምረጡ</b>\nክፍያ: <b>{$amtF}</b>\n\nተቋም ይምረጡ:";

        $buttons = [];
        $row = [];
        foreach ($items as $item) {
            $label = ($item['favorite'] ? '⭐ ' : '') . $item['display_name'];
            $row[] = ['text' => $label, 'callback_data' => "dube:rpay_prov:{$customerId}:{$kind}:{$amountCents}:{$item['provider_key']}"];
            if (count($row) === 2) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $buttons[] = $row;
        }

        // Pagination
        if ($totalPages > 1) {
            $navRow = [];
            if ($page > 1) {
                $prev = $page - 1;
                $navRow[] = ['text' => '◀️ ' . ($locale === 'en' ? 'Prev' : 'ወደኋላ'), 'callback_data' => "dube:rpay_page:{$customerId}:{$kind}:{$amountCents}:{$prev}"];
            }
            if ($page < $totalPages) {
                $next = $page + 1;
                $navRow[] = ['text' => ($locale === 'en' ? 'Next' : 'ወደፊት') . ' ▶️', 'callback_data' => "dube:rpay_page:{$customerId}:{$kind}:{$amountCents}:{$next}"];
            }
            if (!empty($navRow)) {
                $buttons[] = $navRow;
            }
        }

        $buttons[] = [
            ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => "dube:cust:{$customerId}"],
        ];

        if ($messageId > 0) {
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
     * Repayment: Provider selected, prompt reference if enabled or skip.
     */
    public function handleRepaymentProviderPicked(
        string $callbackId,
        int|string $chatId,
        int $messageId,
        string $customerId,
        string $kind,
        int $amountCents,
        string $providerKey,
        ?User $user
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($callbackId);

        $shop = Shop::find($user?->shop_id);
        $requireRef = (bool) ($shop?->require_payment_reference ?? false);

        $this->botState->start($chatId, 'dube_repay_ref', 'await_ref', [
            'customer_id' => $customerId,
            'kind' => $kind,
            'amount_cents' => $amountCents,
            'provider_key' => $providerKey,
            'require_ref' => $requireRef,
        ]);

        $provName = $this->providerService->listProviders((string) $user?->shop_id, $kind, $locale)
            ->firstWhere('provider_key', $providerKey)['display_name'] ?? $providerKey;

        if ($requireRef) {
            $prompt = $locale === 'en'
                ? "📝 <b>Transaction Reference Required</b>\nProvider: <b>{$provName}</b>\n\nPlease enter the transaction reference / receipt number:"
                : "📝 <b>የግብይት ማጣቀሻ ቁጥር ያስፈልጋል</b>\nተቋም: <b>{$provName}</b>\n\nእባክዎ የማጣቀሻ ቁጥር (Reference / Txn ID) ያስገቡ:";

            $buttons = [
                [['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => "dube:cust:{$customerId}"]],
            ];
        } else {
            $prompt = $locale === 'en'
                ? "📝 <b>Transaction Reference (Optional)</b>\nProvider: <b>{$provName}</b>\n\nEnter reference number or tap Skip:"
                : "📝 <b>የግብይት ማጣቀሻ ቁጥር (አስገዳጅ ያልሆነ)</b>\nተቋም: <b>{$provName}</b>\n\nየማጣቀሻ ቁጥር ያስገቡ ወይም ይለፉ:";

            $buttons = [
                [['text' => '⏭ ' . ($locale === 'en' ? 'Skip Reference' : 'ይለፉ'), 'callback_data' => "dube:rpay_skip_ref:{$customerId}:{$kind}:{$amountCents}:{$providerKey}"]],
                [['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => "dube:cust:{$customerId}"]],
            ];
        }

        if ($messageId > 0) {
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
     * Repayment: Handle entered reference.
     */
    public function handleRepaymentReferenceEntered(int|string $chatId, string $text, ?User $user): void
    {
        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $customerId = $data['customer_id'] ?? '';
        $kind = $data['kind'] ?? 'bank';
        $amountCents = (int) ($data['amount_cents'] ?? 0);
        $providerKey = $data['provider_key'] ?? '';
        $ref = trim($text);

        $this->botState->clear($chatId);
        $this->executeRepayment($chatId, $customerId, $amountCents, $kind, $providerKey, $ref, $user, 0);
    }

    /**
     * Repayment: Complete payment recording via BalanceService::recordOnlinePayment.
     */
    public function executeRepayment(
        int|string $chatId,
        string $customerId,
        int $amountCents,
        string $method,
        ?string $providerKey,
        ?string $reference,
        ?User $user,
        ?int $messageId = null
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'payment.collect')) {
            $this->telegram->sendMessage($chatId, __('permission_denied'));
            return;
        }

        $shopId = (string) $user->shop_id;
        $customer = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('id', $customerId)
            ->first();

        if ($customer === null) {
            $this->telegram->sendMessage($chatId, 'Customer not found.');
            return;
        }

        try {
            $payment = $this->balanceService->recordOnlinePayment(
                shopId: $shopId,
                userId: $user->id,
                method: $method,
                amountCents: $amountCents,
                saleId: null,
                customerId: $customerId,
                providerKey: $providerKey,
                reference: $reference
            );
        } catch (InvalidArgumentException $e) {
            $this->telegram->sendMessage(
                $chatId,
                "⚠️ " . ($locale === 'en' ? "Payment failed: " : "ክፍያው አልተሳካም: ") . $e->getMessage(),
                ['reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user)]
            );
            return;
        }

        $creditAfter = $this->balanceService->customerCredit($customer);
        $newBalance = number_format($creditAfter['net_balance_cents'] / 100, 2) . ' ETB';
        $receivedFormatted = number_format($amountCents / 100, 2) . ' ETB';

        $providerName = $this->providerService->listProviders($shopId, $method === 'bank' ? 'bank' : 'wallet', $locale)
            ->firstWhere('provider_key', $providerKey)['display_name'] ?? ($providerKey ?? ucfirst($method));

        $text = $locale === 'en'
            ? "✅ <b>Repayment Received!</b>\n\n"
                . "Customer: <b>{$customer->name}</b>\n"
                . "Amount: <b>{$receivedFormatted}</b>\n"
                . "Method: <b>" . ucfirst($method) . " ({$providerName})</b>\n"
                . ($reference ? "Reference: <code>{$reference}</code>\n" : "")
                . "New Balance: <b>{$newBalance}</b>\n\n"
                . "<i>Allocated to oldest dube sales first.</i>"
            : "✅ <b>ክፍያው በተሳካ ሁኔታ ተመዝግቧል!</b>\n\n"
                . "ደንበኛ: <b>{$customer->name}</b>\n"
                . "የተከፈለው መጠን: <b>{$receivedFormatted}</b>\n"
                . "የክፍያ መንገድ: <b>{$providerName}</b>\n"
                . ($reference ? "ማጣቀሻ ቁጥር: <code>{$reference}</code>\n" : "")
                . "የቀረ ዕዳ: <b>{$newBalance}</b>\n\n"
                . "<i>ክፍያው በቀዳሚነት የቆዩትን የዱቤ ሽያጮች ሸፍኗል።</i>";

        $buttons = [
            [
                ['text' => '👤 ' . ($locale === 'en' ? 'View Customer' : 'የደንበኛ መረጃ'), 'callback_data' => "dube:cust:{$customerId}"],
                ['text' => '📒 ' . ($locale === 'en' ? 'Debtors List' : 'ወደ ዱቤ ዝርዝር'), 'callback_data' => 'dube:page:1'],
            ],
        ];

        if ($messageId !== null && $messageId > 0) {
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
     * Extend due date: show open sales of this customer.
     */
    public function showExtendSaleList(string $callbackId, int|string $chatId, int $messageId, string $customerId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($callbackId);

        $customer = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user?->shop_id)
            ->where('id', $customerId)
            ->first();

        if ($customer === null) {
            return;
        }

        $statement = $this->reportService->customerStatement($customer);
        $openSales = $statement['open_sales'] ?? collect();

        if ($openSales->isEmpty()) {
            $this->telegram->sendMessage($chatId, $locale === 'en' ? 'No open sales found for this customer.' : 'ምንም ያልተከፈለ ሽያጭ አልተገኘም።');
            return;
        }

        $text = $locale === 'en'
            ? "📅 <b>Extend Due Date — Select Sale</b>\n\nCustomer: <b>{$customer->name}</b>\nSelect the sale to extend:"
            : "📅 <b>የመክፈያ ቀን ማራዘሚያ — ሽያጭ ይምረጡ</b>\n\nደንበኛ: <b>{$customer->name}</b>\nየሚራዘመውን ሽያጭ ይምረጡ:";

        $buttons = [];
        foreach ($openSales as $s) {
            $rem = number_format($s['remaining_cents'] / 100, 2) . ' ETB';
            $due = $s['sale']->due_date ?? 'No date';
            $buttons[] = [
                ['text' => "{$rem} (Due: {$due})", 'callback_data' => "dube:ext_opt:{$s['sale']->id}:{$customerId}"],
            ];
        }

        $buttons[] = [
            ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => "dube:cust:{$customerId}"],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Extend due date: show extension options (+7, +14, +30, custom).
     */
    public function showExtendOptions(string $callbackId, int|string $chatId, int $messageId, string $saleId, string $customerId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($callbackId);

        $sale = Sale::withoutGlobalScope(ShopScope::class)->find($saleId);
        if ($sale === null) {
            return;
        }

        $currentDue = $sale->due_date ?? 'none';

        $text = $locale === 'en'
            ? "📅 <b>Extend Sale Due Date</b>\nCurrent due date: <b>{$currentDue}</b>\n\nChoose new due date:"
            : "📅 <b>የሽያጭ መክፈያ ቀን ማራዘሚያ</b>\nየአሁኑ መክፈያ ቀን: <b>{$currentDue}</b>\n\nአዲሱን የመክፈያ ቀን ይምረጡ:";

        $buttons = [
            [
                ['text' => '+7 ' . ($locale === 'en' ? 'Days' : 'ቀናት'), 'callback_data' => "dube:ext_do:{$saleId}:7:{$customerId}"],
                ['text' => '+14 ' . ($locale === 'en' ? 'Days' : 'ቀናት'), 'callback_data' => "dube:ext_do:{$saleId}:14:{$customerId}"],
                ['text' => '+30 ' . ($locale === 'en' ? 'Days' : 'ቀናት'), 'callback_data' => "dube:ext_do:{$saleId}:30:{$customerId}"],
            ],
            [
                ['text' => '📅 ' . ($locale === 'en' ? 'Custom Date (YYYY-MM-DD)' : 'የተለየ ቀን አስገባ'), 'callback_data' => "dube:ext_custom:{$saleId}:{$customerId}"],
            ],
            [
                ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => "dube:cust:{$customerId}"],
            ],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Prompt custom due date for extension.
     */
    public function promptCustomDueDate(string $callbackId, int|string $chatId, int $messageId, string $saleId, string $customerId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($callbackId);

        $this->botState->start($chatId, 'dube_ext_custom', 'await_date', [
            'sale_id' => $saleId,
            'customer_id' => $customerId,
        ]);

        $prompt = $locale === 'en'
            ? "📅 <b>Enter New Due Date</b>\nPlease enter date in format <code>YYYY-MM-DD</code> (e.g. 2026-11-20):"
            : "📅 <b>አዲስ የመክፈያ ቀን ያስገቡ</b>\nእባክዎ ቀኑን በ <code>YYYY-MM-DD</code> ቅርጸት ያስገቡ (ለምሳሌ 2026-11-20):";

        $buttons = [
            [['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => "dube:cust:{$customerId}"]],
        ];

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Handle custom due date entered.
     */
    public function handleCustomDueDateEntered(int|string $chatId, string $text, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $saleId = $data['sale_id'] ?? null;
        $customerId = $data['customer_id'] ?? null;

        $cleanDate = trim($text);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cleanDate)) {
            $this->telegram->sendMessage(
                $chatId,
                $locale === 'en'
                    ? "⚠️ Invalid date format. Please use YYYY-MM-DD (e.g. 2026-11-20):"
                    : "⚠️ የተሳሳተ የቀን ቅርጸት። እባክዎ በ YYYY-MM-DD ቅርጸት ያስገቡ (ለምሳሌ 2026-11-20):"
            );
            return;
        }

        $this->botState->clear($chatId);
        $this->applyExtendDueDate('', $chatId, 0, $saleId, $cleanDate, $customerId, $user);
    }

    /**
     * Apply extended due date to a sale.
     */
    public function applyExtendDueDate(
        string $callbackId,
        int|string $chatId,
        int $messageId,
        string $saleId,
        string $newDueDate,
        string $customerId,
        ?User $user
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'sale.dube')) {
            if ($callbackId !== '') {
                $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            } else {
                $this->telegram->sendMessage($chatId, __('permission_denied'));
            }
            return;
        }

        if ($callbackId !== '') {
            $this->telegram->answerCallbackQuery($callbackId);
        }

        $sale = Sale::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $saleId)
            ->first();

        if ($sale === null) {
            return;
        }

        $nowMs = (int) (microtime(true) * 1000);
        $sale->due_date = $newDueDate;
        $sale->updated_at_ms = $nowMs;
        $sale->save();

        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $user->shop_id,
            'device_id' => null,
            'entity' => 'sale',
            'entity_id' => $sale->id,
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "✅ " . ($locale === 'en' ? "Due date extended to <b>{$newDueDate}</b>." : "የመክፈያ ቀን ወደ <b>{$newDueDate}</b> ተራዝሟል።")
        );

        $this->showCustomerCard('', $chatId, 0, $customerId, $user);
    }

    /**
     * Start adding a new customer.
     */
    public function startAddCustomer(string $callbackId, int|string $chatId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'customers.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $this->botState->start($chatId, 'dube_add_cust', 'await_name', [
            'shop_id' => $user->shop_id,
        ]);

        $prompt = $locale === 'en'
            ? "➕ <b>Add New Customer</b>\nPlease enter customer name (e.g. Abebe Bikila):"
            : "➕ <b>አዲስ ደንበኛ መመዝገብ</b>\nእባክዎ የደንበኛውን ሙሉ ስም ያስገቡ (ለምሳሌ አበበ ቢቂላ):";

        $buttons = [
            [
                ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'dube:page:1'],
            ],
        ];

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Handle customer name entry when adding a customer.
     */
    public function handleCustomerNameEntered(int|string $chatId, string $name, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $cleanName = trim($name);
        if (mb_strlen($cleanName) < 2) {
            $this->telegram->sendMessage(
                $chatId,
                $locale === 'en' ? '⚠️ Name is too short. Please enter a valid name:' : '⚠️ ስሙ በጣም አጭር ነው። እባክዎ ትክክለኛ ስም ያስገቡ:'
            );
            return;
        }

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $data['name'] = $cleanName;
        $this->botState->next($chatId, 'await_phone', $data);

        $prompt = $locale === 'en'
            ? "📱 <b>Customer Phone (Optional)</b>\nEnter Ethiopian phone number (e.g. 0911234567 or 911234567) or tap Skip:"
            : "📱 <b>የደንበኛ ስልክ ቁጥር (አስገዳጅ ያልሆነ)</b>\nየኢትዮጵያ ስልክ ቁጥር ያስገቡ (ለምሳሌ 0911234567 ወይም 911234567) ወይም ይለፉ:";

        $buttons = [
            [
                ['text' => '⏭ ' . ($locale === 'en' ? 'Skip Phone' : 'ስልክ የለም / ይለፉ'), 'callback_data' => 'dube:cust_skip_phone:0'],
                ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'dube:page:1'],
            ],
        ];

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Handle customer phone entry when adding a customer.
     */
    public function handleCustomerPhoneEntered(int|string $chatId, ?string $phone, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $name = $data['name'] ?? 'Customer';
        $shopId = (string) ($user?->shop_id ?? $data['shop_id'] ?? '');

        $normalizedPhone = null;
        if (!empty($phone) && $phone !== '-') {
            $normalizedPhone = CustomerService::normalizePhone($phone);
        }

        $id = (string) Str::uuid7();
        $result = $this->customerService->upsertCustomer(
            shopId: $shopId,
            id: $id,
            name: $name,
            phone: $normalizedPhone
        );

        $customer = $result['customer'];
        $this->botState->clear($chatId);

        $msg = $locale === 'en'
            ? "✅ Customer <b>{$customer->name}</b> saved!" . ($customer->phone ? " ({$customer->phone})" : "")
            : "✅ ደንበኛ <b>{$customer->name}</b> በተሳካ ሁኔታ ተመዝግቧል!" . ($customer->phone ? " ({$customer->phone})" : "");

        if ($result['duplicate_phone']) {
            $msg .= "\n⚠️ " . ($locale === 'en' ? 'Warning: Another customer has this phone number.' : 'ማስታወሻ: ይህ ስልክ ቁጥር ለሌላ ደንበኛም ተመዝግቧል።');
        }

        $this->telegram->sendMessage($chatId, $msg);

        // If this customer was added during sale flow, return to sale flow
        if (!empty($data['return_to_sale'])) {
            $saleHandler = app(SaleHandler::class);
            $saleHandler->handleDubeCustomerPicked($chatId, $customer->id, $user);
            return;
        }

        $this->start($chatId, $user);
    }

    /**
     * Start editing an existing customer.
     */
    public function startEditCustomer(string $callbackId, int|string $chatId, int $messageId, string $customerId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || !$this->permissionService->can($user, 'customers.manage')) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $customer = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $customerId)
            ->first();

        if ($customer === null) {
            return;
        }

        $this->botState->start($chatId, 'dube_edit_cust', 'await_name', [
            'shop_id' => $user->shop_id,
            'customer_id' => $customerId,
            'current_name' => $customer->name,
            'current_phone' => $customer->phone,
        ]);

        $prompt = $locale === 'en'
            ? "✏️ <b>Edit Customer: {$customer->name}</b>\n\nEnter new customer name (or send <code>-</code> to keep current name):"
            : "✏️ <b>ደንበኛ ማስተካከል: {$customer->name}</b>\n\nአዲስ ስም ያስገቡ (ወይም የአሁኑን ለማቆየት <code>-</code> ይላኩ):";

        $buttons = [
            [['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => "dube:cust:{$customerId}"]],
        ];

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Handle edited customer name.
     */
    public function handleEditCustomerNameEntered(int|string $chatId, string $name, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $trimmed = trim($name);
        $finalName = ($trimmed === '-' || $trimmed === '') ? ($data['current_name'] ?? 'Customer') : $trimmed;
        $data['name'] = $finalName;

        $this->botState->next($chatId, 'await_phone', $data);

        $currentPhone = $data['current_phone'] ?? 'none';
        $prompt = $locale === 'en'
            ? "📱 <b>Customer Phone</b>\nCurrent: <b>{$currentPhone}</b>\n\nEnter new phone number (or send <code>-</code> to keep):"
            : "📱 <b>የደንበኛ ስልክ ቁጥር</b>\nየአሁኑ: <b>{$currentPhone}</b>\n\nአዲስ ስልክ ያስገቡ (ወይም ለማቆየት <code>-</code> ይላኩ):";

        $buttons = [
            [['text' => '⏭ ' . ($locale === 'en' ? 'Keep Current' : 'ያለውን አቆይ'), 'callback_data' => 'dube:cust_skip_phone:0']],
            [['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'dube:page:1']],
        ];

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Handle edited customer phone.
     */
    public function handleEditCustomerPhoneEntered(int|string $chatId, ?string $phone, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $customerId = $data['customer_id'] ?? null;
        $name = $data['name'] ?? ($data['current_name'] ?? 'Customer');
        $shopId = (string) ($user?->shop_id ?? $data['shop_id'] ?? '');

        $trimmed = $phone !== null ? trim($phone) : '';
        $finalPhone = null;
        if ($trimmed === '-' || $trimmed === '') {
            $finalPhone = $data['current_phone'] ?? null;
        } else {
            $finalPhone = CustomerService::normalizePhone($trimmed);
        }

        $result = $this->customerService->upsertCustomer(
            shopId: $shopId,
            id: $customerId,
            name: $name,
            phone: $finalPhone
        );

        $customer = $result['customer'];
        $this->botState->clear($chatId);

        $msg = $locale === 'en'
            ? "✅ Customer <b>{$customer->name}</b> updated!" . ($customer->phone ? " ({$customer->phone})" : "")
            : "✅ ደንበኛ <b>{$customer->name}</b> ተስተካክሏል!" . ($customer->phone ? " ({$customer->phone})" : "");

        if ($result['duplicate_phone']) {
            $msg .= "\n⚠️ " . ($locale === 'en' ? 'Warning: Another customer has this phone number.' : 'ማስታወሻ: ይህ ስልክ ቁጥር ለሌላ ደንበኛም ተመዝግቧል።');
        }

        $this->telegram->sendMessage($chatId, $msg);
        $this->showCustomerCard('', $chatId, 0, $customer->id, $user);
    }

    /**
     * Start search customer.
     */
    public function startSearchCustomer(string $callbackId, int|string $chatId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($callbackId);

        $this->botState->start($chatId, 'dube_search', 'await_query', [
            'shop_id' => $user?->shop_id,
        ]);

        $prompt = $locale === 'en'
            ? "🔍 <b>Search Customer</b>\nEnter name or phone number:"
            : "🔍 <b>ደንበኛ መፈለግ</b>\nየደንበኛውን ስም ወይም ስልክ ቁጥር ያስገቡ:";

        $buttons = [
            [['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'dube:page:1']],
        ];

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Handle search query entered.
     */
    public function handleCustomerSearch(int|string $chatId, string $query, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->botState->clear($chatId);

        $clean = trim($query);
        $shopId = (string) $user?->shop_id;

        $results = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereNull('deleted_at_ms')
            ->where(function ($q) use ($clean) {
                $q->where('name', 'LIKE', "%{$clean}%")
                    ->orWhere('phone', 'LIKE', "%{$clean}%");
            })
            ->limit(10)
            ->get();

        if ($results->isEmpty()) {
            $msg = $locale === 'en'
                ? "🔍 No customers found matching <b>\"{$clean}\"</b>."
                : "🔍 ከ <b>\"{$clean}\"</b> ጋር የሚዛመድ ደንበኛ አልተገኘም።";

            $buttons = [
                [
                    ['text' => '➕ ' . ($locale === 'en' ? 'Add as New Customer' : 'አዲስ ደንበኛ መዝግብ'), 'callback_data' => 'dube:add_cust:0'],
                    ['text' => '◀️ ' . ($locale === 'en' ? 'Back' : 'ተመለስ'), 'callback_data' => 'dube:page:1'],
                ],
            ];

            $this->telegram->sendMessage($chatId, $msg, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
            return;
        }

        $title = $locale === 'en'
            ? "🔍 <b>Search Results for \"{$clean}\"</b>\nSelect customer:"
            : "🔍 <b>የፍለጋ ውጤቶች ለ \"{$clean}\"</b>\nደንበኛ ይምረጡ:";

        $buttons = [];
        foreach ($results as $c) {
            $credit = $this->balanceService->customerCredit($c);
            $balFormatted = number_format($credit['net_balance_cents'] / 100, 2) . ' ETB';
            $buttons[] = [
                ['text' => "👤 {$c->name} ({$balFormatted})", 'callback_data' => "dube:cust:{$c->id}"],
            ];
        }

        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Debtors' : 'ወደ ዱቤ ዝርዝር'), 'callback_data' => 'dube:page:1'],
        ];

        $this->telegram->sendMessage($chatId, $title, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }
}
