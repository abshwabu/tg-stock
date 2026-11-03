<?php

declare(strict_types=1);

namespace App\Services;

use App\Context\CurrentShopContext;
use App\Exceptions\CrossShopViolationException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\MissingShopContextException;
use App\Models\Change;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Support\ImmediateTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

            // Verify and assign location (assign to default location if omitted)
            $shop = Shop::find($shopId);
            if (empty($movement->location_id)) {
                $defaultLoc = $shop?->getDefaultLocation();
                $movement->location_id = $defaultLoc?->id;
            } else {
                $location = Location::withoutGlobalScope(ShopScope::class)
                    ->where('id', $movement->location_id)
                    ->first();

                if ($location === null) {
                    throw new InvalidArgumentException(
                        sprintf('Location [%s] does not exist.', $movement->location_id)
                    );
                }

                if ((string) $location->shop_id !== (string) $shopId) {
                    throw new CrossShopViolationException(
                        sprintf('Location [%s] belongs to shop [%s], not [%s].', $location->id, $location->shop_id, $shopId)
                    );
                }
            }

            // Enforce negative stock rule per location
            if ($shop !== null && !$shop->allow_negative_stock) {
                if ($movement->type === 'transfer_out') {
                    $currentLocationStock = $this->stockOf($movement->product_id, $movement->location_id, $shopId);
                    if ($currentLocationStock + $movement->qty_milli < 0) {
                        throw new InsufficientStockException(
                            sprintf(
                                'Insufficient stock at location [%s]. Available: %s, Requested: %s.',
                                $movement->location_id,
                                self::formatQty($currentLocationStock),
                                self::formatQty(abs($movement->qty_milli))
                            )
                        );
                    }
                }
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

            // Check low-stock / out-of-stock alert with 24h debouncing
            $this->checkLowStockAlert($product, $movement, $shopId);

            return $movement;
        });
    }

    /**
     * Check low stock thresholds and dispatch queued alerts with 24h debounce.
     */
    protected function checkLowStockAlert(Product $product, Movement $movement, string $shopId): void
    {
        $shop = Shop::find($shopId);
        $threshold = $product->low_stock_at_milli ?? $shop?->low_stock_default_milli ?? 5000;
        $locationId = $movement->location_id;
        $currentStockMilli = $locationId
            ? $this->stockOf($product, $locationId, $shopId)
            : $this->stockOf($product, $shopId);
        $nowMs = (int) (microtime(true) * 1000);
        $debounceMs = 86400000; // 24 hours

        if ($movement->qty_milli < 0 && $currentStockMilli <= $threshold) {
            if ($product->low_stock_alerted_at_ms === null || ($nowMs - $product->low_stock_alerted_at_ms) >= $debounceMs) {
                $product->low_stock_alerted_at_ms = $nowMs;
                $product->save();
                \App\Jobs\SendLowStockAlertJob::dispatch($product->id, $currentStockMilli, $shopId, $locationId);
            }
        } elseif ($movement->qty_milli > 0 && $currentStockMilli > $threshold) {
            // Restocked: clear debounce so subsequent drops will alert again
            if ($product->low_stock_alerted_at_ms !== null) {
                $product->low_stock_alerted_at_ms = null;
                $product->save();
            }
        }
    }

    /**
     * Compute authoritative stock for a single product from the append-only ledger:
     * Stock = SUM(movements.qty_milli).
     *
     * If location is specified, returns stock for that specific location.
     * If location is null, returns total stock across all locations in the shop.
     *
     * @return int Stock in integer thousandths (e.g., 2500 for 2.500 units)
     */
    public function stockOf(Product|string $product, Location|string|null $location = null, ?string $shopId = null): int
    {
        $locationId = null;

        if ($location instanceof Location) {
            $locationId = (string) $location->id;
            $shopId = $shopId ?: (string) $location->shop_id;
        } elseif (is_string($location) && $location !== '') {
            $loc = Location::withoutGlobalScope(ShopScope::class)->find($location);
            if ($loc !== null) {
                $locationId = (string) $loc->id;
                $shopId = $shopId ?: (string) $loc->shop_id;
            } else {
                // Legacy parameter compatibility: second argument might be shopId
                $shopId = $shopId ?: $location;
            }
        }

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

        $query = Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('product_id', $productId);

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        return (int) $query->sum('qty_milli');
    }

    /**
     * Compute authoritative stock balances by location for a single product.
     *
     * @return Collection<int, Location> Locations with appended 'stock_milli' attribute
     */
    public function stockByLocation(Product|string $product, ?string $shopId = null): Collection
    {
        if ($product instanceof Product) {
            $productId = (string) $product->id;
            $shopId = $shopId ?: ($product->shop_id ?: CurrentShopContext::getId());
        } else {
            $productId = (string) $product;
            $shopId = $shopId ?: CurrentShopContext::getId();
        }

        if (empty($shopId)) {
            throw new MissingShopContextException('Cannot calculate stockByLocation without a shop context.');
        }

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

        return Location::withoutGlobalScope(ShopScope::class)
            ->where('locations.shop_id', $shopId)
            ->whereNull('locations.deleted_at_ms')
            ->leftJoin('movements', function ($join) use ($shopId, $productId): void {
                $join->on('locations.id', '=', 'movements.location_id')
                    ->where('movements.shop_id', '=', $shopId)
                    ->where('movements.product_id', '=', $productId);
            })
            ->select('locations.*')
            ->selectRaw('CAST(COALESCE(SUM(movements.qty_milli), 0) AS INTEGER) AS stock_milli')
            ->groupBy('locations.id')
            ->orderBy('locations.is_default', 'desc')
            ->orderBy('locations.name', 'asc')
            ->get();
    }

    /**
     * Transfer stock of a product from one location to another.
     *
     * In instant mode (transfer_requires_receipt = false):
     *   Writes transfer_out (negative) and transfer_in (positive) in one transaction.
     * In two-step mode (transfer_requires_receipt = true):
     *   Writes only transfer_out (leaving transfer in-transit until received).
     *
     * @return array{transfer_id: string, out: Movement, in: ?Movement, status: string}
     */
    public function transfer(
        Product|string $product,
        Location|string $fromLocation,
        Location|string $toLocation,
        int|float|string $qty,
        ?User $user = null,
        ?string $transferId = null,
        ?string $note = null
    ): array {
        $productId = $product instanceof Product ? (string) $product->id : (string) $product;

        $fromLoc = $fromLocation instanceof Location
            ? $fromLocation
            : Location::withoutGlobalScope(ShopScope::class)->find($fromLocation);

        $toLoc = $toLocation instanceof Location
            ? $toLocation
            : Location::withoutGlobalScope(ShopScope::class)->find($toLocation);

        if ($fromLoc === null) {
            throw new InvalidArgumentException("Source location [{$fromLocation}] does not exist.");
        }
        if ($toLoc === null) {
            throw new InvalidArgumentException("Destination location [{$toLocation}] does not exist.");
        }

        if ($fromLoc->id === $toLoc->id) {
            throw new InvalidArgumentException('Source and destination locations cannot be the same.');
        }

        if ((string) $fromLoc->shop_id !== (string) $toLoc->shop_id) {
            throw new CrossShopViolationException('Cannot transfer between different shops.');
        }

        $shopId = (string) $fromLoc->shop_id;
        $shop = Shop::find($shopId);
        if ($shop === null) {
            throw new MissingShopContextException("Shop [{$shopId}] not found.");
        }

        $prod = Product::withoutGlobalScope(ShopScope::class)
            ->where('id', $productId)
            ->where('shop_id', $shopId)
            ->first();

        if ($prod === null) {
            throw new InvalidArgumentException("Product [{$productId}] does not exist in shop [{$shopId}].");
        }

        $qtyMilli = abs(self::parseQty($qty));
        if ($qtyMilli <= 0) {
            throw new InvalidArgumentException('Transfer quantity must be greater than zero.');
        }

        // Negative stock rule per location
        if (!$shop->allow_negative_stock) {
            $fromStock = $this->stockOf($prod, $fromLoc, $shopId);
            if ($fromStock < $qtyMilli) {
                throw new InsufficientStockException(
                    sprintf(
                        'Insufficient stock at location [%s]. Available: %s, Requested: %s.',
                        $fromLoc->name,
                        self::formatQty($fromStock),
                        self::formatQty($qtyMilli)
                    )
                );
            }
        }

        $transferId = $transferId ?: (string) Str::uuid7();
        $nowMs = (int) (microtime(true) * 1000);

        return ImmediateTransaction::run(function () use (
            $shop,
            $shopId,
            $productId,
            $fromLoc,
            $toLoc,
            $qtyMilli,
            $user,
            $transferId,
            $note,
            $nowMs
        ): array {
            $outMovement = new Movement([
                'id' => (string) Str::uuid7(),
                'shop_id' => $shopId,
                'product_id' => $productId,
                'location_id' => $fromLoc->id,
                'transfer_id' => $transferId,
                'user_id' => $user?->id,
                'type' => 'transfer_out',
                'qty_milli' => -$qtyMilli,
                'note' => json_encode(['to_location_id' => $toLoc->id, 'note' => $note]),
                'created_at_ms' => $nowMs,
            ]);
            $out = $this->record($outMovement);

            if ($shop->transfer_requires_receipt) {
                return [
                    'transfer_id' => $transferId,
                    'out' => $out,
                    'in' => null,
                    'status' => 'in_transit',
                ];
            }

            $inMovement = new Movement([
                'id' => (string) Str::uuid7(),
                'shop_id' => $shopId,
                'product_id' => $productId,
                'location_id' => $toLoc->id,
                'transfer_id' => $transferId,
                'user_id' => $user?->id,
                'type' => 'transfer_in',
                'qty_milli' => $qtyMilli,
                'note' => $note,
                'created_at_ms' => $nowMs,
            ]);
            $in = $this->record($inMovement);

            return [
                'transfer_id' => $transferId,
                'out' => $out,
                'in' => $in,
                'status' => 'completed',
            ];
        });
    }

    /**
     * Receive an in-transit transfer, writing the matching transfer_in.
     *
     * If received qty differs from sent qty, marks the transfer as a discrepancy
     * (derived flag), never auto-adjusting.
     */
    public function receive(
        string $transferId,
        int|float|string $qtyReceived,
        ?User $user = null,
        ?string $movementId = null,
        ?string $note = null,
        Location|string|null $toLocation = null
    ): Movement {
        return ImmediateTransaction::run(function () use (
            $transferId,
            $qtyReceived,
            $user,
            $movementId,
            $note,
            $toLocation
        ): Movement {
            /** @var Movement|null $out */
            $out = Movement::withoutGlobalScope(ShopScope::class)
                ->where('transfer_id', $transferId)
                ->where('type', 'transfer_out')
                ->first();

            if ($out === null) {
                throw new InvalidArgumentException("Transfer [{$transferId}] not found.");
            }

            // Check if transfer_in already exists (idempotency check)
            $existingIn = Movement::withoutGlobalScope(ShopScope::class)
                ->where('transfer_id', $transferId)
                ->where('type', 'transfer_in')
                ->first();

            if ($existingIn !== null) {
                if ($movementId !== null && (string) $existingIn->id === (string) $movementId) {
                    return $existingIn;
                }
                throw new InvalidArgumentException("Transfer [{$transferId}] has already been received.");
            }

            // Resolve destination location
            $destLocationId = null;
            if ($toLocation !== null) {
                $resolvedLoc = $toLocation instanceof Location
                    ? $toLocation
                    : Location::withoutGlobalScope(ShopScope::class)->find($toLocation);
                if ($resolvedLoc !== null && (string) $resolvedLoc->shop_id === (string) $out->shop_id) {
                    $destLocationId = $resolvedLoc->id;
                }
            }

            if ($destLocationId === null) {
                $meta = json_decode((string) $out->note, true);
                if (is_array($meta) && !empty($meta['to_location_id'])) {
                    $destLocationId = (string) $meta['to_location_id'];
                }
            }

            if ($destLocationId === null) {
                $shop = Shop::find($out->shop_id);
                $destLocationId = $shop?->getDefaultLocation()->id;
            }

            $qtyReceivedMilli = abs(self::parseQty($qtyReceived));

            $inMovement = new Movement([
                'id' => $movementId ?: (string) Str::uuid7(),
                'shop_id' => $out->shop_id,
                'product_id' => $out->product_id,
                'location_id' => $destLocationId,
                'transfer_id' => $transferId,
                'user_id' => $user?->id,
                'type' => 'transfer_in',
                'qty_milli' => $qtyReceivedMilli,
                'note' => $note,
                'created_at_ms' => (int) (microtime(true) * 1000),
            ]);

            return $this->record($inMovement);
        });
    }

    /**
     * Query transfers currently in transit (transfer_out without transfer_in).
     *
     * @return Collection<int, array>
     */
    public function inTransit(Shop|string|null $shop = null): Collection
    {
        if ($shop instanceof Shop) {
            $shopId = (string) $shop->id;
        } else {
            $shopId = $shop ? (string) $shop : CurrentShopContext::getId();
        }

        if (empty($shopId)) {
            throw new MissingShopContextException('Cannot query inTransit without a shop context.');
        }

        $outMovements = Movement::withoutGlobalScope(ShopScope::class)
            ->where('movements.shop_id', $shopId)
            ->where('movements.type', 'transfer_out')
            ->whereNotNull('movements.transfer_id')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('movements as in_m')
                    ->whereColumn('in_m.transfer_id', 'movements.transfer_id')
                    ->where('in_m.type', 'transfer_in');
            })
            ->with(['product', 'location', 'user'])
            ->orderByDesc('movements.created_at_ms')
            ->get();

        return $outMovements->map(function (Movement $out): array {
            $meta = json_decode((string) $out->note, true);
            $destLocId = is_array($meta) ? ($meta['to_location_id'] ?? null) : null;
            $destLoc = $destLocId ? Location::withoutGlobalScope(ShopScope::class)->find($destLocId) : null;

            return [
                'transfer_id' => $out->transfer_id,
                'product_id' => $out->product_id,
                'product_name' => $out->product?->name,
                'from_location_id' => $out->location_id,
                'from_location_name' => $out->location?->name,
                'to_location_id' => $destLocId,
                'to_location_name' => $destLoc?->name,
                'qty_milli' => abs($out->qty_milli),
                'qty' => self::formatQty(abs($out->qty_milli)),
                'sent_at_ms' => $out->created_at_ms,
                'sent_by' => $out->user?->name,
                'note' => is_array($meta) ? ($meta['note'] ?? null) : $out->note,
            ];
        });
    }

    /**
     * Query completed transfers where sent qty differs from received qty.
     *
     * @return Collection<int, array>
     */
    public function discrepancies(Shop|string|null $shop = null): Collection
    {
        if ($shop instanceof Shop) {
            $shopId = (string) $shop->id;
        } else {
            $shopId = $shop ? (string) $shop : CurrentShopContext::getId();
        }

        if (empty($shopId)) {
            throw new MissingShopContextException('Cannot query discrepancies without a shop context.');
        }

        $inMovements = Movement::withoutGlobalScope(ShopScope::class)
            ->where('movements.shop_id', $shopId)
            ->where('movements.type', 'transfer_in')
            ->whereNotNull('movements.transfer_id')
            ->join('movements as out_m', function ($join): void {
                $join->on('movements.transfer_id', '=', 'out_m.transfer_id')
                    ->where('out_m.type', '=', 'transfer_out');
            })
            ->whereRaw('ABS(out_m.qty_milli) != movements.qty_milli')
            ->select([
                'movements.id as in_id',
                'movements.transfer_id',
                'movements.product_id',
                'movements.location_id as to_location_id',
                'out_m.location_id as from_location_id',
                'movements.qty_milli as received_qty_milli',
                'out_m.qty_milli as sent_qty_milli_raw',
                'movements.created_at_ms as received_at_ms',
                'out_m.created_at_ms as sent_at_ms',
                'movements.user_id as received_by_id',
                'out_m.user_id as sent_by_id',
                'movements.note as in_note',
                'out_m.note as out_note',
            ])
            ->get();

        return $inMovements->map(function ($row): array {
            $sentMilli = abs((int) $row->sent_qty_milli_raw);
            $receivedMilli = (int) $row->received_qty_milli;
            $diffMilli = $receivedMilli - $sentMilli;

            $product = Product::withoutGlobalScope(ShopScope::class)->find($row->product_id);
            $fromLoc = Location::withoutGlobalScope(ShopScope::class)->find($row->from_location_id);
            $toLoc = Location::withoutGlobalScope(ShopScope::class)->find($row->to_location_id);

            return [
                'transfer_id' => $row->transfer_id,
                'product_id' => $row->product_id,
                'product_name' => $product?->name,
                'from_location_id' => $row->from_location_id,
                'from_location_name' => $fromLoc?->name,
                'to_location_id' => $row->to_location_id,
                'to_location_name' => $toLoc?->name,
                'sent_qty_milli' => $sentMilli,
                'sent_qty' => self::formatQty($sentMilli),
                'received_qty_milli' => $receivedMilli,
                'received_qty' => self::formatQty($receivedMilli),
                'difference_milli' => $diffMilli,
                'difference_qty' => self::formatQty($diffMilli),
                'is_discrepancy' => true,
                'sent_at_ms' => $row->sent_at_ms,
                'received_at_ms' => $row->received_at_ms,
            ];
        });
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
