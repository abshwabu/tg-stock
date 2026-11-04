<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\Shop;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PaymentProviderService;
use App\Services\PermissionService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;

class SettingsHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected PermissionService $permissionService,
        protected PaymentProviderService $providerService
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
            $rows[] = [
                ['text' => '💳 ' . ($locale === 'en' ? 'Payment Methods & Dube' : 'የክፍያ መንገዶች እና ዱቤ'), 'callback_data' => 'settings:pay:menu'],
            ];

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
     * Show Payment Methods & Dube Settings menu (Owner only).
     */
    public function handlePaymentSettingsMenu(string $callbackQueryId, int|string $chatId, int $messageId, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackQueryId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackQueryId);

        $shop = Shop::find($user->shop_id);
        if ($shop === null) {
            return;
        }

        $enabled = $shop->payment_methods_enabled ?? ['cash', 'bank', 'wallet', 'dube'];
        $dubeDays = $shop->dube_default_days ?? 14;
        $reqRef = (bool) ($shop->require_payment_reference ?? false);

        $cashIcon = in_array('cash', $enabled, true) ? '✅' : '❌';
        $bankIcon = in_array('bank', $enabled, true) ? '✅' : '❌';
        $walletIcon = in_array('wallet', $enabled, true) ? '✅' : '❌';
        $dubeIcon = in_array('dube', $enabled, true) ? '✅' : '❌';
        $reqRefIcon = $reqRef ? '✅' : '❌';

        $text = $locale === 'en'
            ? "💳 <b>Payment Methods & Dube Settings</b>\n\n"
                . "• Default Dube Days: <b>+{$dubeDays} days</b>\n"
                . "• Require Reference for Bank/Wallet: <b>" . ($reqRef ? 'Enabled' : 'Disabled') . "</b>\n\n"
                . "Tap buttons below to toggle methods or change settings:"
            : "💳 <b>የክፍያ መንገዶች እና የዱቤ ቅንብሮች</b>\n\n"
                . "• መደበኛ የዱቤ መክፈያ ጊዜ: <b>+{$dubeDays} ቀናት</b>\n"
                . "• የባንክ/ዋሌት ማጣቀሻ ቁጥር መጠየቅ: <b>" . ($reqRef ? 'የነቃ' : 'የጠፋ') . "</b>\n\n"
                . "ለማብራት/ለማጥፋት ከታች ያሉትን ይጫኑ:";

        $buttons = [
            [
                ['text' => "💵 Cash [{$cashIcon}]", 'callback_data' => 'settings:pay:tog_m:cash'],
                ['text' => "🏦 Bank [{$bankIcon}]", 'callback_data' => 'settings:pay:tog_m:bank'],
            ],
            [
                ['text' => "📱 Wallet [{$walletIcon}]", 'callback_data' => 'settings:pay:tog_m:wallet'],
                ['text' => "📒 Dube [{$dubeIcon}]", 'callback_data' => 'settings:pay:tog_m:dube'],
            ],
            [
                ['text' => "📝 Require Reference [{$reqRefIcon}]", 'callback_data' => 'settings:pay:tog_ref:0'],
            ],
            [
                ['text' => '📅 ' . ($locale === 'en' ? "Dube: 7d" : "ዱቤ: 7ቀን"), 'callback_data' => 'settings:pay:d_days:7'],
                ['text' => '📅 ' . ($locale === 'en' ? "Dube: 14d" : "ዱቤ: 14ቀን"), 'callback_data' => 'settings:pay:d_days:14'],
                ['text' => '📅 ' . ($locale === 'en' ? "Dube: 30d" : "ዱቤ: 30ቀን"), 'callback_data' => 'settings:pay:d_days:30'],
            ],
            [
                ['text' => '⭐ ' . ($locale === 'en' ? 'Favorite Banks' : 'ተመራጭ ባንኮች'), 'callback_data' => 'settings:pay:favs:bank:1'],
                ['text' => '⭐ ' . ($locale === 'en' ? 'Favorite Wallets' : 'ተመራጭ ዋሌቶች'), 'callback_data' => 'settings:pay:favs:wallet:1'],
            ],
            [
                ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Settings' : 'ወደ ቅንብሮች ተመለስ'), 'callback_data' => 'settings:menu:back'],
            ],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Toggle a payment method on or off.
     */
    public function handleTogglePaymentMethod(string $callbackQueryId, int|string $chatId, int $messageId, string $method, ?User $user): void
    {
        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackQueryId, __('permission_denied'), showAlert: true);
            return;
        }

        $shop = Shop::find($user->shop_id);
        if ($shop === null) {
            $this->telegram->answerCallbackQuery($callbackQueryId);
            return;
        }

        $enabled = $shop->payment_methods_enabled ?? ['cash', 'bank', 'wallet', 'dube'];
        if (in_array($method, $enabled, true)) {
            // Cannot disable all methods: at least one method must remain
            if (count($enabled) <= 1) {
                $this->telegram->answerCallbackQuery($callbackQueryId, 'At least one payment method must remain enabled.', showAlert: true);
                return;
            }
            $enabled = array_values(array_diff($enabled, [$method]));
        } else {
            $enabled[] = $method;
        }

        $shop->payment_methods_enabled = $enabled;
        $shop->save();

        $this->telegram->answerCallbackQuery($callbackQueryId, ucfirst($method) . ' toggled');
        $this->handlePaymentSettingsMenu($callbackQueryId, $chatId, $messageId, $user);
    }

    /**
     * Toggle require_payment_reference on or off.
     */
    public function handleToggleRequireReference(string $callbackQueryId, int|string $chatId, int $messageId, ?User $user): void
    {
        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackQueryId, __('permission_denied'), showAlert: true);
            return;
        }

        $shop = Shop::find($user->shop_id);
        if ($shop === null) {
            $this->telegram->answerCallbackQuery($callbackQueryId);
            return;
        }

        $shop->require_payment_reference = !$shop->require_payment_reference;
        $shop->save();

        $this->telegram->answerCallbackQuery($callbackQueryId, 'Require reference ' . ($shop->require_payment_reference ? 'enabled' : 'disabled'));
        $this->handlePaymentSettingsMenu($callbackQueryId, $chatId, $messageId, $user);
    }

    /**
     * Set default dube days.
     */
    public function handleSetDefaultDubeDays(string $callbackQueryId, int|string $chatId, int $messageId, int $days, ?User $user): void
    {
        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackQueryId, __('permission_denied'), showAlert: true);
            return;
        }

        $shop = Shop::find($user->shop_id);
        if ($shop === null) {
            $this->telegram->answerCallbackQuery($callbackQueryId);
            return;
        }

        $shop->dube_default_days = $days;
        $shop->save();

        $this->telegram->answerCallbackQuery($callbackQueryId, "Default Dube days set to {$days}");
        $this->handlePaymentSettingsMenu($callbackQueryId, $chatId, $messageId, $user);
    }

    /**
     * Show favorite providers list for toggle.
     */
    public function handleFavoriteProvidersList(
        string $callbackQueryId,
        int|string $chatId,
        int $messageId,
        string $kind,
        int $page,
        ?User $user
    ): void {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackQueryId, __('permission_denied'), showAlert: true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackQueryId);

        $shopId = (string) $user->shop_id;
        $providers = $this->providerService->listProviders($shopId, $kind, $locale);

        $totalItems = $providers->count();
        $perPage = 6;
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $pageItems = $providers->slice($offset, $perPage)->values();

        $kindLabel = $kind === 'bank'
            ? ($locale === 'en' ? 'Banks' : 'ባንኮች')
            : ($locale === 'en' ? 'Wallets' : 'ዋሌቶች');

        $text = $locale === 'en'
            ? "⭐ <b>Favorite {$kindLabel} (Page {$page}/{$totalPages})</b>\nTap any provider to toggle favorite (⭐ Favorite / ☆ Normal):"
            : "⭐ <b>ተመራጭ {$kindLabel} (ገጽ {$page}/{$totalPages})</b>\nተመራጭ ለማድረግ ወይም ለማንሳት ተቋሙን ይጫኑ (⭐ ተመራጭ / ☆ መደበኛ):";

        $buttons = [];
        foreach ($pageItems as $item) {
            $star = $item['favorite'] ? '⭐' : '☆';
            $label = "{$star} {$item['display_name']}";
            $buttons[] = [
                ['text' => $label, 'callback_data' => "settings:pay:tog_fav:{$kind}:{$item['provider_key']}:{$page}"],
            ];
        }

        // Pagination row
        if ($totalPages > 1) {
            $navRow = [];
            if ($page > 1) {
                $prev = $page - 1;
                $navRow[] = ['text' => '◀️ ' . ($locale === 'en' ? 'Prev' : 'ወደኋላ'), 'callback_data' => "settings:pay:favs:{$kind}:{$prev}"];
            }
            $navRow[] = ['text' => "{$page} / {$totalPages}", 'callback_data' => 'noop:none:0'];
            if ($page < $totalPages) {
                $next = $page + 1;
                $navRow[] = ['text' => ($locale === 'en' ? 'Next' : 'ወደፊት') . ' ▶️', 'callback_data' => "settings:pay:favs:{$kind}:{$next}"];
            }
            $buttons[] = $navRow;
        }

        $buttons[] = [
            ['text' => '◀️ ' . ($locale === 'en' ? 'Back to Payment Settings' : 'ወደ ክፍያ ቅንብሮች'), 'callback_data' => 'settings:pay:menu'],
        ];

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => TelegramUi::inlineKeyboard($buttons),
        ]);
    }

    /**
     * Toggle favorite status on a provider and refresh list.
     */
    public function handleToggleProviderFavorite(
        string $callbackQueryId,
        int|string $chatId,
        int $messageId,
        string $kind,
        string $providerKey,
        int $page,
        ?User $user
    ): void {
        if ($user?->role !== 'owner') {
            $this->telegram->answerCallbackQuery($callbackQueryId, __('permission_denied'), showAlert: true);
            return;
        }

        $shopId = (string) $user->shop_id;
        $newFav = $this->providerService->toggleFavorite($shopId, $providerKey, $kind);

        $this->telegram->answerCallbackQuery($callbackQueryId, $newFav ? 'Marked as favorite ⭐' : 'Removed from favorites');
        $this->handleFavoriteProvidersList($callbackQueryId, $chatId, $messageId, $kind, $page, $user);
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
                ['text' => '💳 ' . ($locale === 'en' ? 'Payment Methods & Dube' : 'የክፍያ መንገዶች እና ዱቤ'), 'callback_data' => 'settings:pay:menu'],
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
