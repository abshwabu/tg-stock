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
        'locations_enabled',
        'transfer_requires_receipt',
        'daily_summary_time',
        'daily_summary_last_sent_date',
        'staff_can_adjust',
        'use_ethiopian_calendar',
        'timezone',
        'payment_methods_enabled',
        'dube_default_days',
        'require_payment_reference',
        'dube_reminder_last_sent_date',
    ];

    protected $casts = [
        'low_stock_default_milli' => 'integer',
        'allow_negative_stock' => 'boolean',
        'locations_enabled' => 'boolean',
        'transfer_requires_receipt' => 'boolean',
        'staff_can_adjust' => 'boolean',
        'use_ethiopian_calendar' => 'boolean',
        'payment_methods_enabled' => 'array',
        'dube_default_days' => 'integer',
        'require_payment_reference' => 'boolean',
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

        static::created(function (Shop $shop): void {
            // Guarantee one default "Main" location per shop
            $shop->getDefaultLocation();
        });
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class, 'shop_id');
    }

    /**
     * Retrieve or create the default location ("Main") for this shop.
     */
    public function getDefaultLocation(): Location
    {
        /** @var Location|null $defaultLoc */
        $defaultLoc = Location::withoutGlobalScope(\App\Scopes\ShopScope::class)
            ->where('shop_id', $this->id)
            ->where('is_default', true)
            ->first();

        if ($defaultLoc !== null) {
            return $defaultLoc;
        }

        $nowMs = (int) (microtime(true) * 1000);
        $locationId = (string) Str::uuid7();

        \Illuminate\Support\Facades\DB::table('locations')->insert([
            'id' => $locationId,
            'shop_id' => $this->id,
            'name' => 'Main',
            'kind' => 'store',
            'is_default' => 1,
            'is_active' => 1,
            'updated_at_ms' => $nowMs,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Location::withoutGlobalScope(\App\Scopes\ShopScope::class)->find($locationId);
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

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'shop_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'shop_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'shop_id');
    }

    public function paymentProviders(): HasMany
    {
        return $this->hasMany(PaymentProvider::class, 'shop_id');
    }
}
