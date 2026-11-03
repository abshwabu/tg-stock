<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Change;
use App\Models\Device;
use App\Models\Movement;
use App\Models\Product;
use App\Scopes\ShopScope;
use App\Support\ImmediateTransaction;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SyncService
{
    public const MAX_CHANGES_PER_REQUEST = 200;
    public const MAX_BODY_BYTES = 262144; // 256 KB
    public const PAGE_SIZE = 300;
    public const MAX_FUTURE_DRIFT_MS = 300000; // 5 minutes

    public function __construct(
        protected LedgerService $ledger
    ) {}

    /**
     * Process an incoming sync batch and return incremental server changes.
     *
     * @param Device $device Current authenticated device
     * @param array $payload Validated sync payload
     * @param int $rawContentLength Size of raw HTTP request body in bytes
     * @return array{accepted: string[], rejected: array<int, array{id: string, reason: string}>, changes: array, cursor: int}
     */
    public function sync(Device $device, array $payload, int $rawContentLength): array
    {
        // 1. Enforce payload size constraint
        if ($rawContentLength > self::MAX_BODY_BYTES) {
            throw new InvalidArgumentException('Sync payload exceeds maximum size of 256 KB.');
        }

        // 2. Strict root-level validation (reject unknown keys)
        $allowedRootKeys = ['cursor', 'changes'];
        $unknownRootKeys = array_diff(array_keys($payload), $allowedRootKeys);
        if (!empty($unknownRootKeys)) {
            throw new InvalidArgumentException(
                'Unknown root field(s): ' . implode(', ', $unknownRootKeys)
            );
        }

        $cursor = isset($payload['cursor']) ? (int) $payload['cursor'] : 0;
        $incomingChanges = $payload['changes'] ?? [];

        if (count($incomingChanges) > self::MAX_CHANGES_PER_REQUEST) {
            throw new InvalidArgumentException(
                sprintf('Batch size exceeds maximum of %d incoming changes.', self::MAX_CHANGES_PER_REQUEST)
            );
        }

        $shopId = (string) $device->shop_id;
        $nowMs = (int) (microtime(true) * 1000);

        $accepted = [];
        $rejected = [];

        // 3. Ingest incoming client changes within an immediate write transaction
        // Sort items so products are processed before movements to guarantee foreign key integrity
        $productItems = [];
        $movementItems = [];
        $otherItems = [];
        foreach ($incomingChanges as $item) {
            $entity = is_array($item) ? ($item['entity'] ?? '') : '';
            if ($entity === 'product') {
                $productItems[] = $item;
            } elseif ($entity === 'movement') {
                $movementItems[] = $item;
            } else {
                $otherItems[] = $item;
            }
        }
        $sortedItems = array_merge($productItems, $movementItems, $otherItems);

        ImmediateTransaction::run(function () use (
            $device,
            $shopId,
            $sortedItems,
            $nowMs,
            &$accepted,
            &$rejected
        ): void {
            foreach ($sortedItems as $item) {
                $this->processIncomingItem($device, $shopId, $item, $nowMs, $accepted, $rejected);
            }
        });

        // 4. Query server changes after cursor made by other devices or Telegram bot
        $changeQuery = Change::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('seq', '>', $cursor)
            ->where(function ($q) use ($device): void {
                $q->whereNull('device_id')
                    ->orWhere('device_id', '!=', $device->id);
            })
            ->orderBy('seq', 'asc')
            ->limit(self::PAGE_SIZE);

        $changeRecords = $changeQuery->get();

        $outgoingChanges = [];
        foreach ($changeRecords as $rec) {
            $formatted = $this->formatChangeRecord($shopId, $rec);
            if ($formatted !== null) {
                $outgoingChanges[] = $formatted;
            }
        }

        // Determine next cursor: advance to the highest sequence processed
        $maxShopSeq = (int) Change::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->max('seq');

        if ($changeRecords->isNotEmpty()) {
            $nextCursor = (int) $changeRecords->last()->seq;
        } else {
            // When no changes from other devices exist, advance to server's highest seq
            $nextCursor = max($cursor, $maxShopSeq);
        }

        return [
            'accepted' => $accepted,
            'rejected' => $rejected,
            'changes' => $outgoingChanges,
            'cursor' => $nextCursor,
        ];
    }

    /**
     * Process a single incoming change item.
     */
    protected function processIncomingItem(
        Device $device,
        string $shopId,
        mixed $item,
        int $nowMs,
        array &$accepted,
        array &$rejected
    ): void {
        if (!is_array($item)) {
            $rejected[] = ['id' => 'unknown', 'reason' => 'Change item must be an object.'];
            return;
        }

        // Strict item fields validation
        $allowedItemKeys = ['id', 'entity', 'op', 'data', 'client_ts'];
        $unknownItemKeys = array_diff(array_keys($item), $allowedItemKeys);
        if (!empty($unknownItemKeys)) {
            $id = (string) ($item['id'] ?? 'unknown');
            $rejected[] = ['id' => $id, 'reason' => 'Unknown field(s) in change item: ' . implode(', ', $unknownItemKeys)];
            return;
        }

        $id = (string) ($item['id'] ?? '');
        $entity = (string) ($item['entity'] ?? '');
        $op = (string) ($item['op'] ?? '');
        $data = $item['data'] ?? [];
        $clientTs = (int) ($item['client_ts'] ?? $nowMs);

        if (empty($id)) {
            $rejected[] = ['id' => 'unknown', 'reason' => 'Missing change id.'];
            return;
        }

        if (!is_array($data)) {
            $rejected[] = ['id' => $id, 'reason' => 'Change data must be an object.'];
            return;
        }

        // Clamp client timestamp more than 5 minutes in the future to server time
        if ($clientTs > $nowMs + self::MAX_FUTURE_DRIFT_MS) {
            $clientTs = $nowMs;
        }

        if ($entity === 'movement') {
            $this->processMovementChange($device, $shopId, $id, $op, $data, $clientTs, $nowMs, $accepted, $rejected);
        } elseif ($entity === 'product') {
            $this->processProductChange($device, $shopId, $id, $op, $data, $clientTs, $nowMs, $accepted, $rejected);
        } else {
            $rejected[] = ['id' => $id, 'reason' => "Unsupported entity [{$entity}]."];
        }
    }

    /**
     * Ingest an incoming movement change (append-only ledger).
     */
    protected function processMovementChange(
        Device $device,
        string $shopId,
        string $id,
        string $op,
        array $data,
        int $clientTs,
        int $nowMs,
        array &$accepted,
        array &$rejected
    ): void {
        if ($op === 'delete') {
            $rejected[] = ['id' => $id, 'reason' => 'Movements cannot be deleted (append-only ledger).'];
            return;
        }

        // Strict data field check
        $allowedFields = ['id', 'product_id', 'type', 'qty', 'unit_price_cents', 'note', 'created_at'];
        $unknown = array_diff(array_keys($data), $allowedFields);
        if (!empty($unknown)) {
            $rejected[] = ['id' => $id, 'reason' => 'Unknown field(s) in movement data: ' . implode(', ', $unknown)];
            return;
        }

        $productId = (string) ($data['product_id'] ?? '');
        $type = (string) ($data['type'] ?? '');
        $rawQty = $data['qty'] ?? '0.000';

        if (!in_array($type, ['in', 'sale', 'adjust'], true)) {
            $rejected[] = ['id' => $id, 'reason' => "Invalid movement type [{$type}]. Must be in, sale, or adjust."];
            return;
        }

        $createdMs = (int) ($data['created_at'] ?? $clientTs);
        if ($createdMs > $nowMs + self::MAX_FUTURE_DRIFT_MS) {
            $createdMs = $nowMs;
        }

        // Verify product belongs to shop
        $product = Product::withoutGlobalScope(ShopScope::class)
            ->where('id', $productId)
            ->where('shop_id', $shopId)
            ->first();

        if ($product === null) {
            $rejected[] = ['id' => $id, 'reason' => "Referenced product [{$productId}] does not exist in this shop."];
            return;
        }

        $movement = new Movement([
            'id' => $id,
            'shop_id' => $shopId,
            'product_id' => $productId,
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'type' => $type,
            'qty_milli' => LedgerService::parseQty($rawQty),
            'unit_price_cents' => isset($data['unit_price_cents']) ? (int) $data['unit_price_cents'] : 0,
            'note' => isset($data['note']) ? (string) $data['note'] : null,
            'created_at_ms' => $createdMs,
        ]);

        try {
            $this->ledger->record($movement);
            $accepted[] = $id;
        } catch (\Throwable $e) {
            $rejected[] = ['id' => $id, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Ingest an incoming product change (Last-Write-Wins).
     */
    protected function processProductChange(
        Device $device,
        string $shopId,
        string $id,
        string $op,
        array $data,
        int $clientTs,
        int $nowMs,
        array &$accepted,
        array &$rejected
    ): void {
        // Strict data field check
        $allowedFields = ['id', 'name', 'sku', 'unit', 'cost_price_cents', 'sell_price_cents', 'low_stock_at', 'is_active', 'updated_at'];
        $unknown = array_diff(array_keys($data), $allowedFields);
        if (!empty($unknown)) {
            $rejected[] = ['id' => $id, 'reason' => 'Unknown field(s) in product data: ' . implode(', ', $unknown)];
            return;
        }

        $updatedMs = (int) ($data['updated_at'] ?? $clientTs);
        if ($updatedMs > $nowMs + self::MAX_FUTURE_DRIFT_MS) {
            $updatedMs = $nowMs;
        }

        /** @var Product|null $existing */
        $existing = Product::withoutGlobalScope(ShopScope::class)
            ->where('id', $id)
            ->where('shop_id', $shopId)
            ->first();

        if ($op === 'delete') {
            if ($existing !== null) {
                // If existing product is strictly newer, delete is stale and loses
                if ($existing->updated_at_ms > $updatedMs) {
                    $rejected[] = ['id' => $id, 'reason' => 'stale_conflict: product updated_at is newer than delete client_ts'];
                    return;
                }

                $existing->deleted_at_ms = $updatedMs;
                $existing->is_active = false;
                $existing->updated_at_ms = $updatedMs;
                $existing->save();

                Change::withoutGlobalScope(ShopScope::class)->create([
                    'shop_id' => $shopId,
                    'device_id' => $device->id,
                    'entity' => 'product',
                    'entity_id' => $id,
                ]);
            }

            $accepted[] = $id;
            return;
        }

        // Handle upsert (LWW)
        if ($existing !== null) {
            // Check if incoming edit is stale
            if ($updatedMs < $existing->updated_at_ms) {
                // Stale-product edits lose correctly
                $rejected[] = [
                    'id' => $id,
                    'reason' => 'stale_conflict: incoming updated_at is older than server product updated_at',
                ];
                return;
            }

            // Tie breaker / replay handling when updated_at is equal
            if ($updatedMs === $existing->updated_at_ms) {
                $lastChange = Change::withoutGlobalScope(ShopScope::class)
                    ->where('shop_id', $shopId)
                    ->where('entity', 'product')
                    ->where('entity_id', $id)
                    ->orderByDesc('seq')
                    ->first();
                $existingDeviceId = $lastChange?->device_id ? (string) $lastChange->device_id : '';

                // Idempotent replay from the same device: accept without modifying or adding duplicate change
                if ($existingDeviceId !== '' && (string) $device->id === $existingDeviceId) {
                    $accepted[] = $id;
                    return;
                }

                // If from a different device, ties broken by device id (higher string wins)
                if ($existingDeviceId !== '' && (string) $device->id !== $existingDeviceId && strcmp((string) $device->id, $existingDeviceId) <= 0) {
                    $rejected[] = ['id' => $id, 'reason' => 'tie_breaker_lost: equal timestamp with lower or equal device id priority'];
                    return;
                }
            }

            // Apply update
            $existing->name = (string) ($data['name'] ?? $existing->name);
            $existing->sku = isset($data['sku']) && $data['sku'] !== '' ? (string) $data['sku'] : null;
            $existing->unit = (string) ($data['unit'] ?? $existing->unit);
            $existing->cost_price_cents = isset($data['cost_price_cents']) ? (int) $data['cost_price_cents'] : $existing->cost_price_cents;
            $existing->sell_price_cents = isset($data['sell_price_cents']) ? (int) $data['sell_price_cents'] : $existing->sell_price_cents;
            $existing->low_stock_at_milli = isset($data['low_stock_at']) ? LedgerService::parseQty($data['low_stock_at']) : $existing->low_stock_at_milli;
            $existing->is_active = isset($data['is_active']) ? (bool) $data['is_active'] : $existing->is_active;
            $existing->updated_at_ms = $updatedMs;
            $existing->deleted_at_ms = null; // Un-delete if upserting
            $existing->save();

            Change::withoutGlobalScope(ShopScope::class)->create([
                'shop_id' => $shopId,
                'device_id' => $device->id,
                'entity' => 'product',
                'entity_id' => $id,
            ]);

            $accepted[] = $id;
            return;
        }

        // Insert new product
        $name = (string) ($data['name'] ?? '');
        if (empty($name)) {
            $rejected[] = ['id' => $id, 'reason' => 'Product name is required.'];
            return;
        }

        Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => $id,
            'shop_id' => $shopId,
            'name' => $name,
            'sku' => isset($data['sku']) && $data['sku'] !== '' ? (string) $data['sku'] : null,
            'unit' => (string) ($data['unit'] ?? 'pcs'),
            'cost_price_cents' => isset($data['cost_price_cents']) ? (int) $data['cost_price_cents'] : 0,
            'sell_price_cents' => isset($data['sell_price_cents']) ? (int) $data['sell_price_cents'] : 0,
            'low_stock_at_milli' => isset($data['low_stock_at']) ? LedgerService::parseQty($data['low_stock_at']) : null,
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
            'updated_at_ms' => $updatedMs,
        ]);

        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $shopId,
            'device_id' => $device->id,
            'entity' => 'product',
            'entity_id' => $id,
        ]);

        $accepted[] = $id;
    }

    /**
     * Format a Change record into wire format.
     */
    protected function formatChangeRecord(string $shopId, Change $change): ?array
    {
        if ($change->entity === 'movement') {
            /** @var Movement|null $m */
            $m = Movement::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('id', $change->entity_id)
                ->first();

            if ($m === null) {
                return null;
            }

            return [
                'id' => $m->id,
                'entity' => 'movement',
                'op' => 'upsert',
                'data' => [
                    'id' => $m->id,
                    'product_id' => $m->product_id,
                    'type' => $m->type,
                    'qty' => LedgerService::formatQty($m->qty_milli),
                    'unit_price_cents' => $m->unit_price_cents,
                    'note' => $m->note,
                    'created_at' => $m->created_at_ms,
                ],
                'cursor' => $change->seq,
            ];
        }

        if ($change->entity === 'product') {
            /** @var Product|null $p */
            $p = Product::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('id', $change->entity_id)
                ->first();

            if ($p === null) {
                return null;
            }

            $isDeleted = $p->deleted_at_ms !== null;

            return [
                'id' => $p->id,
                'entity' => 'product',
                'op' => $isDeleted ? 'delete' : 'upsert',
                'data' => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'unit' => $p->unit,
                    'cost_price_cents' => $p->cost_price_cents,
                    'sell_price_cents' => $p->sell_price_cents,
                    'low_stock_at' => $p->low_stock_at_milli !== null ? LedgerService::formatQty($p->low_stock_at_milli) : null,
                    'is_active' => (bool) $p->is_active,
                    'updated_at' => $p->updated_at_ms,
                ],
                'cursor' => $change->seq,
            ];
        }

        return null;
    }
}
