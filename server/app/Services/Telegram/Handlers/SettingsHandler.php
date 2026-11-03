<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;

class SettingsHandler
{
    public function __construct(
        protected TelegramClient $telegram
    ) {}

    /**
     * Show settings menu.
     */
    public function handleSettings(int|string $chatId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $shop = $user?->shop_id ? Shop::find($user->shop_id) : null;
        $shopName = $shop?->name ?? 'None';
        $role = $user?->role ?? 'none';
        $langName = $locale === 'am' ? 'አማርኛ (Amharic)' : 'English';

        $text = __('settings_title', [
            'shop' => $shopName,
            'role' => $role,
            'language' => $langName,
        ]) . "\n\n" . __('choose_language');

        $keyboard = TelegramUi::inlineKeyboard([
            [
                ['text' => '🇪🇹 አማርኛ', 'callback_data' => 'lang:set:am'],
                ['text' => '🇬🇧 English', 'callback_data' => 'lang:set:en'],
            ],
        ]);

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => $keyboard,
        ]);
    }

    /**
     * Handle language switch callback (lang:set:am / lang:set:en).
     */
    public function handleLanguageCallback(string $callbackQueryId, int|string $chatId, int $messageId, string $action, string $lang, ?User $user): void
    {
        if ($action !== 'set' || !in_array($lang, ['am', 'en'], true)) {
            $this->telegram->answerCallbackQuery($callbackQueryId);
            return;
        }

        if ($user !== null) {
            $user->language = $lang;
            $user->save();

            if ($user->role === 'owner' && $user->shop_id) {
                $shop = Shop::find($user->shop_id);
                if ($shop !== null) {
                    $shop->language = $lang;
                    $shop->save();
                }
            }
        }

        app()->setLocale($lang);

        $this->telegram->answerCallbackQuery($callbackQueryId, __('language_updated'));
        $this->telegram->editMessageText(
            $chatId,
            $messageId,
            __('language_updated'),
            ['reply_markup' => ['inline_keyboard' => []]]
        );

        $shopName = $user?->shop_id ? (Shop::find($user->shop_id)?->name ?? 'Shop') : 'Shop';

        $this->telegram->sendMessage(
            $chatId,
            __('menu_overview', ['shop' => $shopName]),
            ['reply_markup' => TelegramUi::mainMenuKeyboard($lang)]
        );
    }
}
