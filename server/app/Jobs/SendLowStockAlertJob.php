<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Location;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\LedgerService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendLowStockAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public string $productId,
        public int $currentStockMilli,
        public string $shopId,
        public ?string $locationId = null
    ) {}

    public function handle(TelegramClient $telegram): void
    {
        $shop = Shop::find($this->shopId);
        if ($shop === null) {
            return;
        }

        $recipients = User::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shopId)
            ->whereIn('role', ['owner', 'admin'])
            ->where('is_active', true)
            ->whereNotNull('telegram_id')
            ->get();

        if ($recipients->isEmpty()) {
            $recipients = User::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $this->shopId)
                ->where('is_active', true)
                ->whereNotNull('telegram_id')
                ->get();
        }

        if ($recipients->isEmpty()) {
            return;
        }

        /** @var Product|null $product */
        $product = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shopId)
            ->where('id', $this->productId)
            ->first();

        if ($product === null) {
            return;
        }

        $location = $this->locationId
            ? Location::withoutGlobalScope(ShopScope::class)->find($this->locationId)
            : null;
        $locName = $location?->name;

        $stockFormatted = LedgerService::formatQty($this->currentStockMilli);
        $thresholdFormatted = LedgerService::formatQty($product->low_stock_at_milli ?? $shop->low_stock_default_milli);

        foreach ($recipients as $recipient) {
            $locale = $recipient->language ?? 'am';
            app()->setLocale($locale);

            $locLineEn = $locName ? "Location: <b>{$locName}</b>\n" : "";
            $locLineAm = $locName ? "ቦታ: <b>{$locName}</b>\n" : "";

            if ($this->currentStockMilli <= 0) {
                $text = $locale === 'en'
                    ? "🚨 <b>OUT OF STOCK ALERT!</b>\n{$locLineEn}You are out of stock on <b>{$product->name}</b> (<b>0 {$product->unit}</b>)!\n<i>በ <b>{$product->name}</b> ላይ ያለው ክምችት ሙሉ በሙሉ አልቋል!</i>\nTap below to add stock."
                    : "🚨 <b>ዕቃው አልቋል! (OUT OF STOCK ALERT)</b>\n{$locLineAm}በ <b>{$product->name}</b> ላይ ያለው ክምችት ሙሉ በሙሉ አልቋል (<b>0 {$product->unit}</b>)!\n<i>You are out of stock on <b>{$product->name}</b>.</i>\nእባክዎ አዲስ ገቢ ዕቃ ይመዝግቡ።";
            } else {
                $text = $locale === 'en'
                    ? "⚠️ <b>LOW STOCK WARNING!</b>\n{$locLineEn}You are low on <b>{$product->name}</b>: <b>{$stockFormatted} {$product->unit}</b> remaining (Threshold: {$thresholdFormatted} {$product->unit}).\n<i>በ <b>{$product->name}</b> ላይ ዝቅተኛ ክምችት አለብዎት።</i>"
                    : "⚠️ <b>የአነስተኛ ክምችት ማስጠንቀቂያ! (LOW STOCK WARNING)</b>\n{$locLineAm}በ <b>{$product->name}</b> ላይ ዝቅተኛ ክምችት አለብዎት: <b>{$stockFormatted} {$product->unit}</b> ብቻ ቀርቷል (ዝቅተኛ ገደብ: {$thresholdFormatted} {$product->unit})።\n<i>You are low on <b>{$product->name}</b>: {$stockFormatted} {$product->unit} remaining.</i>";
            }

            $buttons = TelegramUi::inlineKeyboard([
                [
                    ['text' => __('btn_stock_in'), 'callback_data' => "stockin:start:{$product->id}"],
                ],
            ]);

            try {
                $telegram->sendMessage((int) $recipient->telegram_id, $text, [
                    'reply_markup' => $buttons,
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to send low stock alert to telegram_id [{$recipient->telegram_id}]: " . $e->getMessage());
            }
        }
    }
}
