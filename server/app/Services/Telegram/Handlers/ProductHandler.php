<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\Change;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\LedgerService;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Illuminate\Support\Str;

class ProductHandler
{
    public const PER_PAGE = 5;

    public function __construct(
        protected TelegramClient $telegram,
        protected BotStateService $botState,
        protected LedgerService $ledger
    ) {}

    /**
     * Display paginated list of products for the shop.
     */
    public function handleList(int|string $chatId, ?User $user, int $page = 1): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || empty($user->shop_id)) {
            $this->telegram->sendMessage($chatId, __('unknown_command'));
            return;
        }

        $shopId = (string) $user->shop_id;
        $shop = Shop::find($shopId);

        $total = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereNull('deleted_at_ms')
            ->count();

        if ($total === 0) {
            $buttons = [];
            if ($user->role === 'owner') {
                $buttons[] = [['text' => __('btn_add_product'), 'callback_data' => 'prod:add:start']];
            }
            $this->telegram->sendMessage(
                $chatId,
                __('products_empty'),
                ['reply_markup' => TelegramUi::inlineKeyboard($buttons)]
            );
            return;
        }

        $totalPages = (int) ceil($total / self::PER_PAGE);
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * self::PER_PAGE;

        $products = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereNull('deleted_at_ms')
            ->orderBy('name', 'asc')
            ->skip($offset)
            ->take(self::PER_PAGE)
            ->get();

        $rows = [];
        $lowThreshold = $shop?->low_stock_default_milli ?? 5000;

        foreach ($products as $prod) {
            $stockMilli = $this->ledger->stockOf($prod);
            $stockQty = LedgerService::formatQty($stockMilli);
            $lowMark = $stockMilli <= ($prod->low_stock_at_milli ?? $lowThreshold) ? '🔴 ' : '🟢 ';
            $priceEtb = number_format($prod->sell_price_cents / 100, 2);

            $rows[] = [
                [
                    'text' => "{$lowMark}{$prod->name} — {$stockQty} {$prod->unit} ({$priceEtb} ETB)",
                    'callback_data' => "prod:view:{$prod->id}",
                ],
            ];
        }

        // Action row: Add Product (owner only)
        if ($user->role === 'owner') {
            $rows[] = [
                ['text' => __('btn_add_product'), 'callback_data' => 'prod:add:start'],
            ];
        }

        // Pagination row
        if ($totalPages > 1) {
            $pagRow = [];
            if ($page > 1) {
                $prev = $page - 1;
                $pagRow[] = ['text' => '◀️ ' . ($locale === 'en' ? 'Prev' : 'ወደኋላ'), 'callback_data' => "prod:page:{$prev}"];
            }
            $pagRow[] = ['text' => "{$page} / {$totalPages}", 'callback_data' => 'noop:none:0'];
            if ($page < $totalPages) {
                $next = $page + 1;
                $pagRow[] = ['text' => ($locale === 'en' ? 'Next' : 'ወደፊት') . ' ▶️', 'callback_data' => "prod:page:{$next}"];
            }
            $rows[] = $pagRow;
        }

        $text = __('products_title', ['total' => $total]);
        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($rows),
        ]);
    }

    /**
     * Display a detailed Product Card.
     */
    public function handleView(string $callbackId, int|string $chatId, int $messageId, string $productId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $shopId = (string) $user?->shop_id;
        /** @var Product|null $product */
        $product = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('id', $productId)
            ->whereNull('deleted_at_ms')
            ->first();

        if ($product === null) {
            $this->telegram->answerCallbackQuery($callbackId, 'Product not found', showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $stockMilli = $this->ledger->stockOf($product);
        $stockFormatted = LedgerService::formatQty($stockMilli);
        $shop = Shop::find($shopId);
        $threshold = $product->low_stock_at_milli ?? $shop?->low_stock_default_milli ?? 5000;
        $isLow = $stockMilli <= $threshold;
        $badge = $isLow ? __('stock_badge_low') : __('stock_badge_ok');

        $cardText = __('product_card', [
            'name' => $product->name,
            'sell_price' => number_format($product->sell_price_cents / 100, 2),
            'cost_price' => number_format($product->cost_price_cents / 100, 2),
            'stock' => $stockFormatted,
            'unit' => $product->unit,
            'badge' => $badge,
            'sku' => $product->sku ?? '—',
        ]);

        $buttons = [
            [
                ['text' => __('btn_stock_in'), 'callback_data' => "stockin:start:{$product->id}"],
                ['text' => __('btn_sale'), 'callback_data' => "sale:pick:{$product->id}"],
            ],
        ];

        // Owner only actions
        if ($user?->role === 'owner') {
            $buttons[] = [
                ['text' => __('btn_adjust'), 'callback_data' => "adj:start:{$product->id}"],
                ['text' => __('btn_delete'), 'callback_data' => "prod:del:{$product->id}"],
            ];
        }

        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back to List' : 'ወደ ዝርዝር ተመለስ'), 'callback_data' => 'prod:page:1'],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $cardText, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Search products by plain text query (name or sku).
     */
    public function handleSearch(int|string $chatId, string $query, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $shopId = (string) $user?->shop_id;
        $trimmed = trim($query);

        $matches = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereNull('deleted_at_ms')
            ->where(function ($q) use ($trimmed): void {
                $q->where('name', 'LIKE', "%{$trimmed}%")
                    ->orWhere('sku', 'LIKE', "%{$trimmed}%");
            })
            ->take(8)
            ->get();

        if ($matches->isEmpty()) {
            $buttons = [];
            if ($user?->role === 'owner') {
                $buttons[] = [
                    ['text' => __('btn_add_product'), 'callback_data' => 'prod:add:start'],
                ];
            }
            $this->telegram->sendMessage(
                $chatId,
                __('search_no_results', ['query' => htmlspecialchars($trimmed, ENT_QUOTES)]),
                ['reply_markup' => TelegramUi::inlineKeyboard($buttons)]
            );
            return;
        }

        $rows = [];
        foreach ($matches as $prod) {
            $stockMilli = $this->ledger->stockOf($prod);
            $stockQty = LedgerService::formatQty($stockMilli);
            $priceEtb = number_format($prod->sell_price_cents / 100, 2);

            $rows[] = [
                [
                    'text' => "{$prod->name} — {$stockQty} {$prod->unit} ({$priceEtb} ETB)",
                    'callback_data' => "prod:view:{$prod->id}",
                ],
            ];
        }

        $text = "🔍 <b>" . ($locale === 'en' ? 'Search Results for' : 'የፍለጋ ውጤቶች ለ') . " \"{$trimmed}\":</b>";
        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($rows),
        ]);
    }

    /**
     * Initiate Add Product step-by-step flow (Owner only).
     */
    public function startAddProduct(int|string $chatId, ?User $user, ?string $prefilledName = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->sendMessage($chatId, __('permission_denied'));
            return;
        }

        if (!empty($prefilledName)) {
            $this->botState->start($chatId, 'add_product', 'sell_price', [
                'name' => trim($prefilledName),
                'language' => $locale,
            ]);
            $this->telegram->sendMessage($chatId, __('add_product_sell_price'), [
                'reply_markup' => TelegramUi::cancelKeyboard($locale),
            ]);
            return;
        }

        $this->botState->start($chatId, 'add_product', 'name', [
            'language' => $locale,
        ]);

        $this->telegram->sendMessage($chatId, __('add_product_name'), [
            'reply_markup' => TelegramUi::cancelKeyboard($locale),
        ]);
    }

    /**
     * Handle input during Add Product conversation flow.
     */
    public function handleAddProductStep(int|string $chatId, string $step, string $text, array $data, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($step === 'name') {
            $name = trim($text);
            if (mb_strlen($name) < 2) {
                $this->telegram->sendMessage($chatId, __('add_product_name'), [
                    'reply_markup' => TelegramUi::cancelKeyboard($locale),
                ]);
                return;
            }

            $this->botState->next($chatId, 'sell_price', ['name' => $name]);
            $this->telegram->sendMessage($chatId, __('add_product_sell_price'), [
                'reply_markup' => TelegramUi::cancelKeyboard($locale),
            ]);
            return;
        }

        if ($step === 'sell_price') {
            $price = TelegramUi::parseNumber($text);
            if ($price === null || $price < 0) {
                $this->telegram->sendMessage($chatId, __('invalid_number'), [
                    'reply_markup' => TelegramUi::cancelKeyboard($locale),
                ]);
                return;
            }

            $priceCents = (int) round($price * 100);
            $this->botState->next($chatId, 'opening_stock', ['sell_price_cents' => $priceCents]);

            $skipBtn = TelegramUi::inlineKeyboard([
                [['text' => __('btn_skip'), 'callback_data' => 'prod:add:skip_opening']],
            ]);

            $this->telegram->sendMessage($chatId, __('add_product_opening_stock'), [
                'reply_markup' => $skipBtn,
            ]);
            return;
        }

        if ($step === 'opening_stock') {
            $qty = TelegramUi::parseNumber($text);
            if ($qty === null || $qty < 0) {
                $this->telegram->sendMessage($chatId, __('invalid_number'));
                return;
            }

            $qtyMilli = LedgerService::parseQty((string) $qty);
            $this->botState->next($chatId, 'unit', ['opening_stock_milli' => $qtyMilli]);
            $this->promptUnitSelection($chatId, $locale);
            return;
        }

        if ($step === 'cost_price') {
            $cost = TelegramUi::parseNumber($text);
            $costCents = $cost !== null ? (int) round($cost * 100) : 0;
            $this->finalizeProductCreation($chatId, $costCents, $data, $user);
        }
    }

    /**
     * Show Unit selection inline buttons.
     */
    public function promptUnitSelection(int|string $chatId, string $locale): void
    {
        $keyboard = TelegramUi::inlineKeyboard([
            [
                ['text' => 'pcs (ፍሬ)', 'callback_data' => 'prod:add:unit:pcs'],
                ['text' => 'kg (ኪሎ)', 'callback_data' => 'prod:add:unit:kg'],
                ['text' => 'liter (ሊትር)', 'callback_data' => 'prod:add:unit:liter'],
            ],
            [
                ['text' => 'pack (ፓኬት)', 'callback_data' => 'prod:add:unit:pack'],
                ['text' => 'box (ካርቶን)', 'callback_data' => 'prod:add:unit:box'],
                ['text' => 'bag (ጆንያ)', 'callback_data' => 'prod:add:unit:bag'],
            ],
        ]);

        $this->telegram->sendMessage($chatId, __('add_product_unit'), [
            'reply_markup' => $keyboard,
        ]);
    }

    /**
     * Finalize product creation and log to changes table.
     */
    public function finalizeProductCreation(int|string $chatId, int $costCents, array $data, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $shopId = (string) $user->shop_id;
        $nowMs = (int) (microtime(true) * 1000);
        $productId = (string) Str::uuid7();

        $product = Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => $productId,
            'shop_id' => $shopId,
            'name' => $data['name'],
            'unit' => $data['unit'] ?? 'pcs',
            'sell_price_cents' => (int) ($data['sell_price_cents'] ?? 0),
            'cost_price_cents' => $costCents,
            'is_active' => true,
            'updated_at_ms' => $nowMs,
        ]);

        // Stamp change for mobile sync
        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $shopId,
            'device_id' => null,
            'entity' => 'product',
            'entity_id' => $product->id,
        ]);

        // Record opening stock if > 0
        $openingStockMilli = (int) ($data['opening_stock_milli'] ?? 0);
        if ($openingStockMilli > 0) {
            $movement = new Movement([
                'id' => (string) Str::uuid7(),
                'shop_id' => $shopId,
                'product_id' => $product->id,
                'user_id' => $user->id,
                'device_id' => null,
                'type' => 'in',
                'qty_milli' => $openingStockMilli,
                'unit_price_cents' => $costCents,
                'note' => 'Opening Stock',
                'created_at_ms' => $nowMs,
            ]);
            $this->ledger->record($movement);
        }

        $this->botState->clear($chatId);

        $this->telegram->sendMessage(
            $chatId,
            __('add_product_success', ['name' => $product->name]),
            ['reply_markup' => TelegramUi::mainMenuKeyboard($locale)]
        );

        $this->handleList($chatId, $user, 1);
    }

    /**
     * Offer Quick-Add prompt when user types e.g. "Sugar 120 50".
     */
    public function offerQuickAdd(int|string $chatId, string $name, float $sellPrice, float $stock, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $stateKey = substr(md5("{$name}_{$sellPrice}_{$stock}"), 0, 8);
        $this->botState->start($chatId, 'quick_add', 'confirm', [
            'key' => $stateKey,
            'name' => $name,
            'sell_price' => $sellPrice,
            'stock' => $stock,
        ]);

        $text = __('quick_add_offer', [
            'name' => htmlspecialchars($name, ENT_QUOTES),
            'sell_price' => number_format($sellPrice, 2),
            'stock' => $stock,
        ]);

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => __('btn_quick_create', ['name' => $name]), 'callback_data' => "qadd:create:{$stateKey}"],
                ['text' => __('btn_just_search'), 'callback_data' => "qadd:search:{$stateKey}"],
            ],
        ]);

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Confirm Quick-Add creation in one tap.
     */
    public function executeQuickAdd(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $state = $this->botState->get($chatId);
        if ($state === null || $state->flow !== 'quick_add') {
            $this->telegram->answerCallbackQuery($callbackId, 'Expired', showAlert: true);
            return;
        }

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $data = $state->data;
        $name = (string) $data['name'];
        $sellPriceCents = (int) round(((float) $data['sell_price']) * 100);
        $openingMilli = LedgerService::parseQty((string) $data['stock']);

        $productData = [
            'name' => $name,
            'sell_price_cents' => $sellPriceCents,
            'opening_stock_milli' => $openingMilli,
            'unit' => 'pcs',
        ];

        $this->finalizeProductCreation($chatId, 0, $productData, $user);
    }

    /**
     * Initiate Delete Product confirmation prompt (Owner only).
     */
    public function handleDelete(string $callbackId, int|string $chatId, int $messageId, string $productId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $product = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $productId)
            ->first();

        if ($product === null) {
            $this->telegram->answerCallbackQuery($callbackId, 'Product not found');
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        $prompt = __('delete_confirm', ['product' => $product->name]);
        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => __('btn_confirm_delete'), 'callback_data' => "prod:confirm_del:{$product->id}"],
                ['text' => __('btn_skip'), 'callback_data' => "prod:view:{$product->id}"],
            ],
        ]);

        $this->telegram->editMessageText($chatId, $messageId, $prompt, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Soft-delete product.
     */
    public function confirmDelete(string $callbackId, int|string $chatId, int $messageId, string $productId, ?User $user): void
    {
        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $product = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $user->shop_id)
            ->where('id', $productId)
            ->first();

        if ($product === null) {
            $this->telegram->answerCallbackQuery($callbackId, 'Product not found');
            return;
        }

        $nowMs = (int) (microtime(true) * 1000);
        $product->deleted_at_ms = $nowMs;
        $product->is_active = false;
        $product->updated_at_ms = $nowMs;
        $product->save();

        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $user->shop_id,
            'device_id' => null,
            'entity' => 'product',
            'entity_id' => $product->id,
        ]);

        $this->telegram->answerCallbackQuery($callbackId, __('delete_success', ['product' => $product->name]));
        $this->telegram->editMessageText($chatId, $messageId, __('delete_success', ['product' => $product->name]));
    }
}
