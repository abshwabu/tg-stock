<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\Movement;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\LedgerService;
use App\Services\PermissionService;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Illuminate\Support\Str;

class SaleHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected BotStateService $botState,
        protected LedgerService $ledger,
        protected PermissionService $permissionService,
        protected LocationHandler $locationHandler
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
                $priceEtb = number_format($p->sell_price_cents / 100, 0);
                $currentRow[] = [
                    'text' => "{$p->name} ({$priceEtb} ETB)",
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
        $stockFormatted = LedgerService::formatQty($stockMilli);
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

        // Check if negative stock is disallowed
        if (!$shop?->allow_negative_stock) {
            $curLoc = $this->locationHandler->getCurrentLocation($user);
            $currentStockMilli = $curLoc ? $this->ledger->stockOf($product, $curLoc) : $this->ledger->stockOf($product);

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

        $itemTotalCents = (int) round(($neededMilli / 1000) * $product->sell_price_cents);
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

        $this->showCartSummary($chatId, $cart, $locale);
    }

    /**
     * Display current cart and action buttons.
     */
    public function showCartSummary(int|string $chatId, array $cart, string $locale): void
    {
        $itemsText = '';
        $totalCents = 0;

        foreach ($cart as $idx => $item) {
            $num = $idx + 1;
            $linePrice = number_format($item['total_cents'] / 100, 2);
            $itemsText .= "{$num}. {$item['name']} × {$item['qty_formatted']} {$item['unit']} = <b>{$linePrice} ETB</b>\n";
            $totalCents += (int) $item['total_cents'];
        }

        $totalFormatted = number_format($totalCents / 100, 2);

        $text = __('sale_cart_title', [
            'items' => $itemsText,
            'total' => $totalFormatted,
        ]);

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => __('btn_finish_sale', ['total' => $totalFormatted]), 'callback_data' => 'sale:finish:0'],
            ],
            [
                ['text' => __('btn_add_more'), 'callback_data' => 'sale:start:none'],
                ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'sale:cancel:0'],
            ],
        ]);

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => $buttons,
        ]);
    }

    /**
     * Complete the sale, record ledger movements, and show receipt.
     */
    public function finishSale(string $callbackId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get($chatId);
        // Duplicate tap protection: only process if state exists in cart step
        if ($state === null || $state->flow !== 'sale' || $state->step !== 'cart') {
            $this->telegram->answerCallbackQuery($callbackId, 'Sale already processed.', showAlert: true);
            return;
        }

        $cart = $state->data['cart'] ?? [];
        if (empty($cart)) {
            $this->telegram->answerCallbackQuery($callbackId, 'Cart is empty.');
            return;
        }

        // Clear state immediately to lock against duplicate taps
        $this->botState->clear($chatId);
        $this->telegram->answerCallbackQuery($callbackId);

        $shopId = (string) $user?->shop_id;
        $nowMs = (int) (microtime(true) * 1000);
        $totalCents = 0;
        $receiptLines = '';
        $curLoc = $this->locationHandler->getCurrentLocation($user);

        foreach ($cart as $idx => $item) {
            $num = $idx + 1;
            $linePrice = number_format($item['total_cents'] / 100, 2);
            $receiptLines .= "{$num}. {$item['name']} × {$item['qty_formatted']} {$item['unit']} = <b>{$linePrice} ETB</b>\n";
            $totalCents += (int) $item['total_cents'];

            // Record sale movement (signed negative quantity)
            $movement = new Movement([
                'id' => (string) Str::uuid7(),
                'shop_id' => $shopId,
                'product_id' => $item['product_id'],
                'location_id' => $curLoc?->id,
                'user_id' => $user?->id,
                'device_id' => null, // bot write
                'type' => 'sale',
                'qty_milli' => -((int) $item['qty_milli']),
                'unit_price_cents' => (int) $item['unit_price_cents'],
                'note' => 'Telegram Sale',
                'created_at_ms' => $nowMs,
            ]);

            $this->ledger->record($movement);
        }

        $totalFormatted = number_format($totalCents / 100, 2);

        $receiptText = __('sale_completed', [
            'items' => $receiptLines,
            'total' => $totalFormatted,
        ]);

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => __('btn_next_sale'), 'callback_data' => 'sale:start:none'],
                ['text' => __('btn_undo'), 'callback_data' => 'undo:last:0'],
            ],
        ]);

        $this->telegram->editMessageText($chatId, $messageId, $receiptText, [
            'reply_markup' => $buttons,
        ]);
    }
}
