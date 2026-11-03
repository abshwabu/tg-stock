<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\User;
use App\Services\PairingService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Illuminate\Support\Facades\Log;

class PairingHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected PairingService $pairingService
    ) {}

    /**
     * Handle pair callback queries. Format: pair:action:code
     */
    public function handleCallback(string $callbackQueryId, int|string $chatId, int $messageId, string $action, string $code, array $from, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($action === 'cancel') {
            $this->telegram->answerCallbackQuery($callbackQueryId, __('pair_cancelled'));
            $this->telegram->editMessageText(
                $chatId,
                $messageId,
                __('pair_cancelled'),
                ['reply_markup' => ['inline_keyboard' => []]]
            );
            $this->telegram->sendMessage(
                $chatId,
                __('menu_overview', ['shop' => $user?->shop?->name ?? 'Shop']),
                ['reply_markup' => TelegramUi::mainMenuKeyboard($locale)]
            );
            return;
        }

        if ($action === 'confirm') {
            $telegramName = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
            if (empty($telegramName)) {
                $telegramName = $from['username'] ?? 'Shop Owner';
            }

            try {
                $res = $this->pairingService->confirmPairing(
                    $code,
                    (int) $chatId,
                    $telegramName,
                    $locale
                );

                $deviceName = $res['device']->name;
                $this->telegram->answerCallbackQuery($callbackQueryId, __('pair_success', ['device' => $deviceName]));

                $this->telegram->editMessageText(
                    $chatId,
                    $messageId,
                    __('pair_success', ['device' => $deviceName]),
                    ['reply_markup' => ['inline_keyboard' => []]]
                );

                $this->telegram->sendMessage(
                    $chatId,
                    __('menu_overview', ['shop' => $res['shop']->name]),
                    ['reply_markup' => TelegramUi::mainMenuKeyboard($locale)]
                );
            } catch (\Throwable $e) {
                Log::warning('Telegram pairing confirmation failed: ' . $e->getMessage());
                $this->telegram->answerCallbackQuery($callbackQueryId, __('pair_invalid_code'), showAlert: true);
                $this->telegram->editMessageText(
                    $chatId,
                    $messageId,
                    __('pair_invalid_code'),
                    ['reply_markup' => ['inline_keyboard' => []]]
                );
            }
        }
    }
}
