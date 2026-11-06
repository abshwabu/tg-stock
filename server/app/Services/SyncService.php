<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Change;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\RejectedChange;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Support\ImmediateTransaction;
use Carbon\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SyncService
{
    public const MAX_CHANGES_PER_REQUEST = 200;
    public const MAX_BODY_BYTES = 262144; // 256 KB
    public const PAGE_SIZE = 300;
    public const MAX_FUTURE_DRIFT_MS = 300000; // 5 minutes

    protected PermissionService $permissionService;
    protected CustomerService $customerService;
    protected BalanceService $balanceService;

    public function __construct(
        protected LedgerService $ledger,
        ?PermissionService $permissionService = null,
        ?CustomerService $customerService = null,
        ?BalanceService $balanceService = null
    ) {
        $this->permissionService = $permissionService ?? app(PermissionService::class);
        $this->customerService = $customerService ?? app(CustomerService::class);
        $this->balanceService = $balanceService ?? app(BalanceService::class);
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

        // 3. Ingest incoming client changes within an immediate write transaction.
        // Sort items so locations, products, customers, and providers are processed before sales,
        // and sales are processed before movements and payments to preserve relational integrity.
        $locationItems = [];
        $productItems = [];
        $customerItems = [];
        $providerItems = [];
        $saleItems = [];
        $movementItems = [];
        $paymentItems = [];
        $otherItems = [];

        foreach ($incomingChanges as $item) {
            $entity = is_array($item) ? ($item['entity'] ?? '') : '';
            if ($entity === 'location') {
                $locationItems[] = $item;
            } elseif ($entity === 'product') {
                $productItems[] = $item;
            } elseif ($entity === 'customer') {
                $customerItems[] = $item;
            } elseif ($entity === 'payment_provider') {
                $providerItems[] = $item;
            } elseif ($entity === 'sale') {
                $saleItems[] = $item;
            } elseif ($entity === 'movement') {
                $movementItems[] = $item;
            } elseif ($entity === 'payment') {
                $paymentItems[] = $item;
            } else {
                $otherItems[] = $item;
            }
        }

        $sortedItems = array_merge(
            $locationItems,
            $productItems,
            $customerItems,
            $providerItems,
            $saleItems,
            $movementItems,
            $paymentItems,
            $otherItems
        );

        ImmediateTransaction::run(function () use (
            $device,
            $user,
            $shopId,
            $sortedItems,
            $paymentItems,
            $nowMs,
            $isRevoked,
            $revocationTimeMs,
            &$accepted,
            &$rejected
        ): void {
            $newAcceptedSales = [];

            foreach ($sortedItems as $item) {
                $this->processIncomingItem(
                    $device,
                    $user,
                    $shopId,
                    $item,
                    $nowMs,
                    $isRevoked,
                    $revocationTimeMs,
                    $accepted,
                    $rejected,
                    $paymentItems,
                    $newAcceptedSales
                );
            }

            // Post-batch validation: verify line totals for newly created sales
            foreach ($newAcceptedSales as $saleId => $saleData) {
                $saleMovements = Movement::withoutGlobalScope(ShopScope::class)
                    ->where('shop_id', $shopId)
                    ->where('sale_id', $saleId)
                    ->get();

                $computedTotal = 0;
                foreach ($saleMovements as $sm) {
                    $computedTotal += (int) round((abs($sm->qty_milli) * ($sm->unit_price_cents ?? 0)) / 1000, 0, PHP_ROUND_HALF_UP);
                }

                if ($computedTotal !== (int) $saleData['total_cents']) {
                    // Mismatch: reject the sale with total_mismatch
                    $accepted = array_values(array_diff($accepted, [$saleId]));
                    $rejected[] = ['id' => $saleId, 'reason' => 'total_mismatch'];

                    Sale::withoutGlobalScope(ShopScope::class)->where('id', $saleId)->delete();
                    Change::withoutGlobalScope(ShopScope::class)->where('entity', 'sale')->where('entity_id', $saleId)->delete();

                    // Reject any movement in this batch referencing this invalidated sale
                    foreach ($saleMovements as $sm) {
                        if (in_array($sm->id, $accepted, true)) {
                            $accepted = array_values(array_diff($accepted, [$sm->id]));
                            $rejected[] = ['id' => $sm->id, 'reason' => 'unknown_reference'];
                            Movement::withoutGlobalScope(ShopScope::class)->where('id', $sm->id)->delete();
                            Change::withoutGlobalScope(ShopScope::class)->where('entity', 'movement')->where('entity_id', $sm->id)->delete();
                        }
                    }

                    // Reject any payment in this batch referencing this invalidated sale
                    $salePayments = Payment::withoutGlobalScope(ShopScope::class)
                        ->where('shop_id', $shopId)
                        ->where('sale_id', $saleId)
                        ->get();

                    foreach ($salePayments as $sp) {
                        if (in_array($sp->id, $accepted, true)) {
                            $accepted = array_values(array_diff($accepted, [$sp->id]));
                            $rejected[] = ['id' => $sp->id, 'reason' => 'unknown_reference'];
                            Payment::withoutGlobalScope(ShopScope::class)->where('id', $sp->id)->delete();
                            Change::withoutGlobalScope(ShopScope::class)->where('entity', 'payment')->where('entity_id', $sp->id)->delete();
                        }
                    }
                }
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
        $hasDubeView = $this->permissionService->can($user, 'dube.view');
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
                $isOwner,
                $hasDubeView,
                $device
            );
            if ($formatted !== null) {
                $outgoingChanges[] = $formatted;
            }
        }

        // Determine next cursor: advance to highest sequence processed
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
        array &$rejected,
        array $paymentItems = [],
        array &$newAcceptedSales = []
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
        } elseif ($entity === 'customer') {
            $this->processCustomerChange($device, $user, $shopId, $id, $op, $data, $clientTs, $nowMs, $item, $accepted, $rejected);
        } elseif ($entity === 'sale') {
            $this->processSaleChange($device, $user, $shopId, $id, $op, $data, $clientTs, $nowMs, $item, $accepted, $rejected, $paymentItems, $newAcceptedSales);
        } elseif ($entity === 'payment') {
            $this->processPaymentChange($device, $user, $shopId, $id, $op, $data, $clientTs, $nowMs, $item, $accepted, $rejected);
        } elseif ($entity === 'payment_provider') {
            $this->processPaymentProviderChange($device, $user, $shopId, $id, $op, $data, $clientTs, $nowMs, $item, $accepted, $rejected);
        } else {
            $rejected[] = ['id' => $id, 'reason' => "Unsupported entity [{$entity}]."];
        }
    }

    /**
     * Ingest an incoming customer change (LWW by updated_at).
     */
    protected function processCustomerChange(
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
        $allowedFields = ['id', 'name', 'phone', 'note', 'updated_at', 'deleted_at'];
        $unknown = array_diff(array_keys($data), $allowedFields);
        if (!empty($unknown)) {
            $rejected[] = ['id' => $id, 'reason' => 'Unknown field(s) in customer data: ' . implode(', ', $unknown)];
            return;
        }

        // Permission check
        if (!$this->permissionService->can($user, 'customers.manage', null, $clientTs)) {
            $this->recordRejectedChange($shopId, $device, $id, 'customer', $op, $fullItem, $clientTs, 'forbidden_permission', "User lacks [customers.manage] permission.");
            $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
            return;
        }

        $updatedMs = (int) ($data['updated_at'] ?? $clientTs);
        if ($updatedMs > $nowMs + self::MAX_FUTURE_DRIFT_MS) {
            $updatedMs = $nowMs;
        }

        /** @var Customer|null $existing */
        $existing = Customer::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('id', $id)
            ->first();

        // LWW check: if existing update is newer, accept as resolved
        if ($existing !== null && $existing->updated_at_ms > $updatedMs) {
            $accepted[] = $id;
            return;
        }

        // Delete handling
        $isDelete = $op === 'delete' || !empty($data['deleted_at']);
        if ($isDelete) {
            if ($existing !== null) {
                try {
                    $this->customerService->softDeleteCustomer($existing, $device->id);
                    $accepted[] = $id;
                } catch (\Throwable $e) {
                    $rejected[] = ['id' => $id, 'reason' => 'cannot_delete_with_balance'];
                }
            } else {
                $accepted[] = $id;
            }
            return;
        }

        $name = (string) ($data['name'] ?? '');
        if (trim($name) === '') {
            $rejected[] = ['id' => $id, 'reason' => 'Customer name cannot be empty.'];
            return;
        }

        $phone = isset($data['phone']) ? (string) $data['phone'] : null;
        $note = isset($data['note']) ? (string) $data['note'] : null;

        $this->customerService->upsertCustomer($shopId, $id, $name, $phone, $note, $updatedMs, $device->id);
        $accepted[] = $id;
    }

    /**
     * Ingest an incoming sale change.
     */
    protected function processSaleChange(
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
        array &$rejected,
        array $paymentItems,
        array &$newAcceptedSales
    ): void {
        if ($op === 'delete') {
            $rejected[] = ['id' => $id, 'reason' => 'Sales cannot be deleted; use voiding instead.'];
            return;
        }

        $allowedFields = ['id', 'location_id', 'customer_id', 'total_cents', 'due_date', 'note', 'legacy', 'created_at', 'voided_at'];
        $unknown = array_diff(array_keys($data), $allowedFields);
        if (!empty($unknown)) {
            $rejected[] = ['id' => $id, 'reason' => 'Unknown field(s) in sale data: ' . implode(', ', $unknown)];
            return;
        }

        $createdMs = (int) ($data['created_at'] ?? $clientTs);
        if ($createdMs > $nowMs + self::MAX_FUTURE_DRIFT_MS) {
            $createdMs = $nowMs;
        }

        /** @var Sale|null $existing */
        $existing = Sale::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('id', $id)
            ->first();

        if ($existing !== null) {
            // Existing sale: handle voiding or metadata update
            if (!empty($data['voided_at']) && $existing->voided_at_ms === null) {
                try {
                    $this->balanceService->voidSale($existing, $user ?? (string) $existing->user_id);
                    $accepted[] = $id;
                    return;
                } catch (\Throwable $e) {
                    $rejected[] = ['id' => $id, 'reason' => $e->getMessage()];
                    return;
                }
            }

            // Can update note or due_date if not voided
            if ($existing->voided_at_ms === null) {
                if (array_key_exists('due_date', $data)) {
                    $existing->due_date = $data['due_date'] ? (string) $data['due_date'] : null;
                }
                if (array_key_exists('note', $data)) {
                    $existing->note = $data['note'] ? (string) $data['note'] : null;
                }
                $existing->updated_at_ms = $nowMs;
                $existing->save();
            }

            $accepted[] = $id;
            return;
        }

        // New sale creation
        // 1. Permission check: sale.create
        if (!$this->permissionService->can($user, 'sale.create', null, $createdMs)) {
            $this->recordRejectedChange($shopId, $device, $id, 'sale', $op, $fullItem, $createdMs, 'forbidden_permission', "User lacks [sale.create] permission.");
            $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
            return;
        }

        // 2. Location validation
        $locationId = !empty($data['location_id']) ? (string) $data['location_id'] : null;
        if (empty($locationId)) {
            $defaultLoc = Location::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->where('is_default', true)->first();
            $locationId = $defaultLoc?->id ? (string) $defaultLoc->id : null;
        }

        $loc = Location::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->where('id', $locationId)->first();
        if ($loc === null) {
            $rejected[] = ['id' => $id, 'reason' => 'unknown_reference'];
            return;
        }

        if (!$this->permissionService->can($user, 'sale.create', $locationId, $createdMs)) {
            $this->recordRejectedChange($shopId, $device, $id, 'sale', $op, $fullItem, $createdMs, 'forbidden_location', "User lacks location access for [{$locationId}].");
            $rejected[] = ['id' => $id, 'reason' => 'forbidden_location'];
            return;
        }

        // 3. Customer validation
        $customerId = !empty($data['customer_id']) ? (string) $data['customer_id'] : null;
        if (!empty($customerId)) {
            $cust = Customer::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->where('id', $customerId)->first();
            if ($cust === null) {
                $rejected[] = ['id' => $id, 'reason' => 'unknown_reference'];
                return;
            }
        }

        $totalCents = (int) ($data['total_cents'] ?? 0);

        // 4. Dube check:
        // Calculate explicit payments for this sale in DB and in this incoming batch
        $explicitPaymentsInDb = (int) Payment::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('sale_id', $id)
            ->sum('amount_cents');

        $explicitPaymentsInBatch = 0;
        foreach ($paymentItems as $pi) {
            if (is_array($pi) && isset($pi['data']) && is_array($pi['data'])) {
                if (($pi['data']['sale_id'] ?? '') === $id) {
                    $explicitPaymentsInBatch += (int) ($pi['data']['amount_cents'] ?? 0);
                }
            }
        }

        $totalExplicitPayments = $explicitPaymentsInDb + $explicitPaymentsInBatch;
        $isLegacy = (bool) ($data['legacy'] ?? false);
        $isDube = !$isLegacy && (($totalCents - $totalExplicitPayments) > 0);

        if ($isDube) {
            // Must have sale.dube permission
            if (!$this->permissionService->can($user, 'sale.dube', $locationId, $createdMs)) {
                $this->recordRejectedChange($shopId, $device, $id, 'sale', $op, $fullItem, $createdMs, 'forbidden_permission', "User lacks [sale.dube] permission.");
                $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
                return;
            }

            // Must have customer_id
            if (empty($customerId)) {
                $rejected[] = ['id' => $id, 'reason' => 'missing_customer'];
                return;
            }

            // Must have valid due_date not before sale date
            $dueDate = isset($data['due_date']) ? (string) $data['due_date'] : null;
            if (empty($dueDate)) {
                $rejected[] = ['id' => $id, 'reason' => 'missing_due_date'];
                return;
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                $rejected[] = ['id' => $id, 'reason' => 'invalid_due_date'];
                return;
            }

            $shop = Shop::find($shopId);
            $tz = $shop?->timezone ?: 'Africa/Addis_Ababa';
            $saleDate = Carbon::createFromTimestampMs($createdMs, $tz)->toDateString();

            if ($dueDate < $saleDate) {
                $rejected[] = ['id' => $id, 'reason' => 'invalid_due_date'];
                return;
            }
        }

        $voidedAtMs = !empty($data['voided_at']) ? (int) $data['voided_at'] : null;

        $sale = Sale::withoutGlobalScope(ShopScope::class)->create([
            'id' => $id,
            'shop_id' => $shopId,
            'location_id' => $locationId,
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'customer_id' => $customerId,
            'total_cents' => $totalCents,
            'due_date' => $data['due_date'] ?? null,
            'note' => $data['note'] ?? null,
            'legacy' => $isLegacy,
            'voided_at_ms' => $voidedAtMs,
            'created_at_ms' => $createdMs,
            'updated_at_ms' => $nowMs,
        ]);

        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $shopId,
            'device_id' => $device->id,
            'entity' => 'sale',
            'entity_id' => $sale->id,
        ]);

        $accepted[] = $id;
        $newAcceptedSales[$id] = $data;
    }

    /**
     * Ingest an incoming payment change (insert-or-ignore, append-only).
     */
    protected function processPaymentChange(
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
            $rejected[] = ['id' => $id, 'reason' => 'Payments cannot be deleted (append-only ledger).'];
            return;
        }

        $allowedFields = ['id', 'sale_id', 'customer_id', 'location_id', 'method', 'provider_key', 'amount_cents', 'reference', 'received_at'];
        $unknown = array_diff(array_keys($data), $allowedFields);
        if (!empty($unknown)) {
            $rejected[] = ['id' => $id, 'reason' => 'Unknown field(s) in payment data: ' . implode(', ', $unknown)];
            return;
        }

        $receivedMs = (int) ($data['received_at'] ?? $clientTs);
        if ($receivedMs > $nowMs + self::MAX_FUTURE_DRIFT_MS) {
            $receivedMs = $nowMs;
        }

        // Idempotent replay: insert-or-ignore, never updated
        $existing = Payment::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('id', $id)
            ->first();

        if ($existing !== null) {
            $accepted[] = $id;
            return;
        }

        // Location resolution
        $locationId = !empty($data['location_id']) ? (string) $data['location_id'] : null;
        if (empty($locationId)) {
            $defaultLoc = Location::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->where('is_default', true)->first();
            $locationId = $defaultLoc?->id ? (string) $defaultLoc->id : null;
        }

        $loc = Location::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->where('id', $locationId)->first();
        if ($loc === null) {
            $rejected[] = ['id' => $id, 'reason' => 'unknown_reference'];
            return;
        }

        // 1. Permission check: payment.collect
        if (!$this->permissionService->can($user, 'payment.collect', $locationId, $receivedMs)) {
            $this->recordRejectedChange($shopId, $device, $id, 'payment', $op, $fullItem, $receivedMs, 'forbidden_permission', "User lacks [payment.collect] permission.");
            $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
            return;
        }

        $saleId = !empty($data['sale_id']) ? (string) $data['sale_id'] : null;
        $customerId = !empty($data['customer_id']) ? (string) $data['customer_id'] : null;

        // Constraint: sale_id or customer_id required
        if (empty($saleId) && empty($customerId)) {
            $rejected[] = ['id' => $id, 'reason' => 'invalid_payment'];
            return;
        }

        // Validate sale_id reference if provided
        if (!empty($saleId)) {
            $sale = Sale::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->where('id', $saleId)->first();
            if ($sale === null) {
                $rejected[] = ['id' => $id, 'reason' => 'unknown_reference'];
                return;
            }
            if (empty($customerId) && !empty($sale->customer_id)) {
                $customerId = (string) $sale->customer_id;
            }
        }

        // Validate customer_id reference if provided
        if (!empty($customerId)) {
            $cust = Customer::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->where('id', $customerId)->first();
            if ($cust === null) {
                $rejected[] = ['id' => $id, 'reason' => 'unknown_reference'];
                return;
            }
        }

        $method = (string) ($data['method'] ?? '');
        if (!in_array($method, ['cash', 'bank', 'wallet'], true)) {
            $rejected[] = ['id' => $id, 'reason' => "Invalid payment method [{$method}]. Must be cash, bank, or wallet."];
            return;
        }

        $providerKey = !empty($data['provider_key']) ? (string) $data['provider_key'] : null;

        // Constraints on provider_key: cash forbids it; bank and wallet require it
        if ($method === 'cash') {
            if ($providerKey !== null) {
                $rejected[] = ['id' => $id, 'reason' => 'cash_forbids_provider_key'];
                return;
            }
        } else {
            if (empty($providerKey)) {
                $rejected[] = ['id' => $id, 'reason' => 'missing_provider_key'];
                return;
            }

            if (!$this->isValidProviderKey($shopId, $method, $providerKey)) {
                $rejected[] = ['id' => $id, 'reason' => 'unknown_reference'];
                return;
            }
        }

        $amountCents = (int) ($data['amount_cents'] ?? 0);

        $maxSeq = (int) Payment::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->max('server_seq');

        $payment = Payment::withoutGlobalScope(ShopScope::class)->create([
            'id' => $id,
            'shop_id' => $shopId,
            'sale_id' => $saleId,
            'customer_id' => $customerId,
            'location_id' => $locationId,
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'method' => $method,
            'provider_key' => $providerKey,
            'amount_cents' => $amountCents,
            'reference' => $data['reference'] ?? null,
            'received_at_ms' => $receivedMs,
            'created_at_ms' => $nowMs,
            'server_seq' => $maxSeq + 1,
        ]);

        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $shopId,
            'device_id' => $device->id,
            'entity' => 'payment',
            'entity_id' => $payment->id,
        ]);

        $accepted[] = $id;
    }

    /**
     * Ingest an incoming payment_provider change (LWW by updated_at, owner only).
     */
    protected function processPaymentProviderChange(
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
        $allowedFields = ['provider_key', 'kind', 'name', 'enabled', 'favorite', 'updated_at'];
        $unknown = array_diff(array_keys($data), $allowedFields);
        if (!empty($unknown)) {
            $rejected[] = ['id' => $id, 'reason' => 'Unknown field(s) in payment_provider data: ' . implode(', ', $unknown)];
            return;
        }

        // Owner only permission
        if ($user?->role !== 'owner') {
            $this->recordRejectedChange($shopId, $device, $id, 'payment_provider', $op, $fullItem, $clientTs, 'forbidden_permission', "Only shop owner can configure payment providers.");
            $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
            return;
        }

        $providerKey = (string) ($data['provider_key'] ?? $id);
        if (empty($providerKey)) {
            $rejected[] = ['id' => $id, 'reason' => 'Missing provider_key.'];
            return;
        }

        $kind = (string) ($data['kind'] ?? 'bank');
        if (!in_array($kind, ['bank', 'wallet'], true)) {
            $rejected[] = ['id' => $id, 'reason' => "Invalid provider kind [{$kind}]. Must be bank or wallet."];
            return;
        }

        $updatedMs = (int) ($data['updated_at'] ?? $clientTs);
        if ($updatedMs > $nowMs + self::MAX_FUTURE_DRIFT_MS) {
            $updatedMs = $nowMs;
        }

        /** @var PaymentProvider|null $existing */
        $existing = PaymentProvider::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('provider_key', $providerKey)
            ->first();

        if ($existing !== null && $existing->updated_at_ms > $updatedMs) {
            $accepted[] = $id;
            return;
        }

        if ($existing !== null) {
            $existing->kind = $kind;
            $existing->name = $data['name'] ?? $existing->name;
            $existing->enabled = isset($data['enabled']) ? (bool) $data['enabled'] : $existing->enabled;
            $existing->favorite = isset($data['favorite']) ? (bool) $data['favorite'] : $existing->favorite;
            $existing->updated_at_ms = $updatedMs;
            $existing->save();
        } else {
            PaymentProvider::withoutGlobalScope(ShopScope::class)->create([
                'shop_id' => $shopId,
                'provider_key' => $providerKey,
                'kind' => $kind,
                'name' => $data['name'] ?? null,
                'enabled' => isset($data['enabled']) ? (bool) $data['enabled'] : true,
                'favorite' => isset($data['favorite']) ? (bool) $data['favorite'] : false,
                'updated_at_ms' => $updatedMs,
            ]);
        }

        Change::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $shopId,
            'device_id' => $device->id,
            'entity' => 'payment_provider',
            'entity_id' => $providerKey,
        ]);

        $accepted[] = $id;
    }

    /**
     * Validate whether a provider key is registered in catalog or custom providers.
     */
    public function isValidProviderKey(string $shopId, string $method, string $providerKey): bool
    {
        if ($method === 'bank') {
            if (str_starts_with($providerKey, 'bank.')) {
                $catalog = config('payment_catalog.banks', []);
                if (isset($catalog[$providerKey])) {
                    return true;
                }
            }
            if (str_starts_with($providerKey, 'custom.')) {
                return true;
            }
            return PaymentProvider::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('provider_key', $providerKey)
                ->where('kind', 'bank')
                ->exists();
        }

        if ($method === 'wallet') {
            if (str_starts_with($providerKey, 'wallet.')) {
                $catalog = config('payment_catalog.wallets', []);
                if (isset($catalog[$providerKey])) {
                    return true;
                }
            }
            if (str_starts_with($providerKey, 'custom.')) {
                return true;
            }
            return PaymentProvider::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('provider_key', $providerKey)
                ->where('kind', 'wallet')
                ->exists();
        }

        return false;
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
        $allowedFields = ['id', 'product_id', 'location_id', 'transfer_id', 'sale_id', 'type', 'qty', 'unit_price_cents', 'note', 'created_at', 'tracks_stock', 'source_movement_id'];
        $unknown = array_diff(array_keys($data), $allowedFields);
        if (!empty($unknown)) {
            $rejected[] = ['id' => $id, 'reason' => 'Unknown field(s) in movement data: ' . implode(', ', $unknown)];
            return;
        }

        $productId = (string) ($data['product_id'] ?? '');
        $type = (string) ($data['type'] ?? '');
        $rawQty = $data['qty'] ?? '0.000';

        if (!in_array($type, ['in', 'sale', 'adjust', 'transfer_out', 'transfer_in', 'consume'], true)) {
            $rejected[] = ['id' => $id, 'reason' => "Invalid movement type [{$type}]. Must be in, sale, adjust, transfer_out, transfer_in, or consume."];
            return;
        }

        $createdMs = (int) ($data['created_at'] ?? $clientTs);
        if ($createdMs > $nowMs + self::MAX_FUTURE_DRIFT_MS) {
            $createdMs = $nowMs;
        }

        // Map movement type to permission
        $requiredPerm = match ($type) {
            'sale', 'consume' => 'sale.create',
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

        // Wire defaults for older clients
        $tracksStock = isset($data['tracks_stock']) ? (bool) $data['tracks_stock'] : true;
        $sourceMovementId = !empty($data['source_movement_id']) ? (string) $data['source_movement_id'] : null;

        // Stock-in, adjust, transfer on non-stockable products rejected with not_stockable
        if (!$product->isStockable() && in_array($type, ['in', 'adjust', 'transfer_out', 'transfer_in'], true)) {
            $rejected[] = ['id' => $id, 'reason' => 'not_stockable'];
            return;
        }

        // Validation: tracks_stock=false is allowed only on sale lines
        if (!$tracksStock && $type !== 'sale') {
            $rejected[] = ['id' => $id, 'reason' => 'tracks_stock_not_allowed'];
            return;
        }

        // Validation: if movement created_at is after product kind_changed_at, tracks_stock must match product kind
        if ($product->kind_changed_at_ms !== null && $createdMs > $product->kind_changed_at_ms) {
            if ($product->isStockable() && !$tracksStock) {
                $rejected[] = ['id' => $id, 'reason' => 'tracks_stock_mismatch'];
                return;
            }
            if (!$product->isStockable() && $tracksStock) {
                $rejected[] = ['id' => $id, 'reason' => 'tracks_stock_mismatch'];
                return;
            }
        }

        // Verify sale_id reference if provided
        $saleId = !empty($data['sale_id']) ? (string) $data['sale_id'] : null;
        if (!empty($saleId)) {
            $saleExists = Sale::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('id', $saleId)
                ->exists();

            if (!$saleExists) {
                $rejected[] = ['id' => $id, 'reason' => 'unknown_reference'];
                return;
            }
        }

        $qtyMilli = LedgerService::parseQty($rawQty);

        // Validation: consume movement rules
        if ($type === 'consume') {
            if ($qtyMilli >= 0 || empty($saleId) || empty($sourceMovementId) || !$product->isStockable()) {
                $rejected[] = ['id' => $id, 'reason' => 'invalid_consume'];
                return;
            }

            $sourceMovement = Movement::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('id', $sourceMovementId)
                ->first();

            if ($sourceMovement === null || (string) $sourceMovement->sale_id !== (string) $saleId || $sourceMovement->type !== 'sale' || $sourceMovement->tracks_stock !== false) {
                $rejected[] = ['id' => $id, 'reason' => 'invalid_consume'];
                return;
            }

            $shop = Shop::find($shopId);
            if ($shop?->block_sale_when_material_short) {
                $currentStock = $this->ledger->stockOf($product->id, $locationId, $shopId) ?? 0;
                if ($currentStock + $qtyMilli < 0) {
                    $rejected[] = ['id' => $id, 'reason' => 'insufficient_material'];
                    return;
                }
            }
        }

        $transferId = !empty($data['transfer_id']) ? (string) $data['transfer_id'] : null;

        $movement = new Movement([
            'id' => $id,
            'shop_id' => $shopId,
            'product_id' => $productId,
            'location_id' => $locationId,
            'transfer_id' => $transferId,
            'sale_id' => $saleId,
            'source_movement_id' => $sourceMovementId,
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'type' => $type,
            'tracks_stock' => $tracksStock,
            'qty_milli' => $qtyMilli,
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
        $allowedFields = [
            'id', 'name', 'sku', 'unit', 'cost_price_cents', 'sell_price_cents',
            'low_stock_at', 'is_active', 'updated_at',
            'kind', 'ask_price', 'materials', 'kind_changed_at',
        ];
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

        // Check required permission
        if ($op === 'delete') {
            if (!$this->permissionService->can($user, 'products.delete', null, $updatedMs)) {
                $this->recordRejectedChange($shopId, $device, $id, 'product', $op, $fullItem, $updatedMs, 'forbidden_permission', 'User lacks [products.delete] permission.');
                $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
                return;
            }

            // Material delete protection: cannot delete if used in any consumable's recipe
            if ($existing !== null && app(SalesService::class)->isUsedAsMaterial($id, $shopId)) {
                $rejected[] = ['id' => $id, 'reason' => 'used_as_material'];
                return;
            }
        } elseif ($existing === null) {
            if (!$this->permissionService->can($user, 'products.create', null, $updatedMs)) {
                $this->recordRejectedChange($shopId, $device, $id, 'product', $op, $fullItem, $updatedMs, 'forbidden_permission', 'User lacks [products.create] permission.');
                $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
                return;
            }
        } else {
            // Price edit check
            $costAttempted = array_key_exists('cost_price_cents', $data) && $data['cost_price_cents'] !== $existing->cost_price_cents;
            $sellAttempted = array_key_exists('sell_price_cents', $data) && $data['sell_price_cents'] !== $existing->sell_price_cents;

            if (($costAttempted || $sellAttempted) && !$this->permissionService->can($user, 'price.edit', null, $updatedMs)) {
                $this->recordRejectedChange($shopId, $device, $id, 'product', $op, $fullItem, $updatedMs, 'forbidden_permission', 'User lacks [price.edit] permission.');
                $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
                return;
            }

            if (!$this->permissionService->can($user, 'products.edit', null, $updatedMs)) {
                $this->recordRejectedChange($shopId, $device, $id, 'product', $op, $fullItem, $updatedMs, 'forbidden_permission', 'User lacks [products.edit] permission.');
                $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
                return;
            }
        }

        if ($op === 'delete') {
            if ($existing !== null) {
                if ($updatedMs < $existing->updated_at_ms) {
                    $accepted[] = $id;
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

        // Upsert operation
        $name = (string) ($data['name'] ?? '');
        if (trim($name) === '') {
            $rejected[] = ['id' => $id, 'reason' => 'Product name cannot be empty.'];
            return;
        }

        $costPrice = isset($data['cost_price_cents']) ? (int) $data['cost_price_cents'] : 0;
        $sellPrice = isset($data['sell_price_cents']) ? (int) $data['sell_price_cents'] : 0;

        if ($costPrice < 0 || $sellPrice < 0) {
            $rejected[] = ['id' => $id, 'reason' => 'Prices cannot be negative.'];
            return;
        }

        // Parse and validate materials if provided
        $parsedMaterials = null;
        if (array_key_exists('materials', $data) && $data['materials'] !== null) {
            $rawMaterials = $data['materials'];
            if (!is_array($rawMaterials)) {
                $rejected[] = ['id' => $id, 'reason' => 'invalid_materials'];
                return;
            }
            $parsedMaterials = [];
            foreach ($rawMaterials as $mat) {
                $matId = (string) ($mat['product_id'] ?? '');
                if ($matId === '' || $matId === $id) {
                    $rejected[] = ['id' => $id, 'reason' => 'invalid_materials'];
                    return;
                }
                $matProd = Product::withoutGlobalScope(ShopScope::class)
                    ->where('shop_id', $shopId)
                    ->where('id', $matId)
                    ->first();
                if ($matProd === null || !$matProd->isStockable()) {
                    $rejected[] = ['id' => $id, 'reason' => 'invalid_materials'];
                    return;
                }
                $qtyMilli = isset($mat['qty_milli'])
                    ? (int) $mat['qty_milli']
                    : LedgerService::parseQty((string) ($mat['qty'] ?? '0.000'));
                if ($qtyMilli <= 0) {
                    $rejected[] = ['id' => $id, 'reason' => 'invalid_materials'];
                    return;
                }
                $parsedMaterials[] = [
                    'product_id' => $matId,
                    'qty_milli' => $qtyMilli,
                ];
            }
        }

        if ($existing !== null) {
            // Check if incoming edit is stale
            if ($updatedMs < $existing->updated_at_ms) {
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

            // Kind change validation
            if (array_key_exists('kind', $data) && $data['kind'] !== ($existing->kind ?? 'stockable')) {
                $newKind = (string) $data['kind'];
                try {
                    app(SalesService::class)->validateKindChange($existing, $newKind, $shopId);
                } catch (\InvalidArgumentException $e) {
                    $rejected[] = ['id' => $id, 'reason' => $e->getMessage()];
                    return;
                }
                $existing->kind = $newKind;
                $existing->kind_changed_at_ms = (int) ($data['kind_changed_at'] ?? $updatedMs);
            }

            $existing->name = $name;
            $existing->sku = $data['sku'] ?? $existing->sku;
            $existing->unit = $data['unit'] ?? $existing->unit;
            if (array_key_exists('cost_price_cents', $data)) {
                $existing->cost_price_cents = (int) $data['cost_price_cents'];
            }
            if (array_key_exists('sell_price_cents', $data)) {
                $existing->sell_price_cents = (int) $data['sell_price_cents'];
            }
            if (array_key_exists('ask_price', $data)) {
                $existing->ask_price = (bool) $data['ask_price'];
            }
            if (array_key_exists('materials', $data)) {
                $existing->materials = $parsedMaterials;
            }
            if (array_key_exists('low_stock_at', $data)) {
                $existing->low_stock_at_milli = $data['low_stock_at'] !== null
                    ? LedgerService::parseQty((string) $data['low_stock_at'])
                    : null;
            }
            $existing->is_active = isset($data['is_active']) ? (bool) $data['is_active'] : $existing->is_active;
            $existing->updated_at_ms = $updatedMs;
            $existing->deleted_at_ms = null;
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

        // Create new product
        $newKind = $data['kind'] ?? 'stockable';
        Product::withoutGlobalScope(ShopScope::class)->create([
            'id' => $id,
            'shop_id' => $shopId,
            'name' => $name,
            'sku' => $data['sku'] ?? null,
            'unit' => $data['unit'] ?? 'pcs',
            'kind' => $newKind,
            'cost_price_cents' => $costPrice,
            'sell_price_cents' => $sellPrice,
            'ask_price' => isset($data['ask_price']) ? (bool) $data['ask_price'] : false,
            'materials' => $parsedMaterials,
            'kind_changed_at_ms' => isset($data['kind_changed_at']) ? (int) $data['kind_changed_at'] : null,
            'low_stock_at_milli' => isset($data['low_stock_at']) ? LedgerService::parseQty((string) $data['low_stock_at']) : null,
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
     * Ingest an incoming location change (LWW by updated_at, owner only).
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
        $allowedFields = ['id', 'name', 'kind', 'is_default', 'is_active', 'updated_at'];
        $unknown = array_diff(array_keys($data), $allowedFields);
        if (!empty($unknown)) {
            $rejected[] = ['id' => $id, 'reason' => 'Unknown field(s) in location data: ' . implode(', ', $unknown)];
            return;
        }

        $updatedMs = (int) ($data['updated_at'] ?? $clientTs);
        if ($updatedMs > $nowMs + self::MAX_FUTURE_DRIFT_MS) {
            $updatedMs = $nowMs;
        }

        // Enforce locations.manage authorization
        if (!$this->permissionService->can($user, 'locations.manage', null, $updatedMs)) {
            $this->recordRejectedChange($shopId, $device, $id, 'location', $op, $fullItem, $updatedMs, 'forbidden_permission', 'Only shop owner can modify locations.');
            $rejected[] = ['id' => $id, 'reason' => 'forbidden_permission'];
            return;
        }

        /** @var Location|null $existing */
        $existing = Location::withoutGlobalScope(ShopScope::class)
            ->where('id', $id)
            ->where('shop_id', $shopId)
            ->first();

        if ($op === 'delete') {
            if ($existing !== null) {
                if ($existing->is_default) {
                    $rejected[] = ['id' => $id, 'reason' => 'Default location cannot be deleted.'];
                    return;
                }

                if ($updatedMs < $existing->updated_at_ms) {
                    $accepted[] = $id;
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

        $name = (string) ($data['name'] ?? '');
        if (trim($name) === '') {
            $rejected[] = ['id' => $id, 'reason' => 'Location name cannot be empty.'];
            return;
        }

        $kind = (string) ($data['kind'] ?? 'store');
        if (!in_array($kind, ['store', 'warehouse', 'other'], true)) {
            $rejected[] = ['id' => $id, 'reason' => "Invalid location kind [{$kind}]. Must be store, warehouse, or other."];
            return;
        }

        $isDefault = isset($data['is_default']) ? (bool) $data['is_default'] : false;

        if ($existing !== null) {
            if ($updatedMs < $existing->updated_at_ms) {
                $accepted[] = $id;
                return;
            }

            $existing->name = $name;
            $existing->kind = $kind;
            if ($isDefault) {
                Location::withoutGlobalScope(ShopScope::class)
                    ->where('shop_id', $shopId)
                    ->where('id', '!=', $id)
                    ->update(['is_default' => false]);
                $existing->is_default = true;
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

        if ($isDefault) {
            Location::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->update(['is_default' => false]);
        }

        Location::withoutGlobalScope(ShopScope::class)->create([
            'id' => $id,
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
     * Record a rejected change item for security and diagnostic auditing.
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
     * Format a server Change record for pull synchronization according to client permissions.
     */
    protected function formatChangeRecord(
        string $shopId,
        Change $change,
        bool $hasProductView,
        bool $hasCostView,
        array $allowedLocations,
        bool $isOwner,
        bool $hasDubeView,
        Device $device
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

            // Location filtering: movements outside user's locations are not sent
            if (!$isOwner && !empty($allowedLocations)) {
                $inAllowed = $m->location_id !== null && in_array((string) $m->location_id, $allowedLocations, true);
                if (!$inAllowed) {
                    $touches = $this->transferTouchesLocations($shopId, $m, $allowedLocations);
                    if (!$touches) {
                        return null;
                    }
                }
            }

            // Cost stripping
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
                    'sale_id' => $m->sale_id,
                    'source_movement_id' => $m->source_movement_id,
                    'type' => $m->type,
                    'tracks_stock' => (bool) ($m->tracks_stock ?? true),
                    'qty' => LedgerService::formatQty($m->qty_milli),
                    'unit_price_cents' => $unitPrice,
                    'note' => $m->note,
                    'created_at' => $m->created_at_ms,
                ],
                'cursor' => $change->seq,
            ];
        }

        if ($change->entity === 'product') {
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
            $costPrice = $hasCostView ? $p->cost_price_cents : null;

            $materialsWire = null;
            if (!empty($p->materials) && is_array($p->materials)) {
                $materialsWire = array_map(function ($mat) {
                    return [
                        'product_id' => $mat['product_id'],
                        'qty' => LedgerService::formatQty((int) ($mat['qty_milli'] ?? 0)),
                    ];
                }, $p->materials);
            }

            $productData = [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'unit' => $p->unit,
                'kind' => $p->kind ?? 'stockable',
                'cost_price_cents' => $costPrice,
                'sell_price_cents' => $p->sell_price_cents,
                'ask_price' => (bool) ($p->ask_price ?? false),
                'materials' => $materialsWire,
                'low_stock_at' => $p->low_stock_at_milli !== null ? LedgerService::formatQty($p->low_stock_at_milli) : null,
                'is_active' => (bool) $p->is_active,
                'updated_at' => $p->updated_at_ms,
            ];

            if ($p->kind_changed_at_ms !== null) {
                $productData['kind_changed_at'] = $p->kind_changed_at_ms;
            }

            return [
                'id' => $p->id,
                'entity' => 'product',
                'op' => $isDeleted ? 'delete' : 'upsert',
                'data' => $productData,
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

        if ($change->entity === 'customer') {
            /** @var Customer|null $c */
            $c = Customer::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('id', $change->entity_id)
                ->first();

            if ($c === null) {
                return null;
            }

            $isDeleted = $c->deleted_at_ms !== null;

            $data = [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'updated_at' => (int) $c->updated_at_ms,
                'deleted_at' => $c->deleted_at_ms !== null ? (int) $c->deleted_at_ms : null,
            ];

            // Only users with dube.view (or owner) receive customer notes
            if ($isOwner || $hasDubeView) {
                $data['note'] = $c->note;
            }

            return [
                'id' => $c->id,
                'entity' => 'customer',
                'op' => $isDeleted ? 'delete' : 'upsert',
                'data' => $data,
                'cursor' => $change->seq,
            ];
        }

        if ($change->entity === 'sale') {
            /** @var Sale|null $s */
            $s = Sale::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('id', $change->entity_id)
                ->first();

            if ($s === null) {
                return null;
            }

            // Pull filtering: without dube.view, only receives sales created by this user/device
            if (!$isOwner && !$hasDubeView) {
                $isCreator = ($s->user_id !== null && (string) $s->user_id === (string) $device->user_id)
                    || ($s->device_id !== null && (string) $s->device_id === (string) $device->id);

                if (!$isCreator) {
                    return null;
                }
            }

            return [
                'id' => $s->id,
                'entity' => 'sale',
                'op' => 'upsert',
                'data' => [
                    'id' => $s->id,
                    'location_id' => $s->location_id,
                    'customer_id' => $s->customer_id,
                    'total_cents' => (int) $s->total_cents,
                    'due_date' => $s->due_date,
                    'note' => $s->note,
                    'legacy' => (bool) $s->legacy,
                    'created_at' => (int) $s->created_at_ms,
                    'voided_at' => $s->voided_at_ms !== null ? (int) $s->voided_at_ms : null,
                ],
                'cursor' => $change->seq,
            ];
        }

        if ($change->entity === 'payment') {
            /** @var Payment|null $p */
            $p = Payment::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('id', $change->entity_id)
                ->first();

            if ($p === null) {
                return null;
            }

            // Pull filtering: without dube.view, only receives payments created by this user/device
            if (!$isOwner && !$hasDubeView) {
                $isCreator = ($p->user_id !== null && (string) $p->user_id === (string) $device->user_id)
                    || ($p->device_id !== null && (string) $p->device_id === (string) $device->id);

                if (!$isCreator) {
                    return null;
                }
            }

            return [
                'id' => $p->id,
                'entity' => 'payment',
                'op' => 'upsert',
                'data' => [
                    'id' => $p->id,
                    'sale_id' => $p->sale_id,
                    'customer_id' => $p->customer_id,
                    'location_id' => $p->location_id,
                    'method' => $p->method,
                    'provider_key' => $p->provider_key,
                    'amount_cents' => (int) $p->amount_cents,
                    'reference' => $p->reference,
                    'received_at' => (int) $p->received_at_ms,
                ],
                'cursor' => $change->seq,
            ];
        }

        if ($change->entity === 'payment_provider') {
            /** @var PaymentProvider|null $prov */
            $prov = PaymentProvider::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shopId)
                ->where('provider_key', $change->entity_id)
                ->first();

            if ($prov === null) {
                return null;
            }

            return [
                'id' => $prov->provider_key,
                'entity' => 'payment_provider',
                'op' => 'upsert',
                'data' => [
                    'provider_key' => $prov->provider_key,
                    'kind' => $prov->kind,
                    'name' => $prov->name,
                    'enabled' => (bool) $prov->enabled,
                    'favorite' => (bool) $prov->favorite,
                    'updated_at' => (int) $prov->updated_at_ms,
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

        if (!empty($movement->note)) {
            $decoded = json_decode($movement->note, true);
            if (is_array($decoded) && isset($decoded['to_location_id'])) {
                if (in_array((string) $decoded['to_location_id'], $allowedLocations, true)) {
                    return true;
                }
            }
        }

        return Movement::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('transfer_id', $movement->transfer_id)
            ->whereIn('location_id', $allowedLocations)
            ->exists();
    }
}
