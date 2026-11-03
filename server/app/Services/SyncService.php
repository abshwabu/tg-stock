<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Change;
use App\Models\Device;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Product;
use App\Models\RejectedChange;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Support\ImmediateTransaction;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SyncService
{
    public const MAX_CHANGES_PER_REQUEST = 200;
    public const MAX_BODY_BYTES = 262144; // 256 KB
    public const PAGE_SIZE = 300;
    public const MAX_FUTURE_DRIFT_MS = 300000; // 5 minutes

    protected PermissionService $permissionService;

    public function __construct(
        protected LedgerService $ledger,
        ?PermissionService $permissionService = null
    ) {
        $this->permissionService = $permissionService ?? app(PermissionService::class);
    }

    /**
     * Process an incoming sync batch and return incremental server changes.
     *
     * @param Device $device Current authenticated device
     * @param array $payload Validated sync payload
     * @param int $rawContentLength Size of raw HTTP request body in bytes
     * @return array{accepted: string[], rejected: array<int, array{id: string, reason: string}>, changes: array, cursor: int, perm_version: int}
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

        /** @var User|null $user */
        $user = !empty($device->user_id)
            ? User::withoutGlobalScope(ShopScope::class)->find($device->user_id)
            : null;

        $isRevoked = $device->revoked_at !== null || ($user !== null && !$user->is_active);
        $revocationTimeMs = $device->revoked_at
            ? (int) $device->revoked_at->getTimestampMs()
            : ($user?->updated_at ? (int) $user->updated_at->getTimestampMs() : $nowMs);

        $accepted = [];
        $rejected = [];

        // 3. Ingest incoming client changes within an immediate write transaction
        // Sort items so locations and products are processed before movements to guarantee foreign key integrity
        $locationItems = [];
        $productItems = [];
        $movementItems = [];
        $otherItems = [];
        foreach ($incomingChanges as $item) {
            $entity = is_array($item) ? ($item['entity'] ?? '') : '';
            if ($entity === 'location') {
                $locationItems[] = $item;
            } elseif ($entity === 'product') {
                $productItems[] = $item;
            } elseif ($entity === 'movement') {
                $movementItems[] = $item;
            } else {
                $otherItems[] = $item;
            }
        }
        $sortedItems = array_merge($locationItems, $productItems, $movementItems, $otherItems);

        ImmediateTransaction::run(function () use (
            $device,
            $user,
            $shopId,
            $sortedItems,
            $nowMs,
            $isRevoked,
            $revocationTimeMs,
            &$accepted,
            &$rejected
        ): void {
            foreach ($sortedItems as $item) {
                $this->processIncomingItem($device, $user, $shopId, $item, $nowMs, $isRevoked, $revocationTimeMs, $accepted, $rejected);
            }
        });

        // 4. If device is revoked, mark one-time upload sync as consumed and block pulls immediately
        if ($isRevoked) {
            $device->revoked_sync_used = true;
            $device->save();

            return [
                'accepted' => $accepted,
                'rejected' => $rejected,
                'changes' => [],
                'cursor' => $cursor,
                'perm_version' => (int) ($user?->perm_version ?? 1),
            ];
        }

        // 5. Query server changes after cursor made by other devices or Telegram bot
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

        $hasProductView = $this->permissionService->can($user, 'products.view');
        $hasCostView = $this->permissionService->can($user, 'cost.view');
        $allowedLocations = $user ? $this->permissionService->getLocationIds($user) : [];
        $isOwner = $user?->role === 'owner';

        $outgoingChanges = [];
        foreach ($changeRecords as $rec) {
            $formatted = $this->formatChangeRecord(
                $shopId,
                $rec,
                $hasProductView,
                $hasCostView,
                $allowedLocations,
                $isOwner
            );
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
            'perm_version' => (int) ($user?->perm_version ?? 1),
        ];
    }

    /**
     * Process a single incoming change item.
     */
    protected function processIncomingItem(
        Device $device,
        ?User $user,
        string $shopId,
        mixed $item,
        int $nowMs,
        bool $isRevoked,
        int $revocationTimeMs,
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

        // If device is revoked, reject changes created after the revocation timestamp
        if ($isRevoked) {
            $effectiveItemTime = (int) ($data['created_at'] ?? ($data['updated_at'] ?? $clientTs));
            if ($effectiveItemTime > $revocationTimeMs) {
                $rejected[] = ['id' => $id, 'reason' => 'forbidden_revoked: change created after revocation'];
                return;
            }
        }

        if ($entity === 'movement') {
            $this->processMovementChange($device, $user, $shopId, $id, $op, $data, $clientTs, $nowMs, $item, $accepted, $rejected);
        } elseif ($entity === 'product') {
            $this->processProductChange($device, $user, $shopId, $id, $op, $data, $clientTs, $nowMs, $item, $accepted, $rejected);
        } elseif ($entity === 'location') {
            $this->processLocationChange($device, $user, $shopId, $id, $op, $data, $clientTs, $nowMs, $item, $accepted, $rejected);
        } else {
            $rejected[] = ['id' => $id, 'reason' => "Unsupported entity [{$entity}]."];
        }
    }

    /**
     * Ingest an incoming movement change (append-only ledger).
     */
    protected function processMovementChange(
        Device $device,
        ?User $user,
        string $shopId,
        string $id,
        string $op,
        array $data,
        int $clientTs,
        int $nowMs,
        array $fullItem,
        array &$accepted,
        array &$rejected
    ): void {
        if ($op === 'delete') {
            $rejected[] = ['id' => $id, 'reason' => 'Movements cannot be deleted (append-only ledger).'];
            return;
        }

        // Strict data field check
        $allowedFields = ['id', 'product_id', 'location_id', 'transfer_id', 'type', 'qty', 'unit_price_cents', 'note', 'created_at'];
        $unknown = array_diff(array_keys($data), $allowedFields);
        if (!empty($unknown)) {
            $rejected[] = ['id' => $id, 'reason' => 'Unknown field(s) in movement data: ' . implode(', ', $unknown)];
            return;
        }

        $productId = (string) ($data['product_id'] ?? '');
        $type = (string) ($data['type'] ?? '');
        $rawQty = $data['qty'] ?? '0.000';

        if (!in_array($type, ['in', 'sale', 'adjust', 'transfer_out', 'transfer_in'], true)) {
            $rejected[] = ['id' => $id, 'reason' => "Invalid movement type [{$type}]. Must be in, sale, adjust, transfer_out, or transfer_in."];
            return;
        }

        $createdMs = (int) ($data['created_at'] ?? $clientTs);
        if ($createdMs > $nowMs + self::MAX_FUTURE_DRIFT_MS) {
            $createdMs = $nowMs;
        }

        // Map movement type to permission
        $requiredPerm = match ($type) {
            'sale' => 'sale.create',
            'in' => 'stock.in',
            'adjust' => 'stock.adjust',
            'transfer_out' => 'transfer.create',
            'transfer_in' => 'transfer.receive',
        };

        // 1. Permission check effective at createdMs
        if (!$this->permissionService->can($user, $requiredPerm, null, $createdMs)) {
            $this->recordRejectedChange($shopId, $device, $id, 'movement', $op, $fullItem, $createdMs, 'forbidden_permission', "User lacks [{$requiredPerm}] permission.");
            $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
            return;
        }

        // 2. Location check effective at createdMs
        $locationId = !empty($data['location_id']) ? (string) $data['location_id'] : null;
        if ($locationId === null) {
            $defaultLoc = Location::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('is_default', true)
                ->first();
            $locationId = $defaultLoc?->id ? (string) $defaultLoc->id : null;
        }

        if ($locationId !== null && !$this->permissionService->can($user, $requiredPerm, $locationId, $createdMs)) {
            $this->recordRejectedChange($shopId, $device, $id, 'movement', $op, $fullItem, $createdMs, 'forbidden_location', "User lacks access to location [{$locationId}].");
            $rejected[] = ['id' => $id, 'reason' => 'forbidden_location'];
            return;
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

        $transferId = !empty($data['transfer_id']) ? (string) $data['transfer_id'] : null;

        $movement = new Movement([
            'id' => $id,
            'shop_id' => $shopId,
            'product_id' => $productId,
            'location_id' => $locationId,
            'transfer_id' => $transferId,
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
        ?User $user,
        string $shopId,
        string $id,
        string $op,
        array $data,
        int $clientTs,
        int $nowMs,
        array $fullItem,
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
            if (!$this->permissionService->can($user, 'products.delete', null, $updatedMs)) {
                $this->recordRejectedChange($shopId, $device, $id, 'product', $op, $fullItem, $updatedMs, 'forbidden_permission', 'User lacks products.delete permission.');
                $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
                return;
            }

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
            // Check products.edit permission
            if (!$this->permissionService->can($user, 'products.edit', null, $updatedMs)) {
                $this->recordRejectedChange($shopId, $device, $id, 'product', $op, $fullItem, $updatedMs, 'forbidden_permission', 'User lacks products.edit permission.');
                $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
                return;
            }

            // Check price.edit permission if sell or cost price modified
            $priceChanged = (isset($data['sell_price_cents']) && (int) $data['sell_price_cents'] !== (int) $existing->sell_price_cents)
                || (isset($data['cost_price_cents']) && (int) $data['cost_price_cents'] !== (int) $existing->cost_price_cents);

            if ($priceChanged && !$this->permissionService->can($user, 'price.edit', null, $updatedMs)) {
                $this->recordRejectedChange($shopId, $device, $id, 'product', $op, $fullItem, $updatedMs, 'forbidden_permission', 'User lacks price.edit permission.');
                $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
                return;
            }

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

        // Insert new product: requires products.create
        if (!$this->permissionService->can($user, 'products.create', null, $updatedMs)) {
            $this->recordRejectedChange($shopId, $device, $id, 'product', $op, $fullItem, $updatedMs, 'forbidden_permission', 'User lacks products.create permission.');
            $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
            return;
        }

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
     * Ingest an incoming location change (locations.manage permission, LWW).
     */
    protected function processLocationChange(
        Device $device,
        ?User $user,
        string $shopId,
        string $id,
        string $op,
        array $data,
        int $clientTs,
        int $nowMs,
        array $fullItem,
        array &$accepted,
        array &$rejected
    ): void {
        $updatedMs = (int) ($data['updated_at'] ?? $clientTs);
        if ($updatedMs > $nowMs + self::MAX_FUTURE_DRIFT_MS) {
            $updatedMs = $nowMs;
        }

        // Enforce locations.manage authorization
        if (!$this->permissionService->can($user, 'locations.manage', null, $updatedMs)) {
            $this->recordRejectedChange($shopId, $device, $id, 'location', $op, $fullItem, $updatedMs, 'forbidden_permission', 'User lacks locations.manage permission.');
            $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
            return;
        }

        // Strict data field check
        $allowedFields = ['id', 'name', 'kind', 'is_default', 'is_active', 'updated_at'];
        $unknown = array_diff(array_keys($data), $allowedFields);
        if (!empty($unknown)) {
            $rejected[] = ['id' => $id, 'reason' => 'Unknown field(s) in location data: ' . implode(', ', $unknown)];
            return;
        }

        $locId = (string) ($data['id'] ?? $id);

        /** @var Location|null $existing */
        $existing = Location::withoutGlobalScope(ShopScope::class)
            ->where('id', $locId)
            ->where('shop_id', $shopId)
            ->first();

        if ($op === 'delete') {
            if ($existing !== null) {
                if ($existing->updated_at_ms > $updatedMs) {
                    $rejected[] = ['id' => $id, 'reason' => 'stale_conflict: location updated_at is newer than delete client_ts'];
                    return;
                }

                $reason = null;
                if (!$existing->canArchive($reason)) {
                    $rejected[] = ['id' => $id, 'reason' => $reason];
                    return;
                }

                $existing->deleted_at_ms = $updatedMs;
                $existing->is_active = false;
                $existing->updated_at_ms = $updatedMs;
                $existing->save();

                Change::withoutGlobalScope(ShopScope::class)->create([
                    'shop_id' => $shopId,
                    'device_id' => $device->id,
                    'entity' => 'location',
                    'entity_id' => $id,
                ]);
            }

            $accepted[] = $id;
            return;
        }

        // Upsert
        if ($existing !== null) {
            if ($updatedMs < $existing->updated_at_ms) {
                $rejected[] = [
                    'id' => $id,
                    'reason' => 'stale_conflict: incoming updated_at is older than server location updated_at',
                ];
                return;
            }

            // Check archival constraints if setting is_active = false
            if (isset($data['is_active']) && !(bool) $data['is_active'] && $existing->is_active) {
                $reason = null;
                if (!$existing->canArchive($reason)) {
                    $rejected[] = ['id' => $id, 'reason' => $reason];
                    return;
                }
            }

            // If becoming default, unset other defaults in this shop
            if (!empty($data['is_default'])) {
                Location::withoutGlobalScope(ShopScope::class)
                    ->where('shop_id', $shopId)
                    ->where('id', '!=', $locId)
                    ->update(['is_default' => false]);
            }

            $existing->name = (string) ($data['name'] ?? $existing->name);
            if (isset($data['kind']) && in_array($data['kind'], ['store', 'warehouse', 'other'], true)) {
                $existing->kind = (string) $data['kind'];
            }
            if (isset($data['is_default'])) {
                $existing->is_default = (bool) $data['is_default'];
            }
            if (isset($data['is_active'])) {
                $existing->is_active = (bool) $data['is_active'];
            }
            $existing->updated_at_ms = $updatedMs;
            $existing->deleted_at_ms = null;
            $existing->save();

            Change::withoutGlobalScope(ShopScope::class)->create([
                'shop_id' => $shopId,
                'device_id' => $device->id,
                'entity' => 'location',
                'entity_id' => $id,
            ]);

            $accepted[] = $id;
            return;
        }

        // Insert new location
        $name = (string) ($data['name'] ?? '');
        if (empty($name)) {
            $rejected[] = ['id' => $id, 'reason' => 'Location name is required.'];
            return;
        }

        $isDefault = isset($data['is_default']) ? (bool) $data['is_default'] : false;
        if ($isDefault) {
            Location::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->update(['is_default' => false]);
        }

        $kind = isset($data['kind']) && in_array($data['kind'], ['store', 'warehouse', 'other'], true)
            ? (string) $data['kind']
            : 'store';

        Location::withoutGlobalScope(ShopScope::class)->create([
            'id' => $locId,
            'shop_id' => $shopId,
            'name' => $name,
            'kind' => $kind,
            'is_default' => $isDefault,
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
            'updated_at_ms' => $updatedMs,
        ]);

        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $shopId,
            'device_id' => $device->id,
            'entity' => 'location',
            'entity_id' => $id,
        ]);

        $accepted[] = $id;
    }

    /**
     * Record a rejected change in rejected_changes table for owner review.
     */
    protected function recordRejectedChange(
        string $shopId,
        Device $device,
        string $changeId,
        string $entity,
        string $op,
        array $changeData,
        int $clientTs,
        string $reason,
        string $details
    ): void {
        RejectedChange::withoutGlobalScope(ShopScope::class)->updateOrCreate(
            [
                'shop_id' => $shopId,
                'device_id' => $device->id,
                'change_id' => $changeId,
            ],
            [
                'id' => (string) Str::uuid7(),
                'user_id' => $device->user_id ?? (string) Str::uuid7(),
                'entity' => $entity,
                'op' => $op,
                'change_data' => $changeData,
                'client_ts' => $clientTs,
                'reason' => $reason,
                'details' => $details,
                'status' => 'pending',
            ]
        );
    }

    /**
     * Format a Change record into wire format with permission & location filtering.
     */
    protected function formatChangeRecord(
        string $shopId,
        Change $change,
        bool $hasProductView,
        bool $hasCostView,
        array $allowedLocations,
        bool $isOwner
    ): ?array {
        if ($change->entity === 'movement') {
            /** @var Movement|null $m */
            $m = Movement::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('id', $change->entity_id)
                ->first();

            if ($m === null) {
                return null;
            }

            // Location filtering: movements outside user's locations are not sent, except transfers touching one of their locations
            if (!$isOwner && !empty($allowedLocations)) {
                $inAllowed = $m->location_id !== null && in_array((string) $m->location_id, $allowedLocations, true);
                if (!$inAllowed) {
                    $touches = $this->transferTouchesLocations($shopId, $m, $allowedLocations);
                    if (!$touches) {
                        return null; // Not authorized for this location
                    }
                }
            }

            // Cost stripping: without cost.view, null out unit_price_cents on in and transfer_in movements
            $unitPrice = $m->unit_price_cents;
            if (!$hasCostView && in_array($m->type, ['in', 'transfer_in'], true)) {
                $unitPrice = null;
            }

            return [
                'id' => $m->id,
                'entity' => 'movement',
                'op' => 'upsert',
                'data' => [
                    'id' => $m->id,
                    'product_id' => $m->product_id,
                    'location_id' => $m->location_id,
                    'transfer_id' => $m->transfer_id,
                    'type' => $m->type,
                    'qty' => LedgerService::formatQty($m->qty_milli),
                    'unit_price_cents' => $unitPrice,
                    'note' => $m->note,
                    'created_at' => $m->created_at_ms,
                ],
                'cursor' => $change->seq,
            ];
        }

        if ($change->entity === 'product') {
            // Without products.view: no product changes sent
            if (!$hasProductView) {
                return null;
            }

            /** @var Product|null $p */
            $p = Product::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('id', $change->entity_id)
                ->first();

            if ($p === null) {
                return null;
            }

            $isDeleted = $p->deleted_at_ms !== null;

            // Without cost.view: null out cost_price_cents
            $costPrice = $hasCostView ? $p->cost_price_cents : null;

            return [
                'id' => $p->id,
                'entity' => 'product',
                'op' => $isDeleted ? 'delete' : 'upsert',
                'data' => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'unit' => $p->unit,
                    'cost_price_cents' => $costPrice,
                    'sell_price_cents' => $p->sell_price_cents,
                    'low_stock_at' => $p->low_stock_at_milli !== null ? LedgerService::formatQty($p->low_stock_at_milli) : null,
                    'is_active' => (bool) $p->is_active,
                    'updated_at' => $p->updated_at_ms,
                ],
                'cursor' => $change->seq,
            ];
        }

        if ($change->entity === 'location') {
            /** @var Location|null $loc */
            $loc = Location::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('id', $change->entity_id)
                ->first();

            if ($loc === null) {
                return null;
            }

            $isDeleted = $loc->deleted_at_ms !== null;

            return [
                'id' => $loc->id,
                'entity' => 'location',
                'op' => $isDeleted ? 'delete' : 'upsert',
                'data' => [
                    'id' => $loc->id,
                    'name' => $loc->name,
                    'kind' => $loc->kind,
                    'is_default' => (bool) $loc->is_default,
                    'is_active' => (bool) $loc->is_active,
                    'updated_at' => $loc->updated_at_ms,
                ],
                'cursor' => $change->seq,
            ];
        }

        return null;
    }

    /**
     * Check if a transfer movement touches one of the specified allowed locations.
     */
    protected function transferTouchesLocations(string $shopId, Movement $movement, array $allowedLocations): bool
    {
        if (empty($movement->transfer_id)) {
            return false;
        }

        if ($movement->location_id !== null && in_array((string) $movement->location_id, $allowedLocations, true)) {
            return true;
        }

        // Check if destination location is in allowedLocations from transfer_out note
        if (!empty($movement->note)) {
            $decoded = json_decode($movement->note, true);
            if (is_array($decoded) && isset($decoded['to_location_id'])) {
                if (in_array((string) $decoded['to_location_id'], $allowedLocations, true)) {
                    return true;
                }
            }
        }

        // Check any matching movement with the same transfer_id in this shop
        return Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('transfer_id', $movement->transfer_id)
            ->whereIn('location_id', $allowedLocations)
            ->exists();
    }
}
