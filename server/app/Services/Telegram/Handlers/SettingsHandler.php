<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\Shop;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PermissionService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;

class SettingsHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected PermissionService $permissionService
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
        $negStockStatus = ($shop?->allow_negative_stock) ? '✅ Enabled' : '❌ Disabled';
        $calendarStatus = ($shop?->use_ethiopian_calendar) ? '🇪🇹 Ethiopian' : '🌐 Gregorian';

        $text = __('settings_title', [
            'shop' => $shopName,
            'role' => $role,
            'language' => $langName,
            'neg_stock' => $negStockStatus,
        ]) . "\n📅 Calendar: <b>{$calendarStatus}</b>\n\n" . __('choose_language');

        $rows = [
            [
                ['text' => '🇪🇹 አማርኛ', 'callback_data' => 'lang:set:am'],
                ['text' => '🇬🇧 English', 'callback_data' => 'lang:set:en'],
            ],
        ];

        // Locations management (owner or locations.manage)
        if ($user !== null && ($user->role === 'owner' || $this->permissionService->can($user, 'locations.manage'))) {
            $rows[] = [
                ['text' => '📍 ' . ($locale === 'en' ? 'Manage Locations & Transfers' : 'የቦታዎች እና ዝውውር አስተዳደር'), 'callback_data' => 'loc:settings:0'],
            ];
        }

        // Team management (owner or team.manage)
        if ($user !== null && ($user->role === 'owner' || $this->permissionService->can($user, 'team.manage'))) {
            $rows[] = [
                ['text' => '👥 ' . ($locale === 'en' ? 'Manage Team & Staff' : 'የቡድን እና ሠራተኞች አስተዳደር'), 'callback_data' => 'team:menu:0'],
            ];
        }

        // Linked devices (owner only)
        if ($user?->role === 'owner') {
            $rows[] = [
                ['text' => '📱 ' . ($locale === 'en' ? 'Manage Linked Devices' : 'የተገናኙ ስልኮች አስተዳደር'), 'callback_data' => 'devices:list:0'],
            ];
        }

        // Owner only options
        if ($user?->role === 'owner') {
            $toggleNegLabel = ($shop?->allow_negative_stock)
                ? '⚡ Negative Stock: [ Enabled ✅ ]'
                : '⚡ Negative Stock: [ Disabled ❌ ]';

            $toggleCalLabel = ($shop?->use_ethiopian_calendar)
                ? '📅 Calendar: [ 🇪🇹 Ethiopian ]'
                : '📅 Calendar: [ 🌐 Gregorian ]';

            $rows[] = [
                ['text' => $toggleNegLabel, 'callback_data' => 'settings:toggle:neg_stock'],
            ];

            $rows[] = [
                ['text' => $toggleCalLabel, 'callback_data' => 'settings:toggle:calendar'],
            ];

            $rows[] = [
                ['text' => '📥 ' . ($locale === 'en' ? 'Needs Review Queue' : 'ማረጋገጫ የሚጠብቁ ለውጦች'), 'callback_data' => 'rq:menu:0'],
            ];
        }

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($rows),
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

    /**
     * Toggle allow_negative_stock setting (Owner only).
     */
    public function handleToggleNegativeStock(string $callbackQueryId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackQueryId, __('permission_denied'), showAlert: true);
            return;
        }

        $shop = $user?->shop_id ? Shop::find($user->shop_id) : null;
        if ($shop === null) {
            $this->telegram->answerCallbackQuery($callbackQueryId);
            return;
        }

        $shop->allow_negative_stock = !$shop->allow_negative_stock;
        $shop->save();

        ActivityLogger::log(
            (string) $shop->id,
            $user->id,
            'settings.change',
            "Toggled allow_negative_stock to " . ($shop->allow_negative_stock ? 'enabled' : 'disabled')
        );

        $this->telegram->answerCallbackQuery(
            $callbackQueryId,
            $shop->allow_negative_stock ? 'Negative stock allowed' : 'Negative stock disallowed'
        );

        $this->refreshSettingsMessage($chatId, $messageId, $shop, $user, $locale);
    }

    /**
     * Toggle use_ethiopian_calendar setting (Owner only).
     */
    public function handleToggleCalendar(string $callbackQueryId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackQueryId, __('permission_denied'), showAlert: true);
            return;
        }

        $shop = $user?->shop_id ? Shop::find($user->shop_id) : null;
        if ($shop === null) {
            $this->telegram->answerCallbackQuery($callbackQueryId);
            return;
        }

        $shop->use_ethiopian_calendar = !$shop->use_ethiopian_calendar;
        $shop->save();

        ActivityLogger::log(
            (string) $shop->id,
            $user->id,
            'settings.change',
            "Toggled use_ethiopian_calendar to " . ($shop->use_ethiopian_calendar ? 'enabled' : 'disabled')
        );

        $this->telegram->answerCallbackQuery(
            $callbackQueryId,
            $shop->use_ethiopian_calendar ? 'Ethiopian calendar active' : 'Gregorian calendar active'
        );

        $this->refreshSettingsMessage($chatId, $messageId, $shop, $user, $locale);
    }

    protected function refreshSettingsMessage(int|string $chatId, int $messageId, Shop $shop, User $user, string $locale): void
    {
        $shopName = $shop->name;
        $role = $user->role;
        $langName = $locale === 'am' ? 'አማርኛ (Amharic)' : 'English';
        $negStockStatus = ($shop->allow_negative_stock) ? '✅ Enabled' : '❌ Disabled';
        $calendarStatus = ($shop->use_ethiopian_calendar) ? '🇪🇹 Ethiopian' : '🌐 Gregorian';

        $text = __('settings_title', [
            'shop' => $shopName,
            'role' => $role,
            'language' => $langName,
            'neg_stock' => $negStockStatus,
        ]) . "\n📅 Calendar: <b>{$calendarStatus}</b>\n\n" . __('choose_language');

        $toggleNegLabel = ($shop->allow_negative_stock)
            ? '⚡ Negative Stock: [ Enabled ✅ ]'
            : '⚡ Negative Stock: [ Disabled ❌ ]';

        $toggleCalLabel = ($shop->use_ethiopian_calendar)
            ? '📅 Calendar: [ 🇪🇹 Ethiopian ]'
            : '📅 Calendar: [ 🌐 Gregorian ]';

        $rows = [
            [
                ['text' => '🇪🇹 አማርኛ', 'callback_data' => 'lang:set:am'],
                ['text' => '🇬🇧 English', 'callback_data' => 'lang:set:en'],
            ],
            [
                ['text' => $toggleNegLabel, 'callback_data' => 'settings:toggle:neg_stock'],
            ],
            [
                ['text' => $toggleCalLabel, 'callback_data' => 'settings:toggle:calendar'],
            ],
            [
                ['text' => '👥 ' . ($locale === 'en' ? 'Manage Team & Staff' : 'የቡድን እና ሠራተኞች አስተዳደር'), 'callback_data' => 'team:menu:0'],
            ],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($rows),
        ]);
    }
}
