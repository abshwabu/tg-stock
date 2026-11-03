<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\Movement;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\ActivityLogger;
use App\Services\LedgerService;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Illuminate\Support\Str;

class AdjustHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected BotStateService $botState,
        protected LedgerService $ledger
    ) {}

    /**
     * Start Adjust flow (Owner only).
     */
    public function start(int|string $chatId, string $productId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $shop = Shop::find($user?->shop_id);
        $canAdjust = ($user?->role === 'owner') || ($user?->role === 'staff' && (bool) ($shop?->staff_can_adjust));

        if (!$canAdjust) {
            $this->telegram->sendMessage($chatId, __('permission_denied'));
            return;
        }

        $shopId = (string) $user->shop_id;
        $product = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('id', $productId)
            ->first();

        if ($product === null) {
            $this->telegram->sendMessage($chatId, 'Product not found.');
            return;
        }

        $currentStockMilli = $this->ledger->stockOf($product);
        $currentFormatted = LedgerService::formatQty($currentStockMilli);

        $this->botState->start($chatId, 'adjust', 'await_qty', [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit' => $product->unit,
            'current_stock_milli' => $currentStockMilli,
        ]);

        $prompt = __('adjust_ask_qty', [
            'product' => $product->name,
            'stock' => $currentFormatted,
            'unit' => $product->unit,
        ]);

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => TelegramUi::cancelKeyboard($locale),
        ]);
    }

    /**
     * Handle input of actual physical counted quantity.
     */
    public function handleCountedQty(int|string $chatId, float $counted, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get($chatId);
        if ($state === null || $state->flow !== 'adjust') {
            return;
        }

        if ($counted < 0) {
            $this->telegram->sendMessage($chatId, __('invalid_number'));
            return;
        }

        $data = $state->data;
        $countedMilli = LedgerService::parseQty((string) $counted);
        $currentMilli = (int) $data['current_stock_milli'];
        $diffMilli = $countedMilli - $currentMilli;

        $this->botState->next($chatId, 'await_reason', [
            'counted_milli' => $countedMilli,
            'diff_milli' => $diffMilli,
        ]);

        $diffFormatted = ($diffMilli > 0 ? '+' : '') . LedgerService::formatQty($diffMilli);
        $prompt = "🔧 " . ($locale === 'en' ? 'Adjustment difference' : 'የስቶክ ልዩነት') . ": <b>{$diffFormatted} {$data['unit']}</b>\n\n" . __('adjust_ask_reason');

        $reasonButtons = TelegramUi::inlineKeyboard([
            [
                ['text' => __('adjust_reason_damaged'), 'callback_data' => 'adj:reason:damaged'],
                ['text' => __('adjust_reason_lost'), 'callback_data' => 'adj:reason:lost'],
            ],
            [
                ['text' => __('adjust_reason_count'), 'callback_data' => 'adj:reason:count'],
                ['text' => __('adjust_reason_other'), 'callback_data' => 'adj:reason:other'],
            ],
            [
                ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'adj:cancel:0'],
            ],
        ]);

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => $reasonButtons,
        ]);
    }

    /**
     * Finalize stock adjustment with chosen reason.
     */
    public function finalize(string $callbackId, int|string $chatId, int $messageId, string $reasonCode, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $shop = Shop::find($user?->shop_id);
        $canAdjust = ($user?->role === 'owner') || ($user?->role === 'staff' && (bool) ($shop?->staff_can_adjust));

        if (!$canAdjust) {
            $this->telegram->answerCallbackQuery($callbackId, __('permission_denied'), showAlert: true);
            return;
        }

        $state = $this->botState->get($chatId);
        if ($state === null || $state->flow !== 'adjust' || $state->step !== 'await_reason') {
            $this->telegram->answerCallbackQuery($callbackId, 'Adjustment already recorded.', showAlert: true);
            return;
        }

        // Clear state immediately to prevent duplicate callback tap
        $this->botState->clear($chatId);
        $this->telegram->answerCallbackQuery($callbackId);

        $data = $state->data;
        $shopId = (string) $user?->shop_id;
        $productId = (string) $data['product_id'];

        $product = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('id', $productId)
            ->first();

        if ($product === null) {
            $this->telegram->sendMessage($chatId, 'Product not found.');
            return;
        }

        $diffMilli = (int) $data['diff_milli'];
        $reasons = [
            'damaged' => __('adjust_reason_damaged'),
            'lost' => __('adjust_reason_lost'),
            'count' => __('adjust_reason_count'),
            'other' => __('adjust_reason_other'),
        ];
        $reasonText = $reasons[$reasonCode] ?? 'Adjustment';

        $nowMs = (int) (microtime(true) * 1000);

        $movement = new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $shopId,
            'product_id' => $product->id,
            'user_id' => $user?->id,
            'device_id' => null, // bot write
            'type' => 'adjust',
            'qty_milli' => $diffMilli,
            'unit_price_cents' => $product->cost_price_cents,
            'note' => $reasonText,
            'created_at_ms' => $nowMs,
        ]);

        $this->ledger->record($movement);
        $newStockMilli = $this->ledger->stockOf($product);

        $diffFormatted = ($diffMilli > 0 ? '+' : '') . LedgerService::formatQty($diffMilli);

        \App\Services\ActivityLogger::log(
            $shopId,
            $user?->id,
            'stock.adjust',
            "Adjusted {$product->name} by {$diffFormatted} {$product->unit} (Reason: {$reasonText})"
        );

        $msg = __('adjust_success', [
            'product' => $product->name,
            'diff' => $diffFormatted,
            'unit' => $product->unit,
            'reason' => $reasonText,
            'new_stock' => LedgerService::formatQty($newStockMilli),
        ]);

        $buttons = TelegramUi::inlineKeyboard([
            [
                ['text' => __('btn_undo'), 'callback_data' => 'undo:last:0'],
                ['text' => '◀️ ' . ($locale === 'en' ? 'Back' : 'ተመለስ'), 'callback_data' => 'prod:page:1'],
            ],
        ]);

        $this->telegram->editMessageText($chatId, $messageId, $msg, [
            'reply_markup' => $buttons,
        ]);
    }
}
