<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\Shop;
use App\Models\User;
use App\Services\LedgerService;
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
        protected LedgerService $ledger
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

        // 6. Reports (Quick Overview)
        if (in_array($clean, ['📊 Reports', '📊 ሪፖርት', '/reports'], true)) {
            $shop = $user?->shop_id ? Shop::find($user->shop_id) : null;
            $shopName = $shop?->name ?? 'Shop';
            $stockItems = $this->ledger->stockList((string) $user?->shop_id);

            $totalProducts = $stockItems->count();
            $totalCapitalCents = 0;
            $lowCount = 0;
            $threshold = $shop?->low_stock_default_milli ?? 5000;

            foreach ($stockItems as $item) {
                $stockQty = $item->stock_milli / 1000;
                $costPrice = $item->cost_price_cents;
                $totalCapitalCents += (int) round($stockQty * $costPrice);
                if ($item->stock_milli <= ($item->low_stock_at_milli ?? $threshold)) {
                    $lowCount++;
                }
            }

            $capitalFormatted = number_format($totalCapitalCents / 100, 2);

            $msg = $locale === 'en'
                ? "📊 <b>{$shopName} — Inventory Report</b>\n• Total Products: <b>{$totalProducts}</b>\n• Low Stock Items: <b>{$lowCount}</b>\n• Inventory Value (Cost): <b>{$capitalFormatted} ETB</b>"
                : "📊 <b>{$shopName} — የስቶክ ሪፖርት</b>\n• ጠቅላላ ዕቃዎች: <b>{$totalProducts}</b>\n• አነስተኛ ስቶክ ያላቸው: <b>{$lowCount}</b>\n• የስቶክ ካፒታል (በመግዣ ዋጋ): <b>{$capitalFormatted} ብር</b>";

            $this->telegram->sendMessage($chatId, $msg, [
                'reply_markup' => TelegramUi::mainMenuKeyboard($locale),
            ]);
            return;
        }

        // 7. Help
        if (in_array($clean, ['/help', 'help', 'እርዳታ'], true)) {
            $this->telegram->sendMessage(
                $chatId,
                __('help_text'),
                ['reply_markup' => TelegramUi::mainMenuKeyboard($locale)]
            );
            return;
        }

        // 8. Plain text search outside a flow
        $this->productHandler->handleSearch($chatId, $clean, $user);
    }
}
