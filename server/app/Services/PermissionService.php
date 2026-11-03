<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\Location;
use App\Models\Movement;
use App\Models\PermissionLog;
use App\Models\Product;
use App\Models\RejectedChange;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserLocation;
use App\Models\UserPermission;
use App\Scopes\ShopScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PermissionService
{
    public const PERMISSIONS = [
        'products.view',
        'products.create',
        'products.edit',
        'products.delete',
        'price.edit',
        'cost.view',
        'stock.in',
        'sale.create',
        'stock.adjust',
        'transfer.create',
        'transfer.receive',
        'reports.view',
        'reports.own_sales',
        'export',
        'team.manage',
        'locations.manage',
        'undo.own',
    ];

    public const PRESETS = [
        'owner' => self::PERMISSIONS,
        'salesperson' => [
            'products.view',
            'sale.create',
            'reports.own_sales',
            'undo.own',
        ],
        'warehouse_keeper' => [
            'products.view',
            'stock.in',
            'transfer.create',
            'transfer.receive',
            'undo.own',
        ],
        'custom' => [],
        'staff' => [ // Legacy alias mapped to salesperson
            'products.view',
            'sale.create',
            'reports.own_sales',
            'undo.own',
        ],
    ];

    /**
     * Compute effective permission list for a user.
     * If $atMs is provided, resolves the snapshot effective at that exact epoch ms.
     *
     * @return string[]
     */
    public function getPermissions(User|string $user, ?int $atMs = null): array
    {
        $userModel = $this->resolveUser($user);
        if ($userModel === null) {
            return [];
        }

        if ($atMs !== null) {
            /** @var PermissionLog|null $log */
            $log = PermissionLog::where('user_id', $userModel->id)
                ->where('effective_from_ms', '<=', $atMs)
                ->orderByDesc('effective_from_ms')
                ->first();

            if ($log === null) {
                // If atMs is before the earliest log, use the earliest known log
                $log = PermissionLog::where('user_id', $userModel->id)
                    ->orderBy('effective_from_ms', 'asc')
                    ->first();
            }

            if ($log !== null) {
                $snapshot = is_array($log->snapshot) ? $log->snapshot : json_decode((string) $log->snapshot, true);
                if (isset($snapshot['active']) && !$snapshot['active']) {
                    return [];
                }
                if (($snapshot['role'] ?? '') === 'owner') {
                    return self::PERMISSIONS;
                }
                return $snapshot['permissions'] ?? [];
            }
        }

        if (!$userModel->is_active) {
            return [];
        }

        if ($userModel->role === 'owner') {
            return self::PERMISSIONS;
        }

        // Live calculation: Preset + Overrides (deny wins!)
        $base = self::PRESETS[$userModel->role] ?? [];
        $overrides = UserPermission::where('user_id', $userModel->id)->get();

        $allows = [];
        $denies = [];

        foreach ($overrides as $ov) {
            if ($ov->effect === 'deny') {
                $denies[] = $ov->permission;
            } elseif ($ov->effect === 'allow') {
                $allows[] = $ov->permission;
            }
        }

        $effective = array_unique(array_merge($base, $allows));
        $effective = array_values(array_diff($effective, $denies));

        return $effective;
    }

    /**
     * Compute effective location IDs a user is authorized to act within.
     * Owner has access to all active locations.
     *
     * @return string[]
     */
    public function getLocationIds(User|string $user, ?int $atMs = null): array
    {
        $userModel = $this->resolveUser($user);
        if ($userModel === null) {
            return [];
        }

        if ($atMs !== null) {
            /** @var PermissionLog|null $log */
            $log = PermissionLog::where('user_id', $userModel->id)
                ->where('effective_from_ms', '<=', $atMs)
                ->orderByDesc('effective_from_ms')
                ->first();

            if ($log === null) {
                $log = PermissionLog::where('user_id', $userModel->id)
                    ->orderBy('effective_from_ms', 'asc')
                    ->first();
            }

            if ($log !== null) {
                $snapshot = is_array($log->snapshot) ? $log->snapshot : json_decode((string) $log->snapshot, true);
                if (isset($snapshot['active']) && !$snapshot['active']) {
                    return [];
                }
                if (($snapshot['role'] ?? '') === 'owner') {
                    return Location::withoutGlobalScope(ShopScope::class)
                        ->where('shop_id', $userModel->shop_id)
                        ->whereNull('deleted_at_ms')
                        ->pluck('id')
                        ->map(fn ($id) => (string) $id)
                        ->toArray();
                }
                return $snapshot['location_ids'] ?? [];
            }
        }

        if (!$userModel->is_active) {
            return [];
        }

        if ($userModel->role === 'owner') {
            return Location::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $userModel->shop_id)
                ->whereNull('deleted_at_ms')
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->toArray();
        }

        return UserLocation::where('user_id', $userModel->id)
            ->pluck('location_id')
            ->map(fn ($id) => (string) $id)
            ->toArray();
    }

    /**
     * The single authoritative permission check across sync, bot, and API.
     */
    public function can(
        User|string|null $user,
        string $permission,
        Location|string|null $location = null,
        ?int $atMs = null
    ): bool {
        if ($user === null) {
            return false;
        }

        $userModel = $this->resolveUser($user);
        if ($userModel === null) {
            return false;
        }

        if ($atMs === null) {
            if (!$userModel->is_active) {
                return false;
            }

            // Owners bypass all feature and location restrictions
            if ($userModel->role === 'owner') {
                return true;
            }

            // 1. Feature permission check
            $permissions = $this->getPermissions($userModel);
            if (!in_array($permission, $permissions, true)) {
                return false;
            }

            // 2. Location scope check
            if ($location !== null) {
                $locId = $location instanceof Location ? (string) $location->id : (string) $location;
                $allowedLocations = $this->getLocationIds($userModel);
                if (!in_array($locId, $allowedLocations, true)) {
                    return false;
                }
            }

            return true;
        }

        // Snapshot lookup for historical epoch $atMs
        /** @var PermissionLog|null $log */
        $log = PermissionLog::where('user_id', $userModel->id)
            ->where('effective_from_ms', '<=', $atMs)
            ->orderByDesc('effective_from_ms')
            ->first();

        if ($log === null) {
            $log = PermissionLog::where('user_id', $userModel->id)
                ->orderBy('effective_from_ms', 'asc')
                ->first();
        }

        if ($log !== null) {
            $snapshot = is_array($log->snapshot) ? $log->snapshot : json_decode((string) $log->snapshot, true);
            if (isset($snapshot['active']) && !$snapshot['active']) {
                return false;
            }
            if (($snapshot['role'] ?? '') === 'owner') {
                return true;
            }

            $permissions = $snapshot['permissions'] ?? [];
            if (!in_array($permission, $permissions, true)) {
                return false;
            }

            if ($location !== null) {
                $locId = $location instanceof Location ? (string) $location->id : (string) $location;
                $allowedLocations = $snapshot['location_ids'] ?? [];
                if (!in_array($locId, $allowedLocations, true)) {
                    return false;
                }
            }

            return true;
        }

        // Fallback if no logs exist
        if (!$userModel->is_active) {
            return false;
        }
        if ($userModel->role === 'owner') {
            return true;
        }

        $permissions = $this->getPermissions($userModel);
        if (!in_array($permission, $permissions, true)) {
            return false;
        }

        if ($location !== null) {
            $locId = $location instanceof Location ? (string) $location->id : (string) $location;
            $allowedLocations = $this->getLocationIds($userModel);
            if (!in_array($locId, $allowedLocations, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Update user permissions, role, and/or locations.
     * Enforces last owner protection, increments perm_version, and records historical snapshot.
     */
    public function updatePermissions(
        User $user,
        array $overrides = [],
        ?array $locationIds = null,
        ?string $newRole = null,
        ?User $changedBy = null
    ): void {
        // Enforce last owner protection
        if ($user->role === 'owner' && $newRole !== null && $newRole !== 'owner') {
            $otherOwners = User::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $user->shop_id)
                ->where('role', 'owner')
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherOwners === 0) {
                throw new InvalidArgumentException('Cannot demote or remove the last active owner of the shop.');
            }
        }

        DB::transaction(function () use ($user, $overrides, $locationIds, $newRole, $changedBy): void {
            if ($newRole !== null) {
                $user->role = $newRole;
            }

            // Apply permission overrides
            foreach ($overrides as $perm => $effect) {
                if ($effect === null || $effect === '' || $effect === 'default') {
                    UserPermission::where('user_id', $user->id)->where('permission', $perm)->delete();
                } elseif (in_array($effect, ['allow', 'deny'], true)) {
                    UserPermission::updateOrInsert(
                        ['user_id' => $user->id, 'permission' => $perm],
                        ['id' => (string) Str::uuid7(), 'effect' => $effect, 'updated_at' => now(), 'created_at' => now()]
                    );
                }
            }

            // Sync locations if provided
            if ($locationIds !== null) {
                UserLocation::where('user_id', $user->id)->delete();
                foreach ($locationIds as $locId) {
                    UserLocation::create([
                        'id' => (string) Str::uuid7(),
                        'user_id' => $user->id,
                        'location_id' => (string) $locId,
                    ]);
                }
            }

            // Increment version
            $user->perm_version = ($user->perm_version ?? 0) + 1;
            $user->save();

            // Append snapshot to permission_log
            $perms = $this->getPermissions($user);
            $locs = $this->getLocationIds($user);
            $nowMs = (int) (microtime(true) * 1000);

            PermissionLog::create([
                'id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'effective_from_ms' => $nowMs,
                'snapshot' => [
                    'active' => (bool) $user->is_active,
                    'role' => $user->role,
                    'permissions' => $perms,
                    'location_ids' => $locs,
                ],
                'changed_by' => $changedBy?->id,
            ]);
        });
    }

    /**
     * Deactivate a user account while safeguarding the last shop owner.
     */
    public function deactivateUser(User $user, ?User $changedBy = null): void
    {
        if ($user->role === 'owner') {
            $otherOwners = User::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $user->shop_id)
                ->where('role', 'owner')
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherOwners === 0) {
                throw new InvalidArgumentException('Cannot deactivate or remove the last active owner of the shop.');
            }
        }

        DB::transaction(function () use ($user, $changedBy): void {
            $user->is_active = false;
            $user->perm_version = ($user->perm_version ?? 0) + 1;
            $user->save();

            // Revoke associated devices
            Device::withoutGlobalScope(ShopScope::class)
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $nowMs = (int) (microtime(true) * 1000);
            PermissionLog::create([
                'id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'effective_from_ms' => $nowMs,
                'snapshot' => [
                    'active' => false,
                    'role' => $user->role,
                    'permissions' => [],
                    'location_ids' => [],
                ],
                'changed_by' => $changedBy?->id,
            ]);
        });
    }

    /**
     * Double-confirmed shop ownership transfer.
     */
    public function transferOwnership(
        Shop $shop,
        User $currentOwner,
        User $newOwner,
        bool $confirmed = false,
        ?string $confirmationCode = null
    ): array {
        if ($currentOwner->role !== 'owner') {
            throw new InvalidArgumentException('Current user must be an owner to transfer ownership.');
        }

        if ((string) $currentOwner->shop_id !== (string) $shop->id || (string) $newOwner->shop_id !== (string) $shop->id) {
            throw new InvalidArgumentException('Both users must belong to the same shop.');
        }

        $cacheKey = "ownership_transfer:{$shop->id}:{$newOwner->id}";

        if (!$confirmed) {
            $code = strtoupper(Str::random(6));
            Cache::put($cacheKey, [
                'code' => $code,
                'current_owner_id' => $currentOwner->id,
                'new_owner_id' => $newOwner->id,
            ], now()->addMinutes(15));

            return [
                'confirmed' => false,
                'confirmation_code' => $code,
                'message' => "Ownership transfer initiated. Submit confirmation_code '{$code}' to complete.",
            ];
        }

        // Validate double confirmation
        $pending = Cache::get($cacheKey);
        if ($pending === null || ($pending['code'] ?? '') !== $confirmationCode) {
            throw new InvalidArgumentException('Invalid or expired ownership transfer confirmation code.');
        }

        DB::transaction(function () use ($shop, $newOwner, $currentOwner): void {
            $currentOwner->role = 'salesperson';
            $currentOwner->perm_version = ($currentOwner->perm_version ?? 0) + 1;
            $currentOwner->save();

            $newOwner->role = 'owner';
            $newOwner->perm_version = ($newOwner->perm_version ?? 0) + 1;
            $newOwner->save();

            $nowMs = (int) (microtime(true) * 1000);

            PermissionLog::create([
                'id' => (string) Str::uuid7(),
                'user_id' => $currentOwner->id,
                'effective_from_ms' => $nowMs,
                'snapshot' => [
                    'active' => true,
                    'role' => 'salesperson',
                    'permissions' => self::PRESETS['salesperson'],
                    'location_ids' => $this->getLocationIds($currentOwner),
                ],
                'changed_by' => $currentOwner->id,
            ]);

            PermissionLog::create([
                'id' => (string) Str::uuid7(),
                'user_id' => $newOwner->id,
                'effective_from_ms' => $nowMs,
                'snapshot' => [
                    'active' => true,
                    'role' => 'owner',
                    'permissions' => self::PERMISSIONS,
                    'location_ids' => $this->getLocationIds($newOwner),
                ],
                'changed_by' => $currentOwner->id,
            ]);

            ActivityLogger::log(
                (string) $shop->id,
                $currentOwner->id,
                'ownership.transfer',
                "Ownership transferred from {$currentOwner->name} to {$newOwner->name}."
            );
        });

        Cache::forget($cacheKey);

        return [
            'confirmed' => true,
            'message' => "Ownership successfully transferred to [{$newOwner->name}].",
        ];
    }

    /**
     * Approve a rejected change and apply it as the original user.
     */
    public function approveRejectedChange(
        RejectedChange|string $rejectedChange,
        User $reviewedBy
    ): bool {
        $change = $rejectedChange instanceof RejectedChange
            ? $rejectedChange
            : RejectedChange::withoutGlobalScope(ShopScope::class)->findOrFail($rejectedChange);

        if ($change->status !== 'pending') {
            return false;
        }

        $data = $change->change_data;

        if ($change->entity === 'movement') {
            $movementData = $data['data'] ?? $data;
            $movement = new Movement([
                'id' => $change->change_id,
                'shop_id' => $change->shop_id,
                'product_id' => $movementData['product_id'],
                'location_id' => $movementData['location_id'] ?? null,
                'transfer_id' => $movementData['transfer_id'] ?? null,
                'user_id' => $change->user_id,
                'device_id' => $change->device_id,
                'type' => $movementData['type'],
                'qty_milli' => LedgerService::parseQty($movementData['qty'] ?? '0.000'),
                'unit_price_cents' => (int) ($movementData['unit_price_cents'] ?? 0),
                'note' => $movementData['note'] ?? null,
                'created_at_ms' => $change->client_ts,
            ]);

            app(LedgerService::class)->record($movement);
        } elseif ($change->entity === 'product') {
            $pData = $data['data'] ?? $data;
            $existing = Product::withoutGlobalScope(ShopScope::class)
                ->where('id', $change->change_id)
                ->where('shop_id', $change->shop_id)
                ->first();

            if ($change->op === 'delete' && $existing !== null) {
                $existing->deleted_at_ms = $change->client_ts;
                $existing->is_active = false;
                $existing->updated_at_ms = $change->client_ts;
                $existing->save();
            } else {
                if ($existing !== null) {
                    $existing->name = (string) ($pData['name'] ?? $existing->name);
                    $existing->sku = isset($pData['sku']) ? (string) $pData['sku'] : $existing->sku;
                    $existing->unit = (string) ($pData['unit'] ?? $existing->unit);
                    $existing->cost_price_cents = isset($pData['cost_price_cents']) ? (int) $pData['cost_price_cents'] : $existing->cost_price_cents;
                    $existing->sell_price_cents = isset($pData['sell_price_cents']) ? (int) $pData['sell_price_cents'] : $existing->sell_price_cents;
                    $existing->is_active = isset($pData['is_active']) ? (bool) $pData['is_active'] : $existing->is_active;
                    $existing->updated_at_ms = $change->client_ts;
                    $existing->save();
                } else {
                    Product::withoutGlobalScope(ShopScope::class)->create([
                        'id' => $change->change_id,
                        'shop_id' => $change->shop_id,
                        'name' => (string) ($pData['name'] ?? ''),
                        'sku' => isset($pData['sku']) ? (string) $pData['sku'] : null,
                        'unit' => (string) ($pData['unit'] ?? 'pcs'),
                        'cost_price_cents' => (int) ($pData['cost_price_cents'] ?? 0),
                        'sell_price_cents' => (int) ($pData['sell_price_cents'] ?? 0),
                        'is_active' => isset($pData['is_active']) ? (bool) $pData['is_active'] : true,
                        'updated_at_ms' => $change->client_ts,
                    ]);
                }
            }
        } elseif ($change->entity === 'location') {
            $lData = $data['data'] ?? $data;
            $existing = Location::withoutGlobalScope(ShopScope::class)
                ->where('id', $change->change_id)
                ->where('shop_id', $change->shop_id)
                ->first();

            if ($change->op === 'delete' && $existing !== null) {
                $existing->deleted_at_ms = $change->client_ts;
                $existing->is_active = false;
                $existing->updated_at_ms = $change->client_ts;
                $existing->save();
            } else {
                if ($existing !== null) {
                    $existing->name = (string) ($lData['name'] ?? $existing->name);
                    $existing->kind = (string) ($lData['kind'] ?? $existing->kind);
                    $existing->is_active = isset($lData['is_active']) ? (bool) $lData['is_active'] : $existing->is_active;
                    $existing->updated_at_ms = $change->client_ts;
                    $existing->save();
                } else {
                    Location::withoutGlobalScope(ShopScope::class)->create([
                        'id' => $change->change_id,
                        'shop_id' => $change->shop_id,
                        'name' => (string) ($lData['name'] ?? ''),
                        'kind' => (string) ($lData['kind'] ?? 'store'),
                        'is_default' => (bool) ($lData['is_default'] ?? false),
                        'is_active' => (bool) ($lData['is_active'] ?? true),
                        'updated_at_ms' => $change->client_ts,
                    ]);
                }
            }
        }

        $change->status = 'approved';
        $change->reviewed_by = $reviewedBy->id;
        $change->reviewed_at_ms = (int) (microtime(true) * 1000);
        $change->save();

        return true;
    }

    /**
     * Dismiss a rejected change.
     */
    public function dismissRejectedChange(
        RejectedChange|string $rejectedChange,
        User $reviewedBy
    ): bool {
        $change = $rejectedChange instanceof RejectedChange
            ? $rejectedChange
            : RejectedChange::withoutGlobalScope(ShopScope::class)->findOrFail($rejectedChange);

        if ($change->status !== 'pending') {
            return false;
        }

        $change->status = 'dismissed';
        $change->reviewed_by = $reviewedBy->id;
        $change->reviewed_at_ms = (int) (microtime(true) * 1000);
        $change->save();

        return true;
    }

    /**
     * Resolve a User instance from model or UUID.
     */
    protected function resolveUser(User|string $user): ?User
    {
        if ($user instanceof User) {
            return $user;
        }

        return User::withoutGlobalScope(ShopScope::class)->find($user);
    }
}
