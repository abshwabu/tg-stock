<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Context\CurrentShopContext;
use App\Models\Location;
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
        public string $locale = 'am',
        public ?string $period = null,
        public ?string $locationId = null
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

        $prefix = match ($this->type) {
            'sales', 'period_sales' => !empty($this->period) ? "sales_{$this->period}" : 'sales',
            'products' => 'products',
            'movements' => 'movements',
            'stock_by_loc' => 'stock_by_location',
            'transit' => 'in_transit',
            'discrepancies' => 'discrepancies',
            'valuation' => 'stock_valuation',
            default => $this->type,
        };

        $safeShopName = Str::slug($shop->name, '_') ?: 'shop';
        $filename = "{$prefix}_{$safeShopName}_" . date('Y-m-d_His') . '.csv';
        $filepath = "{$dir}/" . Str::random(12) . '.csv';

        CurrentShopContext::runWith($shop, function () use ($shop, $ledger, $filepath) {
            $fp = fopen($filepath, 'w');
            if (!$fp) {
                return;
            }

            // Write UTF-8 BOM (\xEF\xBB\xBF) so Excel correctly displays Amharic characters
            fwrite($fp, "\xEF\xBB\xBF");

            if ($this->type === 'products') {
                $this->exportProducts($fp, $shop, $ledger);
            } elseif ($this->type === 'sales' || $this->type === 'period_sales') {
                $this->exportSales($fp, $shop, $this->period, $this->locationId);
            } elseif ($this->type === 'movements') {
                $this->exportMovements($fp, $shop);
            } elseif ($this->type === 'stock_by_loc') {
                $this->exportStockByLocation($fp, $shop, $ledger);
            } elseif ($this->type === 'transit') {
                $this->exportTransit($fp, $shop, $ledger);
            } elseif ($this->type === 'discrepancies') {
                $this->exportDiscrepancies($fp, $shop, $ledger);
            } elseif ($this->type === 'valuation') {
                $this->exportValuation($fp, $shop, $ledger);
            }

            fclose($fp);
        });

        if (!file_exists($filepath)) {
            return;
        }

        $periodLabel = match ($this->period) {
            'today' => ($this->locale === 'en' ? "Today's Sales" : 'የዛሬ ሽያጭ'),
            'yesterday' => ($this->locale === 'en' ? "Yesterday's Sales" : 'የትናንት ሽያጭ'),
            'week' => ($this->locale === 'en' ? 'Last 7 Days Sales' : 'ያለፉት 7 ቀናት ሽያጭ'),
            'month' => ($this->locale === 'en' ? 'This Month Sales' : 'የዚህ ወር ሽያጭ'),
            default => null,
        };

        if ($periodLabel !== null) {
            $caption = $this->locale === 'en'
                ? "📊 <b>{$periodLabel} Export</b>\nFile: <code>{$filename}</code>"
                : "📊 <b>{$periodLabel} ኤክስፖርት</b>\nፋይል: <code>{$filename}</code>";
        } else {
            $caption = $this->locale === 'en'
                ? "📊 Export file ready: <b>{$filename}</b>"
                : "📊 የኤክስፖርት ፋይል ተዘጋጅቷል: <b>{$filename}</b>";
        }

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
        $locations = Location::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->whereNull('archived_at')
            ->orderBy('name', 'asc')
            ->get();

        $headers = ['ID', 'Product Name', 'SKU', 'Unit', 'Cost Price (ETB)', 'Sell Price (ETB)', 'Current Stock'];
        if ($locations->count() > 1) {
            foreach ($locations as $loc) {
                $headers[] = $loc->name . ' Stock';
            }
        }
        $headers[] = 'Status';
        fputcsv($fp, $headers);

        $products = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->whereNull('deleted_at_ms')
            ->orderBy('name', 'asc')
            ->get();

        foreach ($products as $p) {
            $stock = LedgerService::formatQty($ledger->stockOf($p));
            $row = [
                $p->id,
                $p->name,
                $p->sku ?? '',
                $p->unit,
                number_format($p->cost_price_cents / 100, 2),
                number_format($p->sell_price_cents / 100, 2),
                $stock,
            ];

            if ($locations->count() > 1) {
                foreach ($locations as $loc) {
                    $row[] = LedgerService::formatQty($ledger->stockOf($p, $loc));
                }
            }

            $row[] = $p->is_active ? 'Active' : 'Inactive';
            fputcsv($fp, $row);
        }
    }

    protected function exportSales($fp, Shop $shop, ?string $period = null, ?string $locationId = null): void
    {
        $tz = $shop->timezone ?: 'Africa/Addis_Ababa';
        $query = Movement::withoutGlobalScope(ShopScope::class)
            ->with([
                'product' => fn($q) => $q->withoutGlobalScope(ShopScope::class),
                'user' => fn($q) => $q->withoutGlobalScope(ShopScope::class),
                'location' => fn($q) => $q->withoutGlobalScope(ShopScope::class),
            ])
            ->where('shop_id', $shop->id)
            ->where('type', 'sale')
            ->orderBy('created_at_ms', 'desc');

        if (!empty($period)) {
            $now = Carbon::now($tz);
            [$startMs, $endMs] = match ($period) {
                'yesterday' => [
                    $now->copy()->subDay()->startOfDay()->timestamp * 1000,
                    $now->copy()->subDay()->endOfDay()->timestamp * 1000,
                ],
                'week' => [
                    $now->copy()->subDays(6)->startOfDay()->timestamp * 1000,
                    $now->copy()->endOfDay()->timestamp * 1000,
                ],
                'month' => [
                    $now->copy()->startOfMonth()->timestamp * 1000,
                    $now->copy()->endOfDay()->timestamp * 1000,
                ],
                default => [ // 'today'
                    $now->copy()->startOfDay()->timestamp * 1000,
                    $now->copy()->endOfDay()->timestamp * 1000,
                ],
            };
            $query->whereBetween('created_at_ms', [$startMs, $endMs]);
        }

        if (!empty($locationId) && $locationId !== 'all') {
            $query->where('location_id', $locationId);
        }

        $sales = $query->get();

        fputcsv($fp, ['Date/Time (Addis)', 'Product', 'Quantity Sold', 'Unit Price (ETB)', 'Total (ETB)', 'Location', 'Staff/User', 'Movement ID']);

        $totalQtyMilli = 0;
        $totalRevenueCents = 0;

        foreach ($sales as $s) {
            $dateAddis = Carbon::createFromTimestampMs($s->created_at_ms, $tz)->format('Y-m-d H:i:s');
            $qtyMilli = abs($s->qty_milli);
            $totalQtyMilli += $qtyMilli;
            $qty = LedgerService::formatQty($qtyMilli);
            $unitPrice = number_format($s->unit_price_cents / 100, 2);
            $saleTotalCents = (int) round(($qtyMilli * $s->unit_price_cents) / 1000);
            $totalRevenueCents += $saleTotalCents;
            $total = number_format($saleTotalCents / 100, 2);
            $locationName = $s->location?->name ?? 'Main';

            fputcsv($fp, [
                $dateAddis,
                $s->product?->name ?? 'Deleted Product',
                $qty,
                $unitPrice,
                $total,
                $locationName,
                $s->user?->name ?? 'System/Bot',
                $s->id,
            ]);
        }

        if ($sales->isNotEmpty()) {
            fputcsv($fp, [
                'TOTAL',
                '',
                LedgerService::formatQty($totalQtyMilli),
                '',
                number_format($totalRevenueCents / 100, 2),
                '',
                '',
                '',
            ]);
        }
    }

    protected function exportMovements($fp, Shop $shop): void
    {
        $tz = $shop->timezone ?: 'Africa/Addis_Ababa';
        fputcsv($fp, ['Date/Time (Addis)', 'Type', 'Product', 'Quantity Change', 'Unit Price (ETB)', 'Location', 'Transfer ID', 'Note', 'Staff/User', 'Seq']);

        $movements = Movement::withoutGlobalScope(ShopScope::class)
            ->with([
                'product' => fn($q) => $q->withoutGlobalScope(ShopScope::class),
                'user' => fn($q) => $q->withoutGlobalScope(ShopScope::class),
                'location' => fn($q) => $q->withoutGlobalScope(ShopScope::class),
            ])
            ->where('shop_id', $shop->id)
            ->orderBy('created_at_ms', 'desc')
            ->get();

        foreach ($movements as $m) {
            $dateAddis = Carbon::createFromTimestampMs($m->created_at_ms, $tz)->format('Y-m-d H:i:s');
            $qty = ($m->qty_milli > 0 ? '+' : '') . LedgerService::formatQty($m->qty_milli);
            $unitPrice = number_format($m->unit_price_cents / 100, 2);
            $locationName = $m->location?->name ?? 'Main';

            fputcsv($fp, [
                $dateAddis,
                strtoupper($m->type),
                $m->product?->name ?? 'Deleted Product',
                $qty,
                $unitPrice,
                $locationName,
                $m->transfer_id ?? '',
                $m->note ?? '',
                $m->user?->name ?? 'System/Bot',
                $m->server_seq,
            ]);
        }
    }

    protected function exportStockByLocation($fp, Shop $shop, LedgerService $ledger): void
    {
        fputcsv($fp, ['Location', 'Location Type', 'Product Name', 'SKU', 'Unit', 'Stock Qty', 'Cost Price (ETB)', 'Sell Price (ETB)', 'Stock Value at Cost (ETB)', 'Stock Value at Sell (ETB)']);

        $locations = Location::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->whereNull('archived_at')
            ->orderBy('name', 'asc')
            ->get();

        $products = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->whereNull('deleted_at_ms')
            ->orderBy('name', 'asc')
            ->get();

        $grandCostCents = 0;
        $grandSellCents = 0;

        foreach ($locations as $loc) {
            foreach ($products as $p) {
                $qtyMilli = $ledger->stockOf($p, $loc);
                if ($qtyMilli != 0) {
                    $qty = LedgerService::formatQty($qtyMilli);
                    $valCostCents = (int) round(($qtyMilli * $p->cost_price_cents) / 1000);
                    $valSellCents = (int) round(($qtyMilli * $p->sell_price_cents) / 1000);
                    $grandCostCents += $valCostCents;
                    $grandSellCents += $valSellCents;

                    fputcsv($fp, [
                        $loc->name,
                        ucfirst($loc->kind),
                        $p->name,
                        $p->sku ?? '',
                        $p->unit,
                        $qty,
                        number_format($p->cost_price_cents / 100, 2),
                        number_format($p->sell_price_cents / 100, 2),
                        number_format($valCostCents / 100, 2),
                        number_format($valSellCents / 100, 2),
                    ]);
                }
            }
        }

        fputcsv($fp, [
            'TOTAL',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            number_format($grandCostCents / 100, 2),
            number_format($grandSellCents / 100, 2),
        ]);
    }

    protected function exportTransit($fp, Shop $shop, LedgerService $ledger): void
    {
        fputcsv($fp, ['Transfer ID', 'Dispatched At (Addis)', 'Product', 'Quantity', 'Unit', 'Origin Location', 'Destination Location', 'Dispatched By']);

        $transits = $ledger->inTransit($shop);

        foreach ($transits as $t) {
            fputcsv($fp, [
                $t['transfer_id'] ?? '',
                $t['dispatched_at'] ?? '',
                $t['product_name'] ?? 'Product',
                $t['qty'] ?? '0',
                $t['unit'] ?? 'pcs',
                $t['from_location_name'] ?? '',
                $t['to_location_name'] ?? '',
                $t['sent_by'] ?? '',
            ]);
        }
    }

    protected function exportDiscrepancies($fp, Shop $shop, LedgerService $ledger): void
    {
        fputcsv($fp, ['Transfer ID', 'Received At (Addis)', 'Product', 'Origin Location', 'Destination Location', 'Sent Qty', 'Received Qty', 'Difference Qty', 'Unit', 'Received By']);

        $discrepancies = $ledger->discrepancies($shop);

        foreach ($discrepancies as $d) {
            $diffSign = ($d['difference_milli'] ?? 0) > 0 ? "+{$d['difference_qty']}" : ($d['difference_qty'] ?? '0');
            fputcsv($fp, [
                $d['transfer_id'] ?? '',
                $d['received_at'] ?? '',
                $d['product_name'] ?? 'Product',
                $d['from_location_name'] ?? '',
                $d['to_location_name'] ?? '',
                $d['sent_qty'] ?? '0',
                $d['received_qty'] ?? '0',
                $diffSign,
                $d['unit'] ?? 'pcs',
                $d['received_by'] ?? '',
            ]);
        }
    }

    protected function exportValuation($fp, Shop $shop, LedgerService $ledger): void
    {
        fputcsv($fp, ['Product Name', 'SKU', 'Unit', 'Total Stock', 'Cost Price (ETB)', 'Sell Price (ETB)', 'Total Value at Cost (ETB)', 'Total Value at Sell (ETB)', 'Potential Gross Margin (ETB)']);

        $stockList = $ledger->stockList((string) $shop->id);
        $grandCostCents = 0;
        $grandSellCents = 0;

        foreach ($stockList as $item) {
            $stockQty = $item->stock_milli / 1000;
            $itemCostCents = (int) round($stockQty * $item->cost_price_cents);
            $itemSellCents = (int) round($stockQty * $item->sell_price_cents);
            $itemMarginCents = max(0, $itemSellCents - $itemCostCents);

            $grandCostCents += $itemCostCents;
            $grandSellCents += $itemSellCents;

            fputcsv($fp, [
                $item->name,
                $item->sku ?? '',
                $item->unit,
                LedgerService::formatQty($item->stock_milli),
                number_format($item->cost_price_cents / 100, 2),
                number_format($item->sell_price_cents / 100, 2),
                number_format($itemCostCents / 100, 2),
                number_format($itemSellCents / 100, 2),
                number_format($itemMarginCents / 100, 2),
            ]);
        }

        fputcsv($fp, [
            'TOTAL',
            '',
            '',
            '',
            '',
            '',
            number_format($grandCostCents / 100, 2),
            number_format($grandSellCents / 100, 2),
            number_format(($grandSellCents - $grandCostCents) / 100, 2),
        ]);
    }
}
