<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\Telegram\Handlers\MenuHandler;
use App\Services\Telegram\Handlers\PairingHandler;
use App\Services\Telegram\Handlers\SettingsHandler;
use App\Services\Telegram\Handlers\StartHandler;
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
        protected MenuHandler $menuHandler
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

        if ($module === 'pair') {
            $this->pairingHandler->handleCallback($cqId, $chatId, $messageId, $action, $id, $from, $user);
            return;
        }

        if ($module === 'lang') {
            $this->settingsHandler->handleLanguageCallback($cqId, $chatId, $messageId, $action, $id, $user);
            return;
        }

        // Default: acknowledge callback query
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

        // Check conversation state
        $state = $this->botState->get($chatId);
        if ($state !== null) {
            if ($state->flow === 'onboarding' && $state->step === 'await_shop_name') {
                $this->startHandler->handleShopNameInput($chatId, $text, $state->data ?? []);
                return;
            }
        }

        // Fall through to menu handler
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
