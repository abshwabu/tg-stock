<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\BotState;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\Telegram\Handlers\AdjustHandler;
use App\Services\Telegram\Handlers\MenuHandler;
use App\Services\Telegram\Handlers\PairingHandler;
use App\Services\Telegram\Handlers\ProductHandler;
use App\Services\Telegram\Handlers\SaleHandler;
use App\Services\Telegram\Handlers\SettingsHandler;
use App\Services\Telegram\Handlers\StartHandler;
use App\Services\Telegram\Handlers\StockInHandler;
use App\Services\Telegram\Handlers\UndoHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class TelegramRouter
{
    public function __construct(
        protected TelegramClient $telegram,
        protected BotStateService $botState,
        protected StartHandler $startHandler,
        protected PairingHandler $pairingHandler,
        protected SettingsHandler $settingsHandler,
        protected MenuHandler $menuHandler,
        protected ProductHandler $productHandler,
        protected StockInHandler $stockInHandler,
        protected SaleHandler $saleHandler,
        protected AdjustHandler $adjustHandler,
        protected UndoHandler $undoHandler
    ) {}

    /**
     * Route and process an incoming Telegram update.
     */
    public function dispatch(array $update): void
    {
        // 1. Handle callback_query (inline buttons)
        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
            return;
        }

        // 2. Handle message
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
            return;
        }

        Log::info('Telegram update received without message or callback_query', [
            'update_id' => $update['update_id'] ?? null,
        ]);
    }

    /**
     * Handle incoming callback query.
     */
    protected function handleCallbackQuery(array $cq): void
    {
        $cqId = (string) ($cq['id'] ?? '');
        $from = $cq['from'] ?? [];
        $chatId = (int) ($cq['message']['chat']['id'] ?? $from['id'] ?? 0);
        $messageId = (int) ($cq['message']['message_id'] ?? 0);
        $data = (string) ($cq['data'] ?? '');

        if (empty($chatId)) {
            $this->telegram->answerCallbackQuery($cqId);
            return;
        }

        $user = $this->resolveUser($chatId);
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        // Check rate limit per-user (30 actions / minute)
        if ($this->isRateLimited($chatId)) {
            $this->telegram->answerCallbackQuery($cqId, __('rate_limited'), showAlert: true);
            return;
        }

        // Callback data format: module:action:id (<64 bytes)
        $parts = explode(':', $data);
        $module = $parts[0] ?? '';
        $action = $parts[1] ?? '';
        $id = $parts[2] ?? '';

        // 1. Pairing
        if ($module === 'pair') {
            $this->pairingHandler->handleCallback($cqId, $chatId, $messageId, $action, $id, $from, $user);
            return;
        }

        // 2. Language & Settings
        if ($module === 'lang') {
            $this->settingsHandler->handleLanguageCallback($cqId, $chatId, $messageId, $action, $id, $user);
            return;
        }

        if ($module === 'settings') {
            if ($action === 'toggle' && $id === 'neg_stock') {
                $this->settingsHandler->handleToggleNegativeStock($cqId, $chatId, $messageId, $user);
            } else {
                $this->telegram->answerCallbackQuery($cqId);
            }
            return;
        }

        // 3. Products
        if ($module === 'prod') {
            $this->handleProductCallback($cqId, $chatId, $messageId, $action, $id, $parts, $user);
            return;
        }

        // 4. Quick Add
        if ($module === 'qadd') {
            if ($action === 'create') {
                $this->productHandler->executeQuickAdd($cqId, $chatId, $messageId, $user);
            } elseif ($action === 'search') {
                $this->telegram->answerCallbackQuery($cqId);
                $state = $this->botState->get($chatId);
                $query = $state?->data['name'] ?? '';
                $this->botState->clear($chatId);
                if (!empty($query)) {
                    $this->productHandler->handleSearch($chatId, $query, $user);
                }
            }
            return;
        }

        // 5. Stock In
        if ($module === 'stockin') {
            $this->handleStockInCallback($cqId, $chatId, $messageId, $action, $id, $user);
            return;
        }

        // 6. Sale
        if ($module === 'sale') {
            $this->handleSaleCallback($cqId, $chatId, $messageId, $action, $id, $user);
            return;
        }

        // 7. Adjust
        if ($module === 'adj') {
            $this->handleAdjustCallback($cqId, $chatId, $messageId, $action, $id, $user);
            return;
        }

        // 8. Undo
        if ($module === 'undo') {
            $this->undoHandler->handleUndo($chatId, $user, $cqId);
            return;
        }

        // Default: acknowledge callback query
        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch product callbacks.
     */
    protected function handleProductCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, array $parts, ?User $user): void
    {
        if ($action === 'page') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->productHandler->handleList($chatId, $user, (int) $id);
            return;
        }

        if ($action === 'view') {
            $this->productHandler->handleView($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'add') {
            $this->telegram->answerCallbackQuery($cqId);
            if ($id === 'start') {
                $this->productHandler->startAddProduct($chatId, $user);
            } elseif ($id === 'skip_opening') {
                $this->botState->next($chatId, 'unit', ['opening_stock_milli' => 0]);
                $this->productHandler->promptUnitSelection($chatId, $user?->language ?? 'am');
            } elseif ($id === 'unit') {
                $unit = $parts[3] ?? 'pcs';
                $this->botState->next($chatId, 'cost_price', ['unit' => $unit]);
                $skipBtn = TelegramUi::inlineKeyboard([
                    [['text' => __('btn_skip'), 'callback_data' => 'prod:add:skip_cost']],
                ]);
                $this->telegram->sendMessage($chatId, __('add_product_cost_price'), [
                    'reply_markup' => $skipBtn,
                ]);
            } elseif ($id === 'skip_cost') {
                $state = $this->botState->get($chatId);
                if ($state !== null) {
                    $this->productHandler->finalizeProductCreation($chatId, 0, $state->data, $user);
                }
            }
            return;
        }

        if ($action === 'del') {
            $this->productHandler->handleDelete($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'confirm_del') {
            $this->productHandler->confirmDelete($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch stock in callbacks.
     */
    protected function handleStockInCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, ?User $user): void
    {
        if ($action === 'start') {
            $this->telegram->answerCallbackQuery($cqId);
            $prodId = $id !== 'none' ? $id : null;
            $this->stockInHandler->start($chatId, $prodId, $user);
            return;
        }

        if ($action === 'qty') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->stockInHandler->handleQty($chatId, (float) $id, $user);
            return;
        }

        if ($action === 'skip_cost') {
            $this->telegram->answerCallbackQuery($cqId);
            $state = $this->botState->get($chatId);
            if ($state !== null) {
                $this->stockInHandler->finalize($chatId, null, $state->data, $user);
            }
            return;
        }

        if ($action === 'cancel') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->botState->clear($chatId);
            $this->telegram->sendMessage($chatId, __('action_cancelled'), [
                'reply_markup' => TelegramUi::mainMenuKeyboard($user?->language ?? 'am'),
            ]);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch sale callbacks.
     */
    protected function handleSaleCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, ?User $user): void
    {
        if ($action === 'start') {
            $this->telegram->answerCallbackQuery($cqId);
            $prodId = $id !== 'none' ? $id : null;
            $this->saleHandler->start($chatId, $prodId, $user);
            return;
        }

        if ($action === 'pick') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->saleHandler->promptQuantity($chatId, $id, $user);
            return;
        }

        if ($action === 'qty') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->saleHandler->handleQuantitySelected($chatId, (float) $id, $user);
            return;
        }

        if ($action === 'finish') {
            $this->saleHandler->finishSale($cqId, $chatId, $messageId, $user);
            return;
        }

        if ($action === 'cancel') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->botState->clear($chatId);
            $this->telegram->sendMessage($chatId, __('action_cancelled'), [
                'reply_markup' => TelegramUi::mainMenuKeyboard($user?->language ?? 'am'),
            ]);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Dispatch adjust callbacks.
     */
    protected function handleAdjustCallback(string $cqId, int $chatId, int $messageId, string $action, string $id, ?User $user): void
    {
        if ($action === 'start') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->adjustHandler->start($chatId, $id, $user);
            return;
        }

        if ($action === 'reason') {
            $this->adjustHandler->finalize($cqId, $chatId, $messageId, $id, $user);
            return;
        }

        if ($action === 'cancel') {
            $this->telegram->answerCallbackQuery($cqId);
            $this->botState->clear($chatId);
            $this->telegram->sendMessage($chatId, __('action_cancelled'), [
                'reply_markup' => TelegramUi::mainMenuKeyboard($user?->language ?? 'am'),
            ]);
            return;
        }

        $this->telegram->answerCallbackQuery($cqId);
    }

    /**
     * Handle incoming chat message.
     */
    protected function handleMessage(array $message): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $from = $message['from'] ?? [];
        $text = trim((string) ($message['text'] ?? ''));

        if (empty($chatId) || $text === '') {
            return;
        }

        $user = $this->resolveUser($chatId);
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        // Check rate limit per-user (30 requests / minute)
        if ($this->isRateLimited($chatId)) {
            $this->telegram->sendMessage($chatId, __('rate_limited'));
            return;
        }

        // Universal Cancel: "❌ Cancel", "❌ ሰርዝ", "/cancel"
        if (in_array($text, ['❌ Cancel', '❌ ሰርዝ', '/cancel'], true)) {
            $this->botState->clear($chatId);
            $this->telegram->sendMessage(
                $chatId,
                __('action_cancelled'),
                ['reply_markup' => TelegramUi::mainMenuKeyboard($locale)]
            );
            return;
        }

        // Handle /start command (including /start pair_<code>)
        if (str_starts_with($text, '/start')) {
            $cmdParts = explode(' ', $text, 2);
            $param = isset($cmdParts[1]) ? trim($cmdParts[1]) : null;
            $this->startHandler->handle($chatId, $from, $param, $user);
            return;
        }

        // Check active conversation state
        $state = $this->botState->get($chatId);
        if ($state !== null) {
            $this->handleStatefulInput($chatId, $text, $state, $user);
            return;
        }

        // Check for Quick-Add format: e.g. "Sugar 120 50" or "ጤፍ 150 200" or "Bread 25"
        if ($user?->role === 'owner' && preg_match('/^(.+?)\s+([0-9]+(?:\.[0-9]+)?)(?:\s+([0-9]+(?:\.[0-9]+)?))?$/u', $text, $matches)) {
            $nameCandidate = trim($matches[1]);
            $sellPrice = (float) $matches[2];
            $openingStock = isset($matches[3]) ? (float) $matches[3] : 0.0;

            if ($sellPrice > 0 && mb_strlen($nameCandidate) >= 2) {
                $this->productHandler->offerQuickAdd($chatId, $nameCandidate, $sellPrice, $openingStock, $user);
                return;
            }
        }

        // Fall through to menu handler (checks menu buttons and falls back to product search)
        $this->menuHandler->handle($chatId, $text, $user);
    }

    /**
     * Dispatch text input to the active conversation state.
     */
    protected function handleStatefulInput(int $chatId, string $text, BotState $state, ?User $user): void
    {
        // 1. Onboarding
        if ($state->flow === 'onboarding' && $state->step === 'await_shop_name') {
            $this->startHandler->handleShopNameInput($chatId, $text, $state->data ?? []);
            return;
        }

        // 2. Add Product
        if ($state->flow === 'add_product') {
            $this->productHandler->handleAddProductStep($chatId, $state->step, $text, $state->data ?? [], $user);
            return;
        }

        // 3. Stock In
        if ($state->flow === 'stock_in') {
            if ($state->step === 'await_qty') {
                $qty = TelegramUi::parseNumber($text);
                if ($qty !== null) {
                    $this->stockInHandler->handleQty($chatId, $qty, $user);
                } else {
                    $this->telegram->sendMessage($chatId, __('invalid_number'));
                }
                return;
            }

            if ($state->step === 'await_cost') {
                $cost = TelegramUi::parseNumber($text);
                $costCents = $cost !== null ? (int) round($cost * 100) : null;
                $this->stockInHandler->finalize($chatId, $costCents, $state->data, $user);
                return;
            }
        }

        // 4. Sale
        if ($state->flow === 'sale' && $state->step === 'await_qty') {
            $qty = TelegramUi::parseNumber($text);
            if ($qty !== null) {
                $this->saleHandler->handleQuantitySelected($chatId, $qty, $user);
            } else {
                $this->telegram->sendMessage($chatId, __('invalid_number'));
            }
            return;
        }

        // 5. Adjust
        if ($state->flow === 'adjust' && $state->step === 'await_qty') {
            $qty = TelegramUi::parseNumber($text);
            if ($qty !== null) {
                $this->adjustHandler->handleCountedQty($chatId, $qty, $user);
            } else {
                $this->telegram->sendMessage($chatId, __('invalid_number'));
            }
            return;
        }

        // Default: clear unrecognized state and pass to menu
        $this->botState->clear($chatId);
        $this->menuHandler->handle($chatId, $text, $user);
    }

    /**
     * Resolve User model by telegram_id without shop scope.
     */
    protected function resolveUser(int $telegramId): ?User
    {
        return User::withoutGlobalScope(ShopScope::class)
            ->where('telegram_id', $telegramId)
            ->first();
    }

    /**
     * Check if user exceeded rate limit (30 requests/min).
     */
    protected function isRateLimited(int $chatId): bool
    {
        $rateKey = "tg_user_rate:{$chatId}";
        if (RateLimiter::tooManyAttempts($rateKey, 30)) {
            return true;
        }

        RateLimiter::hit($rateKey, 60);
        return false;
    }
}
