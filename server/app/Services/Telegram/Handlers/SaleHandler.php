<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\Change;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\BalanceService;
use App\Services\CustomerService;
use App\Services\LedgerService;
use App\Services\PaymentProviderService;
use App\Services\PermissionService;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SaleHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected BotStateService $botState,
        protected LedgerService $ledger,
        protected PermissionService $permissionService,
        protected LocationHandler $locationHandler,
        protected PaymentProviderService $providerService,
        protected CustomerService $customerService,
        protected BalanceService $balanceService
    ) {}

    /**
     * Start sale flow. Shows top/recent items as quick-tap buttons.
     */
    public function start(int|string $chatId, ?string $productId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $curLoc = $this->locationHandler->getCurrentLocation($user);
        if (!$this->permissionService->can($user, 'sale.create', $curLoc)) {
            $this->telegram->sendMessage($chatId, __('permission_denied'));
            return;
        }

        $shopId = (string) $user?->shop_id;

        if ($productId === null) {
            $products = Product::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->whereNull('deleted_at_ms')
                ->take(6)
                ->get();

            $rows = [];
            $currentRow = [];

            foreach ($products as $p) {
                $priceEtb = number_format($p->sell_price_cents / 100, 2) . ' ETB';
                $currentRow[] = [
                    'text' => "{$p->name} ({$priceEtb})",
                    'callback_data' => "sale:pick:{$p->id}",
                ];

                if (count($currentRow) === 2) {
                    $rows[] = $currentRow;
                    $currentRow = [];
                }
            }

            if (!empty($currentRow)) {
                $rows[] = $currentRow;
            }

            $this->telegram->sendMessage($chatId, __('sale_choose_product'), [
                'reply_markup' => TelegramUi::inlineKeyboard($rows),
            ]);
            return;
        }

        $this->promptQuantity($chatId, $productId, $user);
    }

    /**
     * Prompt for sale quantity with fast buttons: 1, 2, 5, 10, or custom.
     */
    public function promptQuantity(int|string $chatId, string $productId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $shopId = (string) $user?->shop_id;
        $product = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('id', $productId)
            ->first();

        if ($product === null) {
            $this->telegram->sendMessage($chatId, 'Product not found.');
            return;
        }

        $curLoc = $this->locationHandler->getCurrentLocation($user);
        $stockMilli = $curLoc ? $this->ledger->stockOf($product, $curLoc) : $this->ledger->stockOf($product);
        $stockFormatted = $stockMilli !== null ? LedgerService::formatQty($stockMilli) : ($locale === 'en' ? 'N/A' : 'አይቆጠርም');
        $priceEtb = number_format($product->sell_price_cents / 100, 2);

        $state = $this->botState->get($chatId);
        $cart = ($state !== null && $state->flow === 'sale') ? ($state->data['cart'] ?? []) : [];

        $this->botState->start($chatId, 'sale', 'await_qty', [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit' => $product->unit,
            'sell_price_cents' => $product->sell_price_cents,
            'cart' => $cart,
        ]);

        $prompt = __('sale_ask_qty', [
            'product' => $product->name,
            'price' => $priceEtb,
            'stock' => $stockFormatted,
            'unit' => $product->unit,
        ]);

        $qtyButtons = TelegramUi::inlineKeyboard([
            [
                ['text' => '1', 'callback_data' => 'sale:qty:1'],
                ['text' => '2', 'callback_data' => 'sale:qty:2'],
                ['text' => '5', 'callback_data' => 'sale:qty:5'],
                ['text' => '10', 'callback_data' => 'sale:qty:10'],
            ],
            [
                ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'sale:cancel:0'],
            ],
        ]);

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => $qtyButtons,
        ]);
    }

    /**
     * Add selected quantity to cart, verifying negative stock constraint.
     */
    public function handleQuantitySelected(int|string $chatId, float $qty, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get($chatId);
        if ($state === null || $state->flow !== 'sale') {
            return;
        }

        if ($qty <= 0) {
            $this->telegram->sendMessage($chatId, __('invalid_number'));
            return;
        }

        $data = $state->data;
        $productId = (string) $data['product_id'];
        $shopId = (string) $user?->shop_id;
        $shop = Shop::find($shopId);

        $product = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('id', $productId)
            ->first();

        if ($product === null) {
            $this->telegram->sendMessage($chatId, 'Product not found.');
            return;
        }

        $neededMilli = LedgerService::parseQty((string) $qty);

        // Check if negative stock is disallowed (stockable items only)
        if ($product->isStockable() && !$shop?->allow_negative_stock) {
            $curLoc = $this->locationHandler->getCurrentLocation($user);
            $currentStockMilli = $curLoc ? $this->ledger->stockOf($product, $curLoc) : $this->ledger->stockOf($product);
            $currentStockMilli = $currentStockMilli ?? 0;

            // Subtract any quantity of this product already in cart
            $alreadyInCartMilli = 0;
            foreach ($data['cart'] ?? [] as $item) {
                if ($item['product_id'] === $productId) {
                    $alreadyInCartMilli += (int) $item['qty_milli'];
                }
            }

            if (($currentStockMilli - $alreadyInCartMilli - $neededMilli) < 0) {
                $avail = LedgerService::formatQty(max(0, $currentStockMilli - $alreadyInCartMilli));
                $this->telegram->sendMessage($chatId, __('sale_insufficient_stock', [
                    'stock' => $avail,
                    'unit' => $product->unit,
                ]));
                return;
            }
        }

        // Half-up rounding per line: qty_milli * unit_price_cents / 1000
        $itemTotalCents = (int) round(($neededMilli * $product->sell_price_cents) / 1000, 0, PHP_ROUND_HALF_UP);
        $cart = $data['cart'] ?? [];
        $cart[] = [
            'product_id' => $product->id,
            'name' => $product->name,
            'unit' => $product->unit,
            'qty_milli' => $neededMilli,
            'qty_formatted' => LedgerService::formatQty($neededMilli),
            'unit_price_cents' => $product->sell_price_cents,
            'total_cents' => $itemTotalCents,
        ];

        $this->botState->next($chatId, 'cart', ['cart' => $cart]);

        $this->showCartSummary($chatId, $cart, $locale, $user);
    }

    /**
     * Display current cart and action buttons.
     */
    public function showCartSummary(int|string $chatId, array $cart, string $locale, ?User $user = null): void
    {
        $itemsText = '';
        $totalCents = 0;

        foreach ($cart as $idx => $item) {
            $num = $idx + 1;
            $linePrice = number_format($item['total_cents'] / 100, 2) . ' ETB';
            $itemsText .= "{$num}. {$item['name']} × {$item['qty_formatted']} {$item['unit']} = <b>{$linePrice}</b>\n";
            $totalCents += (int) $item['total_cents'];
        }

        $totalFormatted = number_format($totalCents / 100, 2) . ' ETB';

        $text = __('sale_cart_title', [
            'items' => $itemsText,
            'total' => $totalFormatted,
        ]);

        $shop = Shop::find($user?->shop_id);
        $enabledMethods = $shop?->payment_methods_enabled ?? ['cash', 'bank', 'wallet', 'dube'];
        $canDube = $this->permissionService->can($user, 'sale.dube');

        $kb = [];

        // Row 1: Cash & Bank
        $row1 = [];
        if (in_array('cash', $enabledMethods, true)) {
            $row1[] = ['text' => '💵 ' . ($locale === 'en' ? 'Cash' : 'ጥሬ ገንዘብ'), 'callback_data' => 'sale:pay_m:cash'];
        }
        if (in_array('bank', $enabledMethods, true)) {
            $row1[] = ['text' => '🏦 ' . ($locale === 'en' ? 'Bank' : 'ባንክ'), 'callback_data' => 'sale:pay_m:bank'];
        }
        if (!empty($row1)) {
            $kb[] = $row1;
        }

        // Row 2: Wallet & Dube
        $row2 = [];
        if (in_array('wallet', $enabledMethods, true)) {
            $row2[] = ['text' => '📱 ' . ($locale === 'en' ? 'Wallet' : 'ዋሌት'), 'callback_data' => 'sale:pay_m:wallet'];
        }
        if (in_array('dube', $enabledMethods, true) && $canDube) {
            $row2[] = ['text' => '📒 ' . ($locale === 'en' ? 'Dube' : 'ዱቤ'), 'callback_data' => 'sale:pay_m:dube'];
        }
        if (!empty($row2)) {
            $kb[] = $row2;
        }

        // Row 3: Add more & Cancel
        $kb[] = [
            ['text' => __('btn_add_more'), 'callback_data' => 'sale:start:none'],
            ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'sale:cancel:0'],
        ];

        $buttons = TelegramUi::inlineKeyboard($kb);

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Cart finish tapped: fast path finishes as Cash sale.
     */
    public function finishSale(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $this->handleCashPaymentSelected($callbackId, $chatId, $messageId, $user);
    }

    /**
     * Show payment step: [💵 Cash] [🏦 Bank] [📱 Wallet] [📒 Dube].
     * Hides methods disabled by shop owner, and Dube if user lacks sale.dube permission.
     */
    public function showPaymentStep(int|string $chatId, ?int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get($chatId);
        if ($state === null || $state->flow !== 'sale') {
            return;
        }

        $cart = $state->data['cart'] ?? [];
        $totalCents = 0;
        foreach ($cart as $item) {
            $totalCents += (int) $item['total_cents'];
        }

        $totalFormatted = number_format($totalCents / 100, 2) . ' ETB';

        $shop = Shop::find($user?->shop_id);
        $enabledMethods = $shop?->payment_methods_enabled ?? ['cash', 'bank', 'wallet', 'dube'];

        $curLoc = $this->locationHandler->getCurrentLocation($user);
        $canDube = $this->permissionService->can($user, 'sale.dube', $curLoc);

        $buttons = [];
        $row1 = [];

        if (in_array('cash', $enabledMethods, true)) {
            $row1[] = ['text' => '💵 ' . ($locale === 'en' ? 'Cash' : 'ጥሬ ገንዘብ'), 'callback_data' => 'sale:pay_m:cash'];
        }
        if (in_array('bank', $enabledMethods, true)) {
            $row1[] = ['text' => '🏦 ' . ($locale === 'en' ? 'Bank' : 'ባንክ'), 'callback_data' => 'sale:pay_m:bank'];
        }
        if (!empty($row1)) {
            $buttons[] = $row1;
        }

        $row2 = [];
        if (in_array('wallet', $enabledMethods, true)) {
            $row2[] = ['text' => '📱 ' . ($locale === 'en' ? 'Wallet' : 'ዋሌት'), 'callback_data' => 'sale:pay_m:wallet'];
        }
        if (in_array('dube', $enabledMethods, true) && $canDube) {
            $row2[] = ['text' => '📒 ' . ($locale === 'en' ? 'Dube' : 'ዱቤ'), 'callback_data' => 'sale:pay_m:dube'];
        }
        if (!empty($row2)) {
            $buttons[] = $row2;
        }

        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Cart' : 'ወደ ቅርጫት ተመለስ'), 'callback_data' => 'sale:cart_back:0'],
            ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'sale:cancel:0'],
        ];

        $text = $locale === 'en'
            ? "💳 <b>Payment Step</b>\nTotal Amount: <b>{$totalFormatted}</b>\n\nChoose payment method (Cash is fast 1-tap finish):"
            : "💳 <b>የክፍያ ደረጃ</b>\nጠቅላላ ድምር: <b>{$totalFormatted}</b>\n\nየክፍያ መንገድ ይምረጡ (ጥሬ ገንዘብ በአንድ ንክኪ ያጠናቅቃል):";

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
     * Show paginated provider list for Bank or Wallet.
     */
    public function showProviderList(
        string $callbackId,
        int|string $chatId,
        int $messageId,
        string $kind,
        int $page,
        ?User $user
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($callbackId);

        $shopId = (string) $user?->shop_id;
        $providers = $this->providerService->listProviders($shopId, $kind, $locale);
        $enabledProviders = $providers->filter(fn($p) => $p['enabled'])->values();

        $totalItems = $enabledProviders->count();
        $perPage = 6;
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $pageItems = $enabledProviders->slice($offset, $perPage)->values();

        $kindLabel = $kind === 'bank'
            ? ($locale === 'en' ? 'Bank' : 'ባንክ')
            : ($locale === 'en' ? 'Mobile Wallet' : 'የሞባይል ዋሌት');

        $text = $locale === 'en'
            ? "🏦 <b>Select {$kindLabel} (Page {$page}/{$totalPages})</b>\nFavorites and recents are listed first:"
            : "🏦 <b>{$kindLabel} ይምረጡ (ገጽ {$page}/{$totalPages})</b>\nተመራጭ እና በቅርብ የተጠቀሟቸው በቀዳሚነት ተቀምጠዋል:";

        $buttons = [];
        foreach ($pageItems as $item) {
            $star = $item['favorite'] ? '⭐ ' : '';
            $label = "{$star}{$item['display_name']}";
            $buttons[] = [
                ['text' => $label, 'callback_data' => "sale:prov_pick:{$kind}:{$item['provider_key']}"],
            ];
        }

        // Pagination row
        if ($totalPages > 1) {
            $navRow = [];
            if ($page > 1) {
                $prev = $page - 1;
                $navRow[] = ['text' => '◀️ ' . ($locale === 'en' ? 'Prev' : 'ወደኋላ'), 'callback_data' => "sale:prov_page:{$kind}:{$prev}"];
            }
            $navRow[] = ['text' => "{$page} / {$totalPages}", 'callback_data' => 'noop:none:0'];
            if ($page < $totalPages) {
                $next = $page + 1;
                $navRow[] = ['text' => ($locale === 'en' ? 'Next' : 'ወደፊት') . ' ▶️', 'callback_data' => "sale:prov_page:{$kind}:{$next}"];
            }
            $buttons[] = $navRow;
        }

        // Other bank/wallet & back
        $otherLabel = $kind === 'bank'
            ? ($locale === 'en' ? '➕ Other Bank…' : '➕ ሌላ ባንክ…')
            : ($locale === 'en' ? '➕ Other Wallet…' : '➕ ሌላ ዋሌት…');

        $buttons[] = [
            ['text' => $otherLabel, 'callback_data' => "sale:prov_other:{$kind}"],
        ];

        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back' : 'ተመለስ'), 'callback_data' => 'sale:pay_step:0'],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Provider chosen: prompt reference if enabled/required, or proceed.
     */
    public function handleProviderPicked(
        string $callbackId,
        int|string $chatId,
        int $messageId,
        string $kind,
        string $providerKey,
        ?User $user
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($callbackId);

        $state = $this->botState->get($chatId);
        if ($state === null || $state->flow !== 'sale') {
            return;
        }

        $data = $state->data;
        $data['payment_method'] = $kind;
        $data['provider_key'] = $providerKey;

        // If this provider pick was for Dube upfront payment, return to Dube flow
        if (!empty($data['dube_upfront_in_progress'])) {
            $data['dube_upfront_method'] = $kind;
            $data['dube_upfront_provider'] = $providerKey;
            unset($data['dube_upfront_in_progress']);

            $this->botState->next($chatId, 'sale_dube_due', $data);
            $this->showDubeDueDateStep($chatId, $messageId, $user);
            return;
        }

        $shop = Shop::find($user?->shop_id);
        $requireRef = (bool) ($shop?->require_payment_reference ?? false);

        $data['require_reference'] = $requireRef;
        $this->botState->next($chatId, 'await_sale_ref', $data);

        $provName = $this->providerService->listProviders((string) $user?->shop_id, $kind, $locale)
            ->firstWhere('provider_key', $providerKey)['display_name'] ?? $providerKey;

        if ($requireRef) {
            $prompt = $locale === 'en'
                ? "📝 <b>Transaction Reference Required</b>\nProvider: <b>{$provName}</b>\n\nPlease enter the transaction reference / receipt number:"
                : "📝 <b>የግብይት ማጣቀሻ ቁጥር ያስፈልጋል</b>\nተቋም: <b>{$provName}</b>\n\nእባክዎ የማጣቀሻ ቁጥር (Reference / Txn ID) ያስገቡ:";

            $buttons = [
                [['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'sale:cancel:0']],
            ];
        } else {
            $prompt = $locale === 'en'
                ? "📝 <b>Transaction Reference (Optional)</b>\nProvider: <b>{$provName}</b>\n\nEnter reference number or tap Skip:"
                : "📝 <b>የግብይት ማጣቀሻ ቁጥር (አስገዳጅ ያልሆነ)</b>\nተቋም: <b>{$provName}</b>\n\nየማጣቀሻ ቁጥር ያስገቡ ወይም ይለፉ:";

            $buttons = [
                [['text' => '⏭ ' . ($locale === 'en' ? 'Skip Reference' : 'ይለፉ'), 'callback_data' => 'sale:skip_ref:0']],
                [['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'sale:cancel:0']],
            ];
        }

        $this->telegram->editMessageText($chatId, $messageId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * "Other bank..." / "Other wallet..." tapped: prompt for custom provider name.
     */
    public function handleOtherProviderSelected(
        string $callbackId,
        int|string $chatId,
        int $messageId,
        string $kind,
        ?User $user
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($callbackId);

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $data['custom_kind'] = $kind;

        $this->botState->next($chatId, 'await_custom_prov', $data);

        $prompt = $locale === 'en'
            ? "➕ <b>Add Custom {$kind}</b>\nPlease type the name of the bank or wallet (e.g. Siinqee Bank, Coopay):"
            : "➕ <b>አዲስ " . ($kind === 'bank' ? 'ባንክ' : 'ዋሌት') . " መመዝገብ</b>\nእባክዎ የተቋሙን ስም ይጻፉ (ለምሳሌ ሲንቄ ባንክ):";

        $buttons = [
            [['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'sale:pay_step:0']],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Custom provider name typed by user.
     */
    public function handleCustomProviderEntered(int|string $chatId, string $name, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $clean = trim($name);
        if (mb_strlen($clean) < 2) {
            $this->telegram->sendMessage($chatId, 'Please enter a valid name.');
            return;
        }

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $kind = $data['custom_kind'] ?? 'bank';
        $shopId = (string) $user?->shop_id;

        $custom = $this->providerService->createCustomProvider($shopId, $kind, $clean);

        $data['payment_method'] = $kind;
        $data['provider_key'] = $custom->provider_key;
        unset($data['custom_kind']);

        // Check reference requirement
        $shop = Shop::find($shopId);
        $requireRef = (bool) ($shop?->require_payment_reference ?? false);
        $data['require_reference'] = $requireRef;

        $this->botState->next($chatId, 'await_sale_ref', $data);

        $prompt = $locale === 'en'
            ? "✅ Saved <b>{$custom->name}</b>!\nEnter transaction reference" . ($requireRef ? " (Required):" : " (Optional):")
            : "✅ <b>{$custom->name}</b> ተመዝግቧል!\nየማጣቀሻ ቁጥር ያስገቡ" . ($requireRef ? " (አስገዳጅ):" : " (አስገዳጅ ያልሆነ):");

        $buttons = [];
        if (!$requireRef) {
            $buttons[] = [['text' => '⏭ ' . ($locale === 'en' ? 'Skip Reference' : 'ይለፉ'), 'callback_data' => 'sale:skip_ref:0']];
        }
        $buttons[] = [['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'sale:cancel:0']];

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Reference entered or skipped. Completes full Bank or Wallet sale.
     */
    public function handleReferenceCompleted(int|string $chatId, ?string $ref, ?User $user, ?int $messageId = null): void
    {
        $state = $this->botState->get($chatId);
        if ($state === null || $state->flow !== 'sale') {
            return;
        }

        $data = $state->data;
        $method = $data['payment_method'] ?? 'bank';
        $providerKey = $data['provider_key'] ?? null;
        $cart = $data['cart'] ?? [];

        $totalCents = 0;
        foreach ($cart as $item) {
            $totalCents += (int) $item['total_cents'];
        }

        $this->completeSale(
            chatId: $chatId,
            messageId: $messageId,
            cart: $cart,
            totalCents: $totalCents,
            method: $method,
            paidNowCents: $totalCents,
            providerKey: $providerKey,
            reference: !empty($ref) && $ref !== '-' ? trim($ref) : null,
            customerId: null,
            dueDate: null,
            user: $user
        );
    }

    /**
     * Start Dube flow: customer selection.
     */
    public function startDubeStep(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $curLoc = $this->locationHandler->getCurrentLocation($user);
        if (!$this->permissionService->can($user, 'sale.dube', $curLoc)) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $shopId = (string) $user?->shop_id;
        $customers = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereNull('deleted_at_ms')
            ->orderByDesc('updated_at_ms')
            ->limit(6)
            ->get();

        $text = $locale === 'en'
            ? "📒 <b>Dube (Pay Later) — Select Customer</b>\nPick a recent customer or tap New Customer:"
            : "📒 <b>የዱቤ ሽያጭ — ደንበኛ ይምረጡ</b>\nደንበኛ ይምረጡ ወይም አዲስ ደንበኛ ይመዝግቡ:";

        $buttons = [];
        foreach ($customers as $c) {
            $phoneStr = $c->phone ? " ({$c->phone})" : "";
            $buttons[] = [
                ['text' => "👤 {$c->name}{$phoneStr}", 'callback_data' => "sale:dube_cust:{$c->id}"],
            ];
        }

        $buttons[] = [
            ['text' => '➕ ' . ($locale === 'en' ? 'New Customer' : 'አዲስ ደንበኛ'), 'callback_data' => 'sale:dube_new:0'],
            ['text' => '🔍 ' . ($locale === 'en' ? 'Search Customer' : 'ደንበኛ ፈልግ'), 'callback_data' => 'sale:dube_search:0'],
        ];

        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back' : 'ተመለስ'), 'callback_data' => 'sale:pay_step:0'],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Customer picked for Dube sale: prompt "Paid now?".
     */
    public function handleDubeCustomerPicked(int|string $chatId, string $customerId, ?User $user, ?int $messageId = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $data['dube_customer_id'] = $customerId;

        $this->botState->next($chatId, 'sale_dube_paid', $data);

        $customer = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user?->shop_id)
            ->where('id', $customerId)
            ->first();

        $cart = $data['cart'] ?? [];
        $totalCents = 0;
        foreach ($cart as $item) {
            $totalCents += (int) $item['total_cents'];
        }

        $c25 = (int) round($totalCents * 0.25);
        $c50 = (int) round($totalCents * 0.50);

        $totF = number_format($totalCents / 100, 2) . ' ETB';
        $c25F = number_format($c25 / 100, 2) . ' ETB';
        $c50F = number_format($c50 / 100, 2) . ' ETB';

        $custName = $customer?->name ?? 'Customer';

        $text = $locale === 'en'
            ? "📒 <b>Dube Sale — Paid Now?</b>\nCustomer: <b>{$custName}</b>\nTotal Sale: <b>{$totF}</b>\n\nHow much is the customer paying upfront now?"
            : "📒 <b>የዱቤ ሽያጭ — አሁን የሚከፈል?</b>\nደንበኛ: <b>{$custName}</b>\nጠቅላላ ሽያጭ: <b>{$totF}</b>\n\nደንበኛው አሁን በቅድሚያ ምን ያህል ይከፍላል?";

        $buttons = [
            [
                ['text' => '0 ETB (0%)', 'callback_data' => 'sale:dube_pnow:0'],
                ['text' => "25% ({$c25F})", 'callback_data' => "sale:dube_pnow:{$c25}"],
                ['text' => "50% ({$c50F})", 'callback_data' => "sale:dube_pnow:{$c50}"],
            ],
            [
                ['text' => '✏️ ' . ($locale === 'en' ? 'Custom Amount' : 'የተለየ መጠን'), 'callback_data' => 'sale:dube_pnow:custom'],
            ],
            [
                ['text' => '◀️ ' . ($locale === 'en' ? 'Back' : 'ተመለስ'), 'callback_data' => 'sale:pay_m:dube'],
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
     * Paid now amount chosen: if > 0 prompt upfront method, else proceed to due date.
     */
    public function handleDubePaidNowAmount(int|string $chatId, int $paidNowCents, ?User $user, ?int $messageId = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $data['dube_paid_now_cents'] = $paidNowCents;

        if ($paidNowCents === 0) {
            $data['dube_upfront_method'] = null;
            $data['dube_upfront_provider'] = null;
            $this->botState->next($chatId, 'sale_dube_due', $data);
            $this->showDubeDueDateStep($chatId, $messageId, $user);
            return;
        }

        // Upfront paid > 0: ask payment method for upfront part
        $this->botState->next($chatId, 'sale_dube_method', $data);

        $shop = Shop::find($user?->shop_id);
        $enabled = $shop?->payment_methods_enabled ?? ['cash', 'bank', 'wallet', 'dube'];

        $amtF = number_format($paidNowCents / 100, 2) . ' ETB';

        $text = $locale === 'en'
            ? "💵 <b>Upfront Payment Method</b>\nPaid Now: <b>{$amtF}</b>\n\nChoose method for this upfront payment:"
            : "💵 <b>የቅድመ ክፍያ መንገድ</b>\nአሁን የሚከፈል: <b>{$amtF}</b>\n\nየቅድመ ክፍያውን መንገድ ይምረጡ:";

        $buttons = [];
        $row = [];
        if (in_array('cash', $enabled, true)) {
            $row[] = ['text' => '💵 ' . ($locale === 'en' ? 'Cash' : 'ጥሬ ገንዘብ'), 'callback_data' => 'sale:dube_umeth:cash'];
        }
        if (in_array('bank', $enabled, true)) {
            $row[] = ['text' => '🏦 ' . ($locale === 'en' ? 'Bank' : 'ባንክ'), 'callback_data' => 'sale:dube_umeth:bank'];
        }
        if (in_array('wallet', $enabled, true)) {
            $row[] = ['text' => '📱 ' . ($locale === 'en' ? 'Wallet' : 'ዋሌት'), 'callback_data' => 'sale:dube_umeth:wallet'];
        }
        if (!empty($row)) {
            $buttons[] = $row;
        }

        $buttons[] = [
            ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'sale:cancel:0'],
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
     * Show due date options (+7, +14, +30, custom).
     */
    public function showDubeDueDateStep(int|string $chatId, ?int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $shop = Shop::find($user?->shop_id);
        $defaultDays = $shop?->dube_default_days ?? 14;

        $tz = $shop?->timezone ?: 'Africa/Addis_Ababa';
        $defaultDate = Carbon::now($tz)->addDays($defaultDays)->toDateString();

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $data['dube_due_date'] = $defaultDate;
        $this->botState->next($chatId, 'sale_dube_due', $data);

        $text = $locale === 'en'
            ? "📅 <b>Dube Due Date</b>\nDefault: <b>+{$defaultDays} days ({$defaultDate})</b>\n\nChoose due date for remaining balance:"
            : "📅 <b>የዱቤ መክፈያ ቀን</b>\nየተመረጠ: <b>+{$defaultDays} ቀናት ({$defaultDate})</b>\n\nየቀሪው ዕዳ መክፈያ ቀን ይምረጡ:";

        $buttons = [
            [
                ['text' => '+7 ' . ($locale === 'en' ? 'Days' : 'ቀናት'), 'callback_data' => 'sale:dube_due:7'],
                ['text' => '+14 ' . ($locale === 'en' ? 'Days' : 'ቀናት'), 'callback_data' => 'sale:dube_due:14'],
                ['text' => '+30 ' . ($locale === 'en' ? 'Days' : 'ቀናት'), 'callback_data' => 'sale:dube_due:30'],
            ],
            [
                ['text' => '📅 ' . ($locale === 'en' ? 'Custom Date' : 'የተለየ ቀን'), 'callback_data' => 'sale:dube_due:custom'],
            ],
            [
                ['text' => '➡️ ' . ($locale === 'en' ? "Use Default ({$defaultDate})" : "በዚህ ቀጥል ({$defaultDate})"), 'callback_data' => "sale:dube_due_pick:{$defaultDate}"],
            ],
            [
                ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'sale:cancel:0'],
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
     * Show Dube sale summary and confirmation button.
     */
    public function showDubeSummary(int|string $chatId, ?int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];

        $customerId = $data['dube_customer_id'] ?? null;
        $dueDate = $data['dube_due_date'] ?? null;
        $paidNowCents = (int) ($data['dube_paid_now_cents'] ?? 0);
        $upfrontMethod = $data['dube_upfront_method'] ?? 'cash';
        $cart = $data['cart'] ?? [];

        if (empty($customerId) || empty($dueDate)) {
            $this->telegram->sendMessage($chatId, 'Customer and due date are required for Dube sales.');
            return;
        }

        $customer = Customer::withoutGlobalScope(ShopScope::class)->find($customerId);
        $totalCents = 0;
        foreach ($cart as $item) {
            $totalCents += (int) $item['total_cents'];
        }

        $dubeCents = max(0, $totalCents - $paidNowCents);

        $totF = number_format($totalCents / 100, 2) . ' ETB';
        $paidF = number_format($paidNowCents / 100, 2) . ' ETB';
        $dubeF = number_format($dubeCents / 100, 2) . ' ETB';

        $text = $locale === 'en'
            ? "📒 <b>Confirm Dube Sale</b>\n\n"
                . "👤 Customer: <b>{$customer?->name}</b>\n"
                . "💰 Total Sale: <b>{$totF}</b>\n"
                . "💵 Pays Now: <b>{$paidF}</b> (" . ucfirst($upfrontMethod) . ")\n"
                . "📒 Dube (Pay Later): <b>{$dubeF}</b>\n"
                . "📅 Due Date: <b>{$dueDate}</b>\n\n"
                . "Confirm and record this sale?"
            : "📒 <b>የዱቤ ሽያጭ ማረጋገጫ</b>\n\n"
                . "👤 ደንበኛ: <b>{$customer?->name}</b>\n"
                . "💰 ጠቅላላ ሽያጭ: <b>{$totF}</b>\n"
                . "💵 አሁን የሚከፈል: <b>{$paidF}</b> (" . ucfirst($upfrontMethod) . ")\n"
                . "📒 ዱቤ: <b>{$dubeF}</b>\n"
                . "📅 የመክፈያ ቀን: <b>{$dueDate}</b>\n\n"
                . "ሽያጩን ማረጋገጥ ይፈልጋሉ?";

        $buttons = [
            [
                ['text' => '✅ ' . ($locale === 'en' ? 'Confirm Sale' : 'ሽያጩን አረጋግጥ'), 'callback_data' => 'sale:dube_confirm:0'],
            ],
            [
                ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'sale:cancel:0'],
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
     * Fast path: 1-tap Cash payment finishes sale immediately.
     */
    public function handleCashPaymentSelected(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $this->telegram->answerCallbackQuery($callbackId);

        $state = $this->botState->get($chatId);
        if ($state === null || $state->flow !== 'sale') {
            return;
        }

        $cart = $state->data['cart'] ?? [];
        if (empty($cart)) {
            return;
        }

        $totalCents = 0;
        foreach ($cart as $item) {
            $totalCents += (int) $item['total_cents'];
        }

        $this->completeSale(
            chatId: $chatId,
            messageId: $messageId,
            cart: $cart,
            totalCents: $totalCents,
            method: 'cash',
            paidNowCents: $totalCents,
            providerKey: null,
            reference: null,
            customerId: null,
            dueDate: null,
            user: $user
        );
    }

    /**
     * Start adding a new customer from within the sale Dube flow.
     */
    public function startDubeAddCustomer(string $callbackId, int|string $chatId, ?User $user): void
    {
        $this->telegram->answerCallbackQuery($callbackId);
        $dubeHandler = app(DubeHandler::class);
        $state = $this->botState->get($chatId);
        $cartData = $state?->data ?? [];

        $dubeHandler->startAddCustomer('', $chatId, $user);

        // Update state to include return_to_sale flag and preserved cart
        $newState = $this->botState->get($chatId);
        $data = $newState?->data ?? [];
        $data['return_to_sale'] = true;
        $data['cart'] = $cartData['cart'] ?? [];
        $this->botState->next($chatId, 'await_name', $data);
    }

    /**
     * Search customer within sale Dube flow.
     */
    public function startDubeCustomerSearch(string $callbackId, int|string $chatId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($callbackId);

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];

        $this->botState->start($chatId, 'sale', 'await_dube_search', $data);

        $prompt = $locale === 'en'
            ? "🔍 <b>Search Customer for Dube Sale</b>\nEnter name or phone number:"
            : "🔍 <b>የዱቤ ሽያጭ ደንበኛ መፈለግ</b>\nየደንበኛውን ስም ወይም ስልክ ቁጥር ያስገቡ:";

        $buttons = [
            [['text' => '◀️ ' . ($locale === 'en' ? 'Back' : 'ተመለስ'), 'callback_data' => 'sale:pay_m:dube']],
        ];

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Handle search query entered in sale flow.
     */
    public function handleDubeCustomerSearchEntered(int|string $chatId, string $query, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $clean = trim($query);
        $shopId = (string) $user?->shop_id;

        $results = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereNull('deleted_at_ms')
            ->where(function ($q) use ($clean) {
                $q->where('name', 'LIKE', "%{$clean}%")
                    ->orWhere('phone', 'LIKE', "%{$clean}%");
            })
            ->limit(8)
            ->get();

        if ($results->isEmpty()) {
            $msg = $locale === 'en'
                ? "🔍 No customers found matching <b>\"{$clean}\"</b>."
                : "🔍 ከ <b>\"{$clean}\"</b> ጋር የሚዛመድ ደንበኛ አልተገኘም።";

            $buttons = [
                [
                    ['text' => '➕ ' . ($locale === 'en' ? 'New Customer' : 'አዲስ ደንበኛ'), 'callback_data' => 'sale:dube_new:0'],
                    ['text' => '◀️ ' . ($locale === 'en' ? 'Back' : 'ተመለስ'), 'callback_data' => 'sale:pay_m:dube'],
                ],
            ];

            $this->telegram->sendMessage($chatId, $msg, [
                'reply_markup' => TelegramUi::inlineKeyboard($buttons),
            ]);
            return;
        }

        $title = $locale === 'en'
            ? "🔍 <b>Select Customer:</b>"
            : "🔍 <b>ደንበኛ ይምረጡ:</b>";

        $buttons = [];
        foreach ($results as $c) {
            $phoneStr = $c->phone ? " ({$c->phone})" : '';
            $buttons[] = [
                ['text' => "👤 {$c->name}{$phoneStr}", 'callback_data' => "sale:dube_cust:{$c->id}"],
            ];
        }

        $buttons[] = [
            ['text' => '➕ ' . ($locale === 'en' ? 'New Customer' : 'አዲስ ደንበኛ'), 'callback_data' => 'sale:dube_new:0'],
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back' : 'ተመለስ'), 'callback_data' => 'sale:pay_m:dube'],
        ];

        $this->telegram->sendMessage($chatId, $title, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Prompt custom upfront amount for Dube sale.
     */
    public function promptCustomPaidNowAmount(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($callbackId);

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $this->botState->next($chatId, 'await_dube_pnow', $data);

        $cart = $data['cart'] ?? [];
        $totalCents = 0;
        foreach ($cart as $item) {
            $totalCents += (int) $item['total_cents'];
        }
        $totF = number_format($totalCents / 100, 2) . ' ETB';

        $prompt = $locale === 'en'
            ? "✏️ <b>Enter Upfront Payment in ETB</b>\nTotal Sale: <b>{$totF}</b>\n(Enter 0 or amount customer pays now):"
            : "✏️ <b>የቅድመ ክፍያ መጠን በብር ያስገቡ</b>\nጠቅላላ ሽያጭ: <b>{$totF}</b>\n(0 ወይም አሁን የሚከፈለውን መጠን ያስገቡ):";

        $buttons = [
            [['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'sale:cancel:0']],
        ];

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Handle custom upfront amount entered.
     */
    public function handleCustomPaidNowAmountEntered(int|string $chatId, string $text, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $num = TelegramUi::parseNumber($text);
        if ($num === null || $num < 0) {
            $this->telegram->sendMessage($chatId, __('invalid_number'));
            return;
        }

        $cents = (int) round($num * 100);

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $cart = $data['cart'] ?? [];
        $totalCents = 0;
        foreach ($cart as $item) {
            $totalCents += (int) $item['total_cents'];
        }

        if ($cents > $totalCents) {
            $totF = number_format($totalCents / 100, 2) . ' ETB';
            $this->telegram->sendMessage(
                $chatId,
                $locale === 'en'
                    ? "⚠️ Upfront payment cannot exceed total sale amount ({$totF})."
                    : "⚠️ የቅድመ ክፍያ መጠኑ ከጠቅላላ የሽያጭ ዋጋው ({$totF}) መብለጥ አይችልም።"
            );
            return;
        }

        $this->handleDubePaidNowAmount($chatId, $cents, $user);
    }

    /**
     * Handle payment method picked for upfront Dube payment.
     */
    public function handleDubeUpfrontMethodPicked(
        string $callbackId,
        int|string $chatId,
        int $messageId,
        string $method,
        ?User $user
    ): void {
        $this->telegram->answerCallbackQuery($callbackId);

        $state = $this->botState->get($chatId);
        if ($state === null || $state->flow !== 'sale') {
            return;
        }

        $data = $state->data;
        if ($method === 'cash') {
            $data['dube_upfront_method'] = 'cash';
            $data['dube_upfront_provider'] = null;
            $this->botState->next($chatId, 'sale_dube_due', $data);
            $this->showDubeDueDateStep($chatId, $messageId, $user);
            return;
        }

        // Bank or Wallet: mark upfront in progress and show provider list
        $data['dube_upfront_in_progress'] = true;
        $this->botState->next($chatId, 'sale_provider', $data);
        $this->showProviderList($callbackId, $chatId, $messageId, $method, 1, $user);
    }

    /**
     * Prompt custom due date for Dube sale.
     */
    public function promptCustomDubeDueDate(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $this->telegram->answerCallbackQuery($callbackId);

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $this->botState->next($chatId, 'await_dube_due', $data);

        $prompt = $locale === 'en'
            ? "📅 <b>Enter Dube Due Date</b>\nPlease enter date in format <code>YYYY-MM-DD</code> (e.g. 2026-11-20):"
            : "📅 <b>የዱቤ መክፈያ ቀን ያስገቡ</b>\nእባክዎ ቀኑን በ <code>YYYY-MM-DD</code> ቅርጸት ያስገቡ (ለምሳሌ 2026-11-20):";

        $buttons = [
            [['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'sale:cancel:0']],
        ];

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Handle custom due date entered in Dube sale flow.
     */
    public function handleCustomDubeDueDateEntered(int|string $chatId, string $text, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

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

        $shop = Shop::find($user?->shop_id);
        $tz = $shop?->timezone ?: 'Africa/Addis_Ababa';
        $today = Carbon::now($tz)->toDateString();
        if ($cleanDate < $today) {
            $this->telegram->sendMessage(
                $chatId,
                $locale === 'en'
                    ? "⚠️ Due date cannot be in the past."
                    : "⚠️ የመክፈያ ቀን ካለፈ ቀን መሆን አይችልም።"
            );
            return;
        }

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $data['dube_due_date'] = $cleanDate;
        $this->botState->next($chatId, 'sale_dube_summary', $data);

        $this->showDubeSummary($chatId, null, $user);
    }

    /**
     * Handle quick due date days button (+7, +14, +30).
     */
    public function handleDubeDueDateDays(string $callbackId, int|string $chatId, int $messageId, int $days, ?User $user): void
    {
        $this->telegram->answerCallbackQuery($callbackId);

        $shop = Shop::find($user?->shop_id);
        $tz = $shop?->timezone ?: 'Africa/Addis_Ababa';
        $targetDate = Carbon::now($tz)->addDays($days)->toDateString();

        $this->handleDubeDueDatePicked($callbackId, $chatId, $messageId, $targetDate, $user);
    }

    /**
     * Handle due date picked.
     */
    public function handleDubeDueDatePicked(string $callbackId, int|string $chatId, int $messageId, string $date, ?User $user): void
    {
        if ($callbackId !== '') {
            $this->telegram->answerCallbackQuery($callbackId);
        }

        $state = $this->botState->get($chatId);
        $data = $state?->data ?? [];
        $data['dube_due_date'] = $date;
        $this->botState->next($chatId, 'sale_dube_summary', $data);

        $this->showDubeSummary($chatId, $messageId, $user);
    }

    /**
     * Handle Dube confirmation button.
     */
    public function handleDubeConfirm(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $this->telegram->answerCallbackQuery($callbackId);

        $state = $this->botState->get($chatId);
        if ($state === null || $state->flow !== 'sale') {
            return;
        }

        $data = $state->data;
        $cart = $data['cart'] ?? [];
        if (empty($cart)) {
            return;
        }

        $totalCents = 0;
        foreach ($cart as $item) {
            $totalCents += (int) $item['total_cents'];
        }

        $paidNowCents = (int) ($data['dube_paid_now_cents'] ?? 0);
        $upfrontMethod = $data['dube_upfront_method'] ?? 'cash';
        $providerKey = $data['dube_upfront_provider'] ?? null;
        $customerId = $data['dube_customer_id'] ?? null;
        $dueDate = $data['dube_due_date'] ?? null;

        $this->completeSale(
            chatId: $chatId,
            messageId: $messageId,
            cart: $cart,
            totalCents: $totalCents,
            method: $upfrontMethod,
            paidNowCents: $paidNowCents,
            providerKey: $providerKey,
            reference: null,
            customerId: $customerId,
            dueDate: $dueDate,
            user: $user
        );
    }

    /**
     * Unified complete sale method: writes Sale, Movements with sale_id, Payment, and Change rows.
     */
    public function completeSale(
        int|string $chatId,
        ?int $messageId,
        array $cart,
        int $totalCents,
        string $method,
        int $paidNowCents,
        ?string $providerKey,
        ?string $reference,
        ?string $customerId,
        ?string $dueDate,
        ?User $user
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $curLoc = $this->locationHandler->getCurrentLocation($user);
        $shopId = (string) $user?->shop_id;
        $nowMs = (int) (microtime(true) * 1000);

        // Validation for Dube
        $isDube = ($totalCents - $paidNowCents) > 0;
        if ($isDube) {
            if (!$this->permissionService->can($user, 'sale.dube', $curLoc)) {
                $this->telegram->sendMessage($chatId, __('permission_denied'));
                return;
            }

            if (empty($customerId)) {
                $this->telegram->sendMessage($chatId, 'Customer is required for Dube sale.');
                return;
            }

            if (empty($dueDate)) {
                $this->telegram->sendMessage($chatId, 'Due date is required for Dube sale.');
                return;
            }

            $shop = Shop::find($shopId);
            $tz = $shop?->timezone ?: 'Africa/Addis_Ababa';
            $today = Carbon::now($tz)->toDateString();
            if ($dueDate < $today) {
                $this->telegram->sendMessage($chatId, 'Due date cannot be before sale date.');
                return;
            }
        }

        // Provider validation
        if ($paidNowCents > 0) {
            if ($method === 'cash' && $providerKey !== null) {
                $providerKey = null;
            }
            if (($method === 'bank' || $method === 'wallet') && empty($providerKey)) {
                $this->telegram->sendMessage($chatId, ucfirst($method) . ' payments require provider.');
                return;
            }
        }

        // Lock / clear bot state
        $this->botState->clear($chatId);

        // Execute atomic transaction
        $receipt = DB::transaction(function () use (
            $shopId,
            $user,
            $curLoc,
            $cart,
            $totalCents,
            $paidNowCents,
            $method,
            $providerKey,
            $reference,
            $customerId,
            $dueDate,
            $nowMs
        ) {
            $saleId = (string) Str::uuid7();

            // 1. Create Sale header
            $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
                'id' => $saleId,
                'shop_id' => $shopId,
                'location_id' => $curLoc?->id,
                'user_id' => $user?->id,
                'device_id' => null,
                'customer_id' => $customerId,
                'total_cents' => $totalCents,
                'due_date' => $dueDate,
                'note' => 'Telegram Sale',
                'legacy' => false,
                'voided_at_ms' => null,
                'created_at_ms' => $nowMs,
                'updated_at_ms' => $nowMs,
            ]);

            Change::withoutGlobalScope(ShopScope::class)->create([
                'shop_id' => $shopId,
                'device_id' => null,
                'entity' => 'sale',
                'entity_id' => $saleId,
            ]);

            // 2. Create Movements with sale_id (supports stockable, service, and consumable items)
            $salesService = app(\App\Services\SalesService::class);
            $receiptLines = '';
            foreach ($cart as $idx => $item) {
                $num = $idx + 1;
                $linePrice = number_format($item['total_cents'] / 100, 2) . ' ETB';
                $receiptLines .= "{$num}. {$item['name']} × {$item['qty_formatted']} {$item['unit']} = <b>{$linePrice}</b>\n";

                $salesService->addLine(
                    sale: $sale,
                    product: $item['product_id'],
                    qty: (int) $item['qty_milli'],
                    unitPriceCents: (int) $item['unit_price_cents'],
                    location: $curLoc?->id,
                    user: $user,
                    device: null,
                    note: 'Telegram Sale',
                    createdAtMs: $nowMs
                );
            }

            // 3. Create Payment if paid portion > 0
            if ($paidNowCents > 0) {
                $payId = (string) Str::uuid7();
                $maxSeq = (int) Payment::withoutGlobalScope(ShopScope::class)
                    ->where('shop_id', $shopId)
                    ->max('server_seq');

                Payment::withoutGlobalScope(ShopScope::class)->create([
                    'id' => $payId,
                    'shop_id' => $shopId,
                    'sale_id' => $saleId,
                    'customer_id' => $customerId,
                    'location_id' => $curLoc?->id,
                    'user_id' => $user?->id,
                    'device_id' => null,
                    'method' => $method,
                    'provider_key' => $providerKey,
                    'amount_cents' => $paidNowCents,
                    'reference' => $reference,
                    'received_at_ms' => $nowMs,
                    'created_at_ms' => $nowMs,
                    'server_seq' => $maxSeq + 1,
                ]);

                Change::withoutGlobalScope(ShopScope::class)->create([
                    'shop_id' => $shopId,
                    'device_id' => null,
                    'entity' => 'payment',
                    'entity_id' => $payId,
                ]);
            }

            return [
                'sale' => $sale,
                'receipt_lines' => $receiptLines,
            ];
        });

        $totalFormatted = number_format($totalCents / 100, 2) . ' ETB';
        $paidFormatted = number_format($paidNowCents / 100, 2) . ' ETB';
        $dubeCents = max(0, $totalCents - $paidNowCents);
        $dubeFormatted = number_format($dubeCents / 100, 2) . ' ETB';

        $methodLabel = match ($method) {
            'cash' => $locale === 'en' ? 'Cash' : 'ጥሬ ገንዘብ',
            'bank' => $locale === 'en' ? 'Bank' : 'ባንክ',
            'wallet' => $locale === 'en' ? 'Wallet' : 'ዋሌት',
            default => ucfirst($method),
        };

        $badge = $dubeCents === 0
            ? "🟢 <b>PAID ({$methodLabel})</b>"
            : ($paidNowCents > 0 ? "🟡 <b>PARTIAL DUBE ({$paidFormatted} paid via {$methodLabel}, {$dubeFormatted} due)</b>" : "🔴 <b>DUBE (UNPAID: {$dubeFormatted})</b>");

        $receiptText = "🎉 <b>" . ($locale === 'en' ? 'Sale Completed!' : 'ሽያጭ ተጠናቋል!') . "</b>\n"
            . "Status: {$badge}\n\n"
            . $receipt['receipt_lines'] . "\n"
            . "💰 <b>" . ($locale === 'en' ? 'Grand Total' : 'ጠቅላላ ድምር') . ": {$totalFormatted}</b>\n";

        if ($isDube) {
            $cust = Customer::withoutGlobalScope(ShopScope::class)->find($customerId);
            $receiptText .= "👤 " . ($locale === 'en' ? 'Customer' : 'ደንበኛ') . ": <b>{$cust?->name}</b>\n"
                . "📅 " . ($locale === 'en' ? 'Due Date' : 'የመክፈያ ቀን') . ": <b>{$dueDate}</b>\n";
        }

        if (!empty($reference)) {
            $receiptText .= "📝 Reference: <code>{$reference}</code>\n";
        }

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => __('btn_next_sale'), 'callback_data' => 'sale:start:none'],
                ['text' => __('btn_undo'), 'callback_data' => 'undo:last:0'],
            ],
        ]);

        if ($messageId !== null) {
            $this->telegram->editMessageText($chatId, $messageId, $receiptText, [
                'reply_markup' => $buttons,
            ]);
        } else {
            $this->telegram->sendMessage($chatId, $receiptText, [
                'reply_markup' => $buttons,
            ]);
        }
    }
}
