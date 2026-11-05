<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\Movement;
use App\Models\Product;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\LedgerService;
use App\Services\PermissionService;
use App\Services\Telegram\BotStateService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Illuminate\Support\Str;

class StockInHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected BotStateService $botState,
        protected LedgerService $ledger,
        protected PermissionService $permissionService,
        protected LocationHandler $locationHandler
    ) {}

    /**
     * Start Stock In flow. If productId is null, prompts to pick a product.
     */
    public function start(int|string $chatId, ?string $productId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $curLoc = $this->locationHandler->getCurrentLocation($user);

        if (!$this->permissionService->can($user, 'stock.in', $curLoc)) {
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
            foreach ($products as $p) {
                $rows[] = [
                    ['text' => "📦 {$p->name}", 'callback_data' => "stockin:start:{$p->id}"],
                ];
            }

            $this->telegram->sendMessage($chatId, __('menu_stock_in') . ' — ' . __('sale_choose_product'), [
                'reply_markup' => TelegramUi::inlineKeyboard($rows),
            ]);
            return;
        }

        $product = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('id', $productId)
            ->first();

        if ($product === null) {
            $this->telegram->sendMessage($chatId, 'Product not found.');
            return;
        }

        if (!$product->isStockable()) {
            $msg = $locale === 'en' ? '⚠️ Cannot restock a non-stockable item (service or consumable).' : '⚠️ ስቶክ የሌለው ዕቃ መመዝገብ አይቻልም።';
            $this->telegram->sendMessage($chatId, $msg);
            return;
        }

        $currentStock = LedgerService::formatQty($this->ledger->stockOf($product));

        $this->botState->start($chatId, 'stock_in', 'await_qty', [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit' => $product->unit,
            'current_stock' => $currentStock,
        ]);

        $prompt = __('stock_in_ask_qty', [
            'product' => $product->name,
            'stock' => $currentStock,
            'unit' => $product->unit,
        ]);

        $quickButtons = TelegramUi::inlineKeyboard([
            [
                ['text' => '+10', 'callback_data' => 'stockin:qty:10'],
                ['text' => '+20', 'callback_data' => 'stockin:qty:20'],
                ['text' => '+50', 'callback_data' => 'stockin:qty:50'],
                ['text' => '+100', 'callback_data' => 'stockin:qty:100'],
            ],
            [
                ['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'stockin:cancel:0'],
            ],
        ]);

        $this->telegram->sendMessage($chatId, $prompt, [
            'reply_markup' => $quickButtons,
        ]);
    }

    /**
     * Handle quantity selected via inline button or text.
     */
    public function handleQty(int|string $chatId, float $qty, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $state = $this->botState->get($chatId);
        if ($state === null || $state->flow !== 'stock_in') {
            return;
        }

        if ($qty <= 0) {
            $this->telegram->sendMessage($chatId, __('invalid_number'));
            return;
        }

        $qtyMilli = LedgerService::parseQty((string) $qty);
        $this->botState->next($chatId, 'await_cost', ['qty_milli' => $qtyMilli]);

        $skipBtn = TelegramUi::inlineKeyboard([
            [['text' => __('btn_skip'), 'callback_data' => 'stockin:skip_cost:0']],
            [['text' => '❌ ' . ($locale === 'en' ? 'Cancel' : 'ሰርዝ'), 'callback_data' => 'stockin:cancel:0']],
        ]);

        $this->telegram->sendMessage($chatId, __('stock_in_ask_cost'), [
            'reply_markup' => $skipBtn,
        ]);
    }

    /**
     * Finalize Stock In recording.
     */
    public function finalize(int|string $chatId, ?int $costCents, array $data, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $shopId = (string) $user?->shop_id;
        $productId = (string) $data['product_id'];

        $product = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('id', $productId)
            ->first();

        if ($product === null) {
            $this->botState->clear($chatId);
            $this->telegram->sendMessage($chatId, 'Product not found.');
            return;
        }

        if ($costCents !== null && $costCents > 0) {
            $product->cost_price_cents = $costCents;
            $product->updated_at_ms = (int) (microtime(true) * 1000);
            $product->save();
        }

        $nowMs = (int) (microtime(true) * 1000);
        $qtyMilli = (int) $data['qty_milli'];
        $curLoc = $this->locationHandler->getCurrentLocation($user);

        $movement = new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $shopId,
            'product_id' => $product->id,
            'location_id' => $curLoc?->id,
            'user_id' => $user?->id,
            'device_id' => null,
            'type' => 'in',
            'qty_milli' => $qtyMilli,
            'unit_price_cents' => $costCents ?? $product->cost_price_cents,
            'note' => 'Bot Stock In',
            'created_at_ms' => $nowMs,
        ]);

        $this->ledger->record($movement);
        $newStockMilli = $this->ledger->stockOf($product);

        $this->botState->clear($chatId);

        $successText = __('stock_in_success', [
            'product' => $product->name,
            'added' => LedgerService::formatQty($qtyMilli),
            'unit' => $product->unit,
            'new_stock' => LedgerService::formatQty($newStockMilli),
        ]);

        $actionButtons = TelegramUi::inlineKeyboard([
            [
                ['text' => __('btn_stock_in'), 'callback_data' => 'stockin:start:none'],
                ['text' => __('btn_undo'), 'callback_data' => 'undo:last:0'],
            ],
        ]);

        $this->telegram->sendMessage($chatId, $successText, [
            'reply_markup' => $actionButtons,
        ]);
    }
}
