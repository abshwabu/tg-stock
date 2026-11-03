<?php

declare(strict_types=1);

namespace App\Services;

use App\Context\CurrentShopContext;
use App\Exceptions\CrossShopViolationException;
use App\Exceptions\MissingShopContextException;
use App\Models\Change;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Shop;
use App\Scopes\ShopScope;
use App\Support\ImmediateTransaction;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class LedgerService
{
    /**
     * Record a movement into the append-only ledger idempotently.
     *
     * Invariants:
     * - Executed within a BEGIN IMMEDIATE transaction.
     * - Replays of the same movement UUID are harmless no-ops.
     * - Product must belong to the same shop as the movement.
     * - Stamps server_seq monotonically per shop.
     * - Appends a change entry into the changes table.
     */
    public function record(Movement $movement): Movement
    {
        // 1. Resolve and validate tenant shop context
        $shopId = $movement->shop_id ?: CurrentShopContext::getId();

        if (empty($shopId)) {
            throw new MissingShopContextException('Cannot record movement without a shop_id or an active CurrentShopContext.');
        }

        if (CurrentShopContext::has() && (string) $movement->shop_id !== '' && (string) $movement->shop_id !== (string) CurrentShopContext::getId()) {
            throw new CrossShopViolationException(
                sprintf('Movement shop_id [%s] does not match active CurrentShopContext [%s].', $movement->shop_id, CurrentShopContext::getId())
            );
        }

        $movement->shop_id = (string) $shopId;

        // 2. Execute within an immediate write transaction
        return ImmediateTransaction::run(function () use ($movement, $shopId): Movement {
            // Idempotency check: if movement already exists by client-generated UUID
            $existing = Movement::withoutGlobalScope(ShopScope::class)
                ->where('id', $movement->id)
                ->first();

            if ($existing !== null) {
                if ((string) $existing->shop_id !== (string) $shopId) {
                    throw new CrossShopViolationException(
                        sprintf('Movement ID [%s] is already recorded under shop [%s].', $movement->id, $existing->shop_id)
                    );
                }

                // Idempotent replay: return existing record without duplicate changes or modifications
                return $existing;
            }

            // Verify the product exists and strictly belongs to this shop
            $product = Product::withoutGlobalScope(ShopScope::class)
                ->where('id', $movement->product_id)
                ->first();

            if ($product === null) {
                throw new InvalidArgumentException(
                    sprintf('Product [%s] does not exist.', $movement->product_id)
                );
            }

            if ((string) $product->shop_id !== (string) $shopId) {
                throw new CrossShopViolationException(
                    sprintf('Product [%s] belongs to shop [%s], not [%s].', $product->id, $product->shop_id, $shopId)
                );
            }

            // Stamp monotonic per-shop server_seq
            $maxSeq = (int) Movement::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->max('server_seq');

            $movement->server_seq = $maxSeq + 1;

            if (empty($movement->created_at_ms)) {
                $movement->created_at_ms = (int) (microtime(true) * 1000);
            }

            // Save movement record (append-only)
            $movement->save();

            // Append change cursor entry for sync clients
            Change::withoutGlobalScope(ShopScope::class)->create([
                'shop_id' => $shopId,
                'device_id' => $movement->device_id,
                'entity' => 'movement',
                'entity_id' => $movement->id,
            ]);

            return $movement;
        });
    }

    /**
     * Compute authoritative stock for a single product from the append-only ledger:
     * Stock = SUM(movements.qty_milli).
     *
     * @return int Stock in integer thousandths (e.g., 2500 for 2.500 units)
     */
    public function stockOf(Product|string $product, ?string $shopId = null): int
    {
        if ($product instanceof Product) {
            $productId = (string) $product->id;
            if ($shopId === null) {
                if (CurrentShopContext::has() && (string) $product->shop_id !== (string) CurrentShopContext::getId()) {
                    throw new CrossShopViolationException(
                        sprintf('Product [%s] belongs to shop [%s], not active context [%s].', $productId, $product->shop_id, CurrentShopContext::getId())
                    );
                }
                $shopId = CurrentShopContext::has() ? CurrentShopContext::getId() : (string) $product->shop_id;
            }
        } else {
            $productId = (string) $product;
            $shopId = $shopId ?: CurrentShopContext::getId();
        }

        if (empty($shopId)) {
            throw new MissingShopContextException('Cannot calculate stockOf without a shop context.');
        }

        // Verify product exists and belongs to this shop
        $prod = Product::withoutGlobalScope(ShopScope::class)
            ->where('id', $productId)
            ->first();

        if ($prod === null) {
            throw new InvalidArgumentException(sprintf('Product [%s] does not exist.', $productId));
        }

        if ((string) $prod->shop_id !== (string) $shopId) {
            throw new CrossShopViolationException(
                sprintf('Product [%s] belongs to shop [%s], not [%s].', $productId, $prod->shop_id, $shopId)
            );
        }

        return (int) Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('product_id', $productId)
            ->sum('qty_milli');
    }

    /**
     * Compute authoritative stock balances for all active products in a shop.
     *
     * @return Collection<int, Product> Products with appended 'stock_milli' attribute
     */
    public function stockList(Shop|string|null $shop = null): Collection
    {
        if ($shop instanceof Shop) {
            $shopId = (string) $shop->id;
        } else {
            $shopId = $shop ? (string) $shop : CurrentShopContext::getId();
        }

        if (empty($shopId)) {
            throw new MissingShopContextException('Cannot retrieve stockList without a shop context.');
        }

        return Product::withoutGlobalScope(ShopScope::class)
            ->where('products.shop_id', $shopId)
            ->whereNull('products.deleted_at_ms')
            ->leftJoin('movements', function ($join) use ($shopId): void {
                $join->on('products.id', '=', 'movements.product_id')
                    ->where('movements.shop_id', '=', $shopId);
            })
            ->select('products.*')
            ->selectRaw('CAST(COALESCE(SUM(movements.qty_milli), 0) AS INTEGER) AS stock_milli')
            ->groupBy('products.id')
            ->orderBy('products.name')
            ->get();
    }

    /**
     * Parse a quantity wire string or number into integer thousandths.
     * Examples: "2.500" => 2500, "0.125" => 125, "-1.500" => -1500, 10 => 10000.
     */
    public static function parseQty(string|int|float $qty): int
    {
        if (is_int($qty)) {
            return $qty;
        }

        $str = trim((string) $qty);
        $isNegative = str_starts_with($str, '-');
        if ($isNegative || str_starts_with($str, '+')) {
            $str = substr($str, 1);
        }

        $parts = explode('.', $str, 2);
        $whole = $parts[0] === '' ? 0 : (int) $parts[0];
        $fraction = 0;

        if (isset($parts[1])) {
            $fracStr = substr($parts[1] . '000', 0, 3);
            $fraction = (int) $fracStr;
        }

        $milli = ($whole * 1000) + $fraction;
        return $isNegative ? -$milli : $milli;
    }

    /**
     * Format integer thousandths into a canonical 3-decimal wire format string.
     * Examples: 2500 => "2.500", 125 => "0.125", -1500 => "-1.500", 0 => "0.000".
     */
    public static function formatQty(int $milli): string
    {
        $isNegative = $milli < 0;
        $abs = abs($milli);
        $whole = intdiv($abs, 1000);
        $fraction = $abs % 1000;

        $formatted = sprintf('%d.%03d', $whole, $fraction);
        return $isNegative ? '-' . $formatted : $formatted;
    }
}
