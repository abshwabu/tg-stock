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
        protected TeamHandler $teamHandler
    ) {}

    /**
     * Handle top-level menu button taps or commands.
     */
    public function handle(int|string $chatId, string $text, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $clean = trim($text);

        // 1. Settings
        if (in_array($clean, ['⚙️ Settings', '⚙️ ቅንብሮች', '/settings'], true)) {
            $this->settingsHandler->handleSettings($chatId, $user);
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

        // 9. Plain text search outside a flow
        $this->productHandler->handleSearch($chatId, $clean, $user);
    }
}
