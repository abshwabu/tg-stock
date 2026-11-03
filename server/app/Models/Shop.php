<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Shop extends Model
{
    use HasFactory;

    protected $table = 'shops';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'currency',
        'language',
        'low_stock_default_milli',
        'allow_negative_stock',
        'daily_summary_time',
        'daily_summary_last_sent_date',
        'staff_can_adjust',
        'use_ethiopian_calendar',
        'timezone',
    ];

    protected $casts = [
        'low_stock_default_milli' => 'integer',
        'allow_negative_stock' => 'boolean',
        'staff_can_adjust' => 'boolean',
        'use_ethiopian_calendar' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Shop $shop): void {
            if (empty($shop->id)) {
                $shop->id = (string) Str::uuid7();
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'shop_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'shop_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'shop_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'shop_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(Change::class, 'shop_id');
    }
}
