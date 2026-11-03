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
        protected ReviewQueueHandler $reviewQueueHandler
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
                ['reply_markup' => TelegramUi::mainMenuKeyboard($locale)]
            );
            return;
        }

        // 8.5 Privacy & Data Protection Notice
        if (in_array($clean, ['/privacy', 'privacy', 'ግላዊነት'], true)) {
            $privacyMsg = $locale === 'am'
                ? "🔒 <b>የግላዊነት እና የዳታ ጥበቃ ማስታወሻ</b>\n\n• ኢንቬንቶሪ ኦፍላይን እንዲሰራ የታሰበ ነው።\n• በሰርቨሩ ላይ የሱቅዎ ስም፣ የዕቃዎች ዝርዝርና የግብይት እንቅስቃሴዎች ብቻ ይቀመጣሉ።\n• ምንም ዓይነት የደንበኞች የግል መረጃ ወይም የክፍያ ካርድ አይቀመጥም።\n• የኦንላይን ዳታዎን ለማጥፋት ከሞባይል አፑ ላይ Settings > 'Delete Online Shop' የሚለውን ይጫኑ።\n\nሙሉ መረጃ: /docs/PRIVACY.md"
                : "🔒 <b>Privacy & Data Protection Notice</b>\n\n• Inventory is offline-first.\n• Only shop catalog metadata, stock movements, and paired device hashes are stored online.\n• No customer personal info, banking data, or passwords are ever stored.\n• To delete all online data, open Mobile App Settings > 'Delete Online Shop on Server'.\n\nFull notice: /docs/PRIVACY.md";

            $this->telegram->sendMessage(
                $chatId,
                $privacyMsg,
                ['reply_markup' => TelegramUi::mainMenuKeyboard($locale)]
            );
            return;
        }

        // 9. Plain text search outside a flow
        $this->productHandler->handleSearch($chatId, $clean, $user);
    }
}
