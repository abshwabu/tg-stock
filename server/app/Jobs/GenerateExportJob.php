<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Movement;
use App\Models\Product;
use App\Models\Shop;
use App\Scopes\ShopScope;
use App\Services\LedgerService;
use App\Services\Telegram\TelegramClient;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(
        public string $type,
        public string $shopId,
        public int|string $chatId,
        public string $locale = 'am'
    ) {}

    public function handle(TelegramClient $telegram, LedgerService $ledger): void
    {
        $shop = Shop::find($this->shopId);
        if ($shop === null) {
            return;
        }

        $dir = storage_path('app/exports');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $filename = "{$this->type}_{$shop->name}_" . date('Y-m-d_His') . '.csv';
        $filepath = "{$dir}/" . Str::random(12) . '.csv';

        $fp = fopen($filepath, 'w');
        if (!$fp) {
            return;
        }

        // Write UTF-8 BOM (\xEF\xBB\xBF) so Excel correctly displays Amharic characters
        fwrite($fp, "\xEF\xBB\xBF");

        if ($this->type === 'products') {
            $this->exportProducts($fp, $shop, $ledger);
        } elseif ($this->type === 'sales') {
            $this->exportSales($fp, $shop);
        } elseif ($this->type === 'movements') {
            $this->exportMovements($fp, $shop);
        }

        fclose($fp);

        $caption = $this->locale === 'en'
            ? "📊 Export file ready: <b>{$filename}</b>"
            : "📊 የኤክስፖርት ፋይል ተዘጋጅቷል: <b>{$filename}</b>";

        try {
            $telegram->sendDocument(
                $this->chatId,
                file_get_contents($filepath),
                $filename,
                $caption
            );
        } finally {
            // Guarantee temporary file is deleted right after sending
            @unlink($filepath);
        }
    }

    protected function exportProducts($fp, Shop $shop, LedgerService $ledger): void
    {
        fputcsv($fp, ['ID', 'Product Name', 'SKU', 'Unit', 'Cost Price (ETB)', 'Sell Price (ETB)', 'Current Stock', 'Status']);

        $products = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->whereNull('deleted_at_ms')
            ->orderBy('name', 'asc')
            ->get();

        foreach ($products as $p) {
            $stock = LedgerService::formatQty($ledger->stockOf($p));
            fputcsv($fp, [
                $p->id,
                $p->name,
                $p->sku ?? '',
                $p->unit,
                number_format($p->cost_price_cents / 100, 2),
                number_format($p->sell_price_cents / 100, 2),
                $stock,
                $p->is_active ? 'Active' : 'Inactive',
            ]);
        }
    }

    protected function exportSales($fp, Shop $shop): void
    {
        fputcsv($fp, ['Date/Time (Addis)', 'Product', 'Quantity Sold', 'Unit Price (ETB)', 'Total (ETB)', 'Staff/User', 'Movement ID']);

        $sales = Movement::withoutGlobalScope(ShopScope::class)
            ->with(['product', 'user'])
            ->where('shop_id', $shop->id)
            ->where('type', 'sale')
            ->orderBy('created_at_ms', 'desc')
            ->get();

        foreach ($sales as $s) {
            $dateAddis = Carbon::createFromTimestampMs($s->created_at_ms, $shop->timezone ?: 'Africa/Addis_Ababa')->format('Y-m-d H:i:s');
            $qty = LedgerService::formatQty(abs($s->qty_milli));
            $unitPrice = number_format($s->unit_price_cents / 100, 2);
            $total = number_format((abs($s->qty_milli) * $s->unit_price_cents) / 100000, 2);

            fputcsv($fp, [
                $dateAddis,
                $s->product?->name ?? 'Deleted Product',
                $qty,
                $unitPrice,
                $total,
                $s->user?->name ?? 'System/Bot',
                $s->id,
            ]);
        }
    }

    protected function exportMovements($fp, Shop $shop): void
    {
        fputcsv($fp, ['Date/Time (Addis)', 'Type', 'Product', 'Quantity Change', 'Unit Price (ETB)', 'Note', 'Staff/User', 'Seq']);

        $movements = Movement::withoutGlobalScope(ShopScope::class)
            ->with(['product', 'user'])
            ->where('shop_id', $shop->id)
            ->orderBy('created_at_ms', 'desc')
            ->get();

        foreach ($movements as $m) {
            $dateAddis = Carbon::createFromTimestampMs($m->created_at_ms, $shop->timezone ?: 'Africa/Addis_Ababa')->format('Y-m-d H:i:s');
            $qty = ($m->qty_milli > 0 ? '+' : '') . LedgerService::formatQty($m->qty_milli);
            $unitPrice = number_format($m->unit_price_cents / 100, 2);

            fputcsv($fp, [
                $dateAddis,
                strtoupper($m->type),
                $m->product?->name ?? 'Deleted Product',
                $qty,
                $unitPrice,
                $m->note ?? '',
                $m->user?->name ?? 'System/Bot',
                $m->server_seq,
            ]);
        }
    }
}
