<?php

declare(strict_types=1);

namespace App\Services;

use App\Context\CurrentShopContext;
use App\Exceptions\CrossShopViolationException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\MissingShopContextException;
use App\Models\Change;
use App\Models\Device;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Support\ImmediateTransaction;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SalesService
{
    public function __construct(
        protected ?LedgerService $ledger = null
    ) {}

    protected function getLedger(): LedgerService
    {
        return $this->ledger ?? app(LedgerService::class);
    }

    /**
     * Add a sale line to a sale, applying item kind and material consumption rules.
     *
     * @return array{sale_movement: Movement, consume_movements: array<int, Movement>, warnings: array<int, string>}
     */
    public function addLine(
        Sale|string $sale,
        Product|string $product,
        int|string|float $qty,
        ?int $unitPriceCents = null,
        Location|string|null $location = null,
        ?User $user = null,
        ?Device $device = null,
        ?string $note = null,
        ?int $createdAtMs = null
    ): array {
        $saleModel = $sale instanceof Sale
            ? $sale
            : Sale::withoutGlobalScope(ShopScope::class)->find($sale);

        if ($saleModel === null) {
            throw new InvalidArgumentException("Sale [{$sale}] does not exist.");
        }

        $shopId = (string) $saleModel->shop_id;
        $shop = Shop::find($shopId);

        $productModel = $product instanceof Product
            ? $product
            : Product::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('id', $product)
                ->first();

        if ($productModel === null) {
            throw new InvalidArgumentException("Product [{$product}] does not exist in shop [{$shopId}].");
        }

        if ((string) $productModel->shop_id !== $shopId) {
            throw new CrossShopViolationException("Product does not belong to sale shop [{$shopId}].");
        }

        // Resolve location
        $locationId = null;
        if ($location instanceof Location) {
            $locationId = (string) $location->id;
        } elseif (is_string($location) && $location !== '') {
            $locationId = $location;
        } else {
            $locationId = $saleModel->location_id ?: $shop?->getDefaultLocation()->id;
        }

        $soldQtyMilli = abs(LedgerService::parseQty($qty));
        if ($soldQtyMilli <= 0) {
            throw new InvalidArgumentException('Sale line quantity must be greater than zero.');
        }

        $priceCents = $unitPriceCents !== null ? $unitPriceCents : (int) $productModel->sell_price_cents;
        $nowMs = $createdAtMs ?: (int) (microtime(true) * 1000);

        return ImmediateTransaction::run(function () use (
            $saleModel,
            $productModel,
            $shop,
            $shopId,
            $locationId,
            $soldQtyMilli,
            $priceCents,
            $user,
            $device,
            $note,
            $nowMs
        ): array {
            $ledger = $this->getLedger();
            $saleMovId = (string) Str::uuid7();
            $warnings = [];
            $consumeMovements = [];

            if ($productModel->isStockable()) {
                // 1. Stockable: sale movement with tracks_stock=true
                $saleMovement = new Movement([
                    'id' => $saleMovId,
                    'shop_id' => $shopId,
                    'product_id' => $productModel->id,
                    'location_id' => $locationId,
                    'sale_id' => $saleModel->id,
                    'user_id' => $user?->id ?? $saleModel->user_id,
                    'device_id' => $device?->id ?? $saleModel->device_id,
                    'type' => 'sale',
                    'tracks_stock' => true,
                    'source_movement_id' => null,
                    'qty_milli' => -$soldQtyMilli,
                    'unit_price_cents' => $priceCents,
                    'note' => $note,
                    'created_at_ms' => $nowMs,
                ]);

                $recordedSaleMov = $ledger->record($saleMovement);

                return [
                    'sale_movement' => $recordedSaleMov,
                    'consume_movements' => [],
                    'warnings' => [],
                ];
            }

            if ($productModel->isService()) {
                // 2. Service: sale movement with tracks_stock=false (negative qty, no stock effect)
                $saleMovement = new Movement([
                    'id' => $saleMovId,
                    'shop_id' => $shopId,
                    'product_id' => $productModel->id,
                    'location_id' => $locationId,
                    'sale_id' => $saleModel->id,
                    'user_id' => $user?->id ?? $saleModel->user_id,
                    'device_id' => $device?->id ?? $saleModel->device_id,
                    'type' => 'sale',
                    'tracks_stock' => false,
                    'source_movement_id' => null,
                    'qty_milli' => -$soldQtyMilli,
                    'unit_price_cents' => $priceCents,
                    'note' => $note,
                    'created_at_ms' => $nowMs,
                ]);

                $recordedSaleMov = $ledger->record($saleMovement);

                return [
                    'sale_movement' => $recordedSaleMov,
                    'consume_movements' => [],
                    'warnings' => [],
                ];
            }

            // 3. Consumable: sale movement with tracks_stock=false, plus consume movements for materials
            $saleMovement = new Movement([
                'id' => $saleMovId,
                'shop_id' => $shopId,
                'product_id' => $productModel->id,
                'location_id' => $locationId,
                'sale_id' => $saleModel->id,
                'user_id' => $user?->id ?? $saleModel->user_id,
                'device_id' => $device?->id ?? $saleModel->device_id,
                'type' => 'sale',
                'tracks_stock' => false,
                'source_movement_id' => null,
                'qty_milli' => -$soldQtyMilli,
                'unit_price_cents' => $priceCents,
                'note' => $note,
                'created_at_ms' => $nowMs,
            ]);

            $recordedSaleMov = $ledger->record($saleMovement);

            $materials = $productModel->materials ?: [];
            foreach ($materials as $mat) {
                $matProductId = (string) ($mat['product_id'] ?? '');
                $matQtyPerUnit = (int) ($mat['qty_milli'] ?? 0);

                if ($matProductId === '' || $matQtyPerUnit <= 0) {
                    continue;
                }

                $matProduct = Product::withoutGlobalScope(ShopScope::class)
                    ->where('shop_id', $shopId)
                    ->where('id', $matProductId)
                    ->first();

                if ($matProduct === null || !$matProduct->isStockable()) {
                    throw new InvalidArgumentException("Material [{$matProductId}] must be a stockable product.");
                }

                if ($matProduct->id === $productModel->id) {
                    throw new InvalidArgumentException('A consumable cannot use itself as raw material.');
                }

                // Deduction: -(material qty_milli * sold qty / 1000) rounded half-up
                $deductMilli = (int) round(($matQtyPerUnit * $soldQtyMilli) / 1000, 0, PHP_ROUND_HALF_UP);
                if ($deductMilli <= 0) {
                    continue;
                }

                // Check material stock
                $currentStock = $ledger->stockOf($matProduct, $locationId, $shopId) ?? 0;
                if ($currentStock - $deductMilli < 0) {
                    if ($shop?->block_sale_when_material_short) {
                        throw new InsufficientStockException(
                            sprintf(
                                'Shortage of raw material [%s] at location. Available: %s, Required: %s.',
                                $matProduct->name,
                                LedgerService::formatQty($currentStock),
                                LedgerService::formatQty($deductMilli)
                            )
                        );
                    }
                    $warnings[] = sprintf(
                        'Material shortage: [%s] available %s, required %s.',
                        $matProduct->name,
                        LedgerService::formatQty($currentStock),
                        LedgerService::formatQty($deductMilli)
                    );
                }

                // Record consume movement
                $consumeMov = new Movement([
                    'id' => (string) Str::uuid7(),
                    'shop_id' => $shopId,
                    'product_id' => $matProduct->id,
                    'location_id' => $locationId,
                    'sale_id' => $saleModel->id,
                    'user_id' => $user?->id ?? $saleModel->user_id,
                    'device_id' => $device?->id ?? $saleModel->device_id,
                    'type' => 'consume',
                    'tracks_stock' => true,
                    'source_movement_id' => $recordedSaleMov->id,
                    'qty_milli' => -$deductMilli,
                    'unit_price_cents' => 0,
                    'note' => "Consumed for {$productModel->name}",
                    'created_at_ms' => $nowMs,
                ]);

                $consumeMovements[] = $ledger->record($consumeMov);
            }

            return [
                'sale_movement' => $recordedSaleMov,
                'consume_movements' => $consumeMovements,
                'warnings' => $warnings,
            ];
        });
    }

    /**
     * Check if a product is used as raw material in any consumable's recipe within the shop.
     */
    public function isUsedAsMaterial(Product|string $product, ?string $shopId = null): bool
    {
        $productId = $product instanceof Product ? (string) $product->id : (string) $product;

        if ($shopId === null && $product instanceof Product) {
            $shopId = (string) $product->shop_id;
        }
        $shopId = $shopId ?: CurrentShopContext::getId();

        if (empty($shopId)) {
            throw new MissingShopContextException('Cannot check material usage without a shop context.');
        }

        $consumables = Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->whereNull('deleted_at_ms')
            ->whereNotNull('materials')
            ->get();

        foreach ($consumables as $cons) {
            $materials = $cons->materials;
            if (!is_array($materials)) {
                continue;
            }
            foreach ($materials as $m) {
                if (($m['product_id'] ?? '') === $productId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validate rules for changing an item's kind.
     *
     * - stockable -> service/consumable only when current stock is zero across all locations.
     * - service/consumable -> stockable allowed and starts at zero stock.
     * - A stockable product used as raw material cannot be changed to another kind or deleted.
     */
    public function validateKindChange(Product $product, string $newKind, ?string $shopId = null): void
    {
        $currentKind = $product->kind ?? 'stockable';
        if ($currentKind === $newKind) {
            return;
        }

        $shopId = $shopId ?: (string) $product->shop_id;

        // Protection for products used as material
        if ($currentKind === 'stockable' && $newKind !== 'stockable') {
            if ($this->isUsedAsMaterial($product, $shopId)) {
                throw new InvalidArgumentException('used_as_material');
            }
        }

        // stockable -> service/consumable only if stock is zero
        if ($currentKind === 'stockable' && in_array($newKind, ['service', 'consumable'], true)) {
            $currentStock = $this->getLedger()->stockOf($product->id, null, $shopId);
            if ($currentStock !== null && $currentStock !== 0) {
                throw new InvalidArgumentException('stock_not_zero');
            }
        }
    }

    /**
     * Change a product's kind, updating timestamps and recording changes feed.
     */
    public function changeKind(Product $product, string $newKind, ?int $changedAtMs = null, ?string $shopId = null): Product
    {
        $shopId = $shopId ?: (string) $product->shop_id;
        $this->validateKindChange($product, $newKind, $shopId);

        $nowMs = $changedAtMs ?: (int) (microtime(true) * 1000);

        $product->kind = $newKind;
        $product->kind_changed_at_ms = $nowMs;
        $product->updated_at_ms = $nowMs;
        $product->save();

        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $shopId,
            'device_id' => null,
            'entity' => 'product',
            'entity_id' => $product->id,
        ]);

        return $product;
    }
}
