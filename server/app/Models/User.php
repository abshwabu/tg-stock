<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class User extends Model
{
    use HasFactory, BelongsToShop;

    protected $table = 'users';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'telegram_id',
        'name',
        'language',
        'shop_id',
        'role',
        'perm_version',
        'is_active',
    ];

    protected $casts = [
        'telegram_id' => 'integer',
        'perm_version' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
        'perm_version' => 1,
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (empty($user->id)) {
                $user->id = (string) Str::uuid7();
            }
            if ($user->perm_version === null) {
                $user->perm_version = 1;
            }
            if ($user->is_active === null) {
                $user->is_active = true;
            }
        });

        static::created(function (User $user): void {
            $nowMs = (int) (microtime(true) * 1000);
            $role = $user->role ?? 'salesperson';
            $perms = $role === 'owner'
                ? \App\Services\PermissionService::PERMISSIONS
                : (\App\Services\PermissionService::PRESETS[$role] ?? []);

            \App\Models\PermissionLog::create([
                'id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'effective_from_ms' => $nowMs,
                'snapshot' => [
                    'active' => $user->is_active ?? true,
                    'role' => $role,
                    'permissions' => $perms,
                    'location_ids' => [],
                ],
                'changed_by' => null,
            ]);
        });
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class, 'user_id');
    }

    public function assignedLocations(): HasMany
    {
        return $this->hasMany(UserLocation::class, 'user_id');
    }

    public function permissionLogs(): HasMany
    {
        return $this->hasMany(PermissionLog::class, 'user_id');
    }

    public function rejectedChanges(): HasMany
    {
        return $this->hasMany(RejectedChange::class, 'user_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'user_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'user_id');
    }
}
