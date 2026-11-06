<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;

class MenuHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected SettingsHandler $settingsHandler,
        protected ProductHandler $productHandler,
        protected StockInHandler $stockInHandler,
        protected SaleHandler $saleHandler,
        protected UndoHandler $undoHandler,
        protected ReportHandler $reportHandler,
        protected TeamHandler $teamHandler,
        protected LocationHandler $locationHandler,
        protected TransferHandler $transferHandler,
        protected ReviewQueueHandler $reviewQueueHandler,
        protected DubeHandler $dubeHandler
    ) {}

    /**
     * Handle top-level menu button taps or commands.
     */
    public function handle(int|string $chatId, string $text, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $clean = trim($text);

        // Menu shortcut
        if (in_array($clean, ['/menu', 'menu', 'ማውጫ'], true)) {
            $shop = $user?->shop_id ? \App\Models\Shop::find($user->shop_id) : null;
            $shopName = $shop?->name ?? 'Shop';
            $this->telegram->sendMessage(
                $chatId,
                __('welcome_existing', ['name' => $user?->name ?? 'User', 'shop' => $shopName]),
                ['reply_markup' => TelegramUi::mainMenuInlineKeyboard($locale, $user)]
            );
            $this->telegram->sendMessage(
                $chatId,
                $locale === 'en' ? '📍 Quick actions menu:' : '📍 ፈጣን ማውጫ:',
                ['reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user)]
            );
            return;
        }

        // 0. Location Switcher: e.g. "📍 Store ▾", "📍 Warehouse ▾", or command /switch, /location
        if (preg_match('/^📍.+▾$/u', $clean) || in_array($clean, ['/switch', '/location', '/locations'], true)) {
            $this->locationHandler->handleSwitchMenu($chatId, $user);
            return;
        }

        // 1. Settings
        if (in_array($clean, ['⚙️ Settings', '⚙️ ቅንብሮች', '/settings'], true)) {
            $this->settingsHandler->handleSettings($chatId, $user);
            return;
        }

        // 1.5 Transfer
        if (in_array($clean, ['🚚 Transfer', '🚚 ማስተላለፍ', '/transfer'], true)) {
            $this->transferHandler->start($chatId, null, $user);
            return;
        }

        // 1.6 Review Queue
        if (in_array($clean, ['📥 Needs Review', '📥 ማረጋገጫ የሚጠብቁ', '/review'], true)) {
            $this->reviewQueueHandler->handleReviewMenu($chatId, $user);
            return;
        }

        // 2. Undo
        if (in_array($clean, ['↩️ Undo', '↩️ መልስ', '/undo'], true)) {
            $this->undoHandler->handleUndo($chatId, $user);
            return;
        }

        // 3. Products
        if (in_array($clean, ['📦 Products', '📦 ዕቃዎች', '/products'], true)) {
            $this->productHandler->handleList($chatId, $user, 1);
            return;
        }

        // 4. Stock In
        if (in_array($clean, ['➕ Stock In', '➕ ገቢ ዕቃ', '/stockin'], true)) {
            $this->stockInHandler->start($chatId, null, $user);
            return;
        }

        // 5. Sale
        if (in_array($clean, ['🛒 Sale', '🛒 ሽያጭ', '/sale'], true)) {
            $this->saleHandler->start($chatId, null, $user);
            return;
        }

        // 5.5 Dube
        if (in_array($clean, ['📒 Dube', '📒 ዱቤ', '/dube', 'dube', 'ዱቤ'], true)) {
            $this->dubeHandler->start($chatId, $user);
            return;
        }

        // 6. Reports
        if (in_array($clean, ['📊 Reports', '📊 ሪፖርት', '/reports', '/report'], true)) {
            $this->reportHandler->handleMenu($chatId, $user);
            return;
        }

        // 7. Team
        if (in_array($clean, ['👥 Team', '👥 ቡድን', '/team', '/staff'], true)) {
            $this->teamHandler->handleTeamMenu($chatId, $user);
            return;
        }

        // 8. Help
        if (in_array($clean, ['/help', 'help', 'እርዳታ'], true)) {
            $this->telegram->sendMessage(
                $chatId,
                __('help_text'),
                ['reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user)]
            );
            return;
        }

        // 8.5 Privacy & Data Protection Notice
        if (in_array($clean, ['/privacy', 'privacy', 'ግላዊነት'], true)) {
            $siteUrl = rtrim((string) config('app.url'), '/');
            $privacyMsg = $locale === 'am'
                ? "🔒 <b>የግላዊነት እና የዳታ ጥበቃ ማስታወሻ</b>\n\n• መተግበሪያው ከመስመር ውጭ ይሰራል፤ የመስመር ላይ ማመሳሰል አማራጭ ነው።\n• ማመሳሰልን ሲያበሩ የቴሌግራም መለያ፣ የሱቅ መረጃ እና የመሣሪያ ቶከን በሰርቨሩ ላይ ይቀመጣሉ።\n• የመስመር ላይ መረጃዎን በአፑ ቅንብሮች ውስጥ ወይም /delete_shop በመጠቀም ያጥፉ።\n\nሙሉ ፖሊሲ: {$siteUrl}/privacy/\nየአጠቃቀም ደንቦች: {$siteUrl}/terms/\nድጋፍ: support@yenesuq.com"
                : "🔒 <b>Privacy & Data Protection Notice</b>\n\n• Inventory works offline; online sync is optional.\n• When enabled, the server stores your Telegram ID, shop data, and device token.\n• Delete online data in the app settings or with /delete_shop.\n\nPrivacy policy: {$siteUrl}/privacy/\nTerms of service: {$siteUrl}/terms/\nSupport: support@yenesuq.com";

            $this->telegram->sendMessage(
                $chatId,
                $privacyMsg,
                ['reply_markup' => TelegramUi::mainMenuKeyboard($locale)]
            );
            return;
        }

        // 9. Unknown text remains searchable, with a clear route back to the menu.
        $this->telegram->sendMessage(
            $chatId,
            __('unknown_command'),
            ['reply_markup' => TelegramUi::mainMenuKeyboard($locale, $user)]
        );
        $this->productHandler->handleSearch($chatId, $clean, $user);
    }
}
