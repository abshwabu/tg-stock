<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\Movement;
use App\Models\Product;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\LedgerService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Illuminate\Support\Str;

class UndoHandler
{
    public const MAX_UNDO_AGE_MS = 600000; // 10 minutes

    public function __construct(
        protected TelegramClient $telegram,
        protected LedgerService $ledger
    ) {}

    /**
     * Revert user's own last action within 10 minutes via a compensating movement.
     */
    public function handleUndo(int|string $chatId, ?User $user, ?string $callbackId = null): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user === null || empty($user->shop_id)) {
            if ($callbackId) {
                $this->telegram->answerCallbackQuery($callbackId);
            }
            return;
        }

        $shopId = (string) $user->shop_id;

        /** @var Movement|null $last */
        $last = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('user_id', $user->id)
            ->orderByDesc('created_at_ms')
            ->orderByDesc('server_seq')
            ->first();

        if ($last === null) {
            if ($callbackId) {
                $this->telegram->answerCallbackQuery($callbackId, __('undo_no_action'), showAlert: true);
            } else {
                $this->telegram->sendMessage($chatId, __('undo_no_action'));
            }
            return;
        }

        // Check if last movement is itself an undo action
        if (str_starts_with($last->note ?? '', 'Compensating undo for')) {
            if ($callbackId) {
                $this->telegram->answerCallbackQuery($callbackId, __('undo_already_done'), showAlert: true);
            } else {
                $this->telegram->sendMessage($chatId, __('undo_already_done'));
            }
            return;
        }

        // Check if movement was already undone by a subsequent action
        $alreadyUndone = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('note', 'like', "%Compensating undo for [{$last->id}]%")
            ->exists();

        if ($alreadyUndone) {
            if ($callbackId) {
                $this->telegram->answerCallbackQuery($callbackId, __('undo_already_done'), showAlert: true);
            } else {
                $this->telegram->sendMessage($chatId, __('undo_already_done'));
            }
            return;
        }

        // Verify action is within 10 minutes
        $nowMs = (int) (microtime(true) * 1000);
        if (($nowMs - $last->created_at_ms) > self::MAX_UNDO_AGE_MS) {
            if ($callbackId) {
                $this->telegram->answerCallbackQuery($callbackId, __('undo_too_old'), showAlert: true);
            } else {
                $this->telegram->sendMessage($chatId, __('undo_too_old'));
            }
            return;
        }

        if ($callbackId) {
            $this->telegram->answerCallbackQuery($callbackId);
        }

        /** @var Product|null $product */
        $product = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('id', $last->product_id)
            ->first();

        $productName = $product?->name ?? 'Product';
        $productUnit = $product?->unit ?? 'pcs';

        // Create compensating movement (exact opposite signed quantity)
        $compensatingMovement = new Movement([
            'id' => (string) Str::uuid7(),
            'shop_id' => $shopId,
            'product_id' => $last->product_id,
            'user_id' => $user->id,
            'device_id' => null, // bot write
            'type' => 'adjust',
            'qty_milli' => -$last->qty_milli,
            'unit_price_cents' => $last->unit_price_cents,
            'note' => "Compensating undo for [{$last->id}] ({$last->type})",
            'created_at_ms' => $nowMs,
        ]);

        $this->ledger->record($compensatingMovement);

        $newStockMilli = $product !== null ? $this->ledger->stockOf($product) : 0;
        $revertedQty = LedgerService::formatQty(abs($last->qty_milli));

        $msg = __('undo_success', [
            'type' => $last->type,
            'product' => $productName,
            'qty' => $revertedQty,
            'unit' => $productUnit,
            'new_stock' => LedgerService::formatQty($newStockMilli),
        ]);

        $this->telegram->sendMessage($chatId, $msg, [
            'reply_markup' => TelegramUi::mainMenuKeyboard($locale),
        ]);
    }
}
