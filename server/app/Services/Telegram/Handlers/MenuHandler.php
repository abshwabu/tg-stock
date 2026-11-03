<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\Shop;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;

class MenuHandler
{
    public function __construct(
        protected TelegramClient $telegram,
        protected SettingsHandler $settingsHandler
    ) {}

    /**
     * Handle top-level menu button taps or commands.
     */
    public function handle(int|string $chatId, string $text, ?User $user): void
    {
        $locale = $user?->language ?? 'am';
        app()->setLocale($locale);

        $shop = $user?->shop_id ? Shop::find($user->shop_id) : null;
        $shopName = $shop?->name ?? 'Shop';

        $clean = trim($text);

        // 1. Settings
        if (in_array($clean, ['⚙️ Settings', '⚙️ ቅንብሮች', '/settings'], true)) {
            $this->settingsHandler->handleSettings($chatId, $user);
            return;
        }

        // 2. Help
        if (in_array($clean, ['/help', 'help', 'እርዳታ'], true)) {
            $this->telegram->sendMessage(
                $chatId,
                __('help_text'),
                ['reply_markup' => TelegramUi::mainMenuKeyboard($locale)]
            );
            return;
        }

        // 3. Products
        if (in_array($clean, ['📦 Products', '📦 ዕቃዎች', '/products'], true)) {
            $msg = $locale === 'en'
                ? "📦 <b>{$shopName} — Products</b>\nView stock and manage product catalog."
                : "📦 <b>{$shopName} — ዕቃዎች</b>\nየሱቅዎን ዕቃዎች እና ስቶክ እዚህ ይመልከቱ።";

            $this->telegram->sendMessage($chatId, $msg, [
                'reply_markup' => TelegramUi::mainMenuKeyboard($locale),
            ]);
            return;
        }

        // 4. Stock In
        if (in_array($clean, ['➕ Stock In', '➕ ገቢ ዕቃ', '/stockin'], true)) {
            $msg = $locale === 'en'
                ? "➕ <b>{$shopName} — Stock In</b>\nAdd new inventory deliveries to ledger."
                : "➕ <b>{$shopName} — ገቢ ዕቃ</b>\nአዲስ የገቡ ዕቃዎችን ወደ ስቶክ መዝገብ ያስገቡ።";

            $this->telegram->sendMessage($chatId, $msg, [
                'reply_markup' => TelegramUi::mainMenuKeyboard($locale),
            ]);
            return;
        }

        // 5. Sale
        if (in_array($clean, ['🛒 Sale', '🛒 ሽያጭ', '/sale'], true)) {
            $msg = $locale === 'en'
                ? "🛒 <b>{$shopName} — Sale</b>\nRecord sales and track customer totals."
                : "🛒 <b>{$shopName} — ሽያጭ</b>\nዕቃ ይሽጡ እና ገቢዎን ይመዝግቡ።";

            $this->telegram->sendMessage($chatId, $msg, [
                'reply_markup' => TelegramUi::mainMenuKeyboard($locale),
            ]);
            return;
        }

        // 6. Reports
        if (in_array($clean, ['📊 Reports', '📊 ሪፖርት', '/reports'], true)) {
            $msg = $locale === 'en'
                ? "📊 <b>{$shopName} — Reports</b>\nDaily summary and stock value."
                : "📊 <b>{$shopName} — ሪፖርት</b>\nየዕለቱ ሽያጭ እና የዕቃ ካፒታል ማጠቃለያ።";

            $this->telegram->sendMessage($chatId, $msg, [
                'reply_markup' => TelegramUi::mainMenuKeyboard($locale),
            ]);
            return;
        }

        // 7. Fallback for unrecognized text
        $this->telegram->sendMessage(
            $chatId,
            __('unknown_command'),
            ['reply_markup' => TelegramUi::mainMenuKeyboard($locale)]
        );
    }
}
