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
        'is_active',
    ];

    protected $casts = [
        'telegram_id' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (empty($user->id)) {
                $user->id = (string) Str::uuid7();
            }
        });
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
