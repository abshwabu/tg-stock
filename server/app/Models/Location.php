<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Scopes\ShopScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Location extends Model
{
    use HasFactory, BelongsToShop;

    protected $table = 'locations';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'shop_id',
        'name',
        'kind', // store | warehouse | other
        'is_default',
        'is_active',
        'updated_at_ms',
        'deleted_at_ms',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'updated_at_ms' => 'integer',
        'deleted_at_ms' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Location $location): void {
            if (empty($location->id)) {
                $location->id = (string) Str::uuid7();
            }
            if (empty($location->updated_at_ms)) {
                $location->updated_at_ms = (int) (microtime(true) * 1000);
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'location_id');
    }

    /**
     * Determine if this location currently holds any non-zero product stock.
     */
    public function hasStock(): bool
    {
        return Movement::withoutGlobalScope(ShopScope::class)
            ->where('location_id', $this->id)
            ->groupBy('product_id')
            ->havingRaw('SUM(qty_milli) != 0')
            ->exists();
    }

    /**
     * Determine if this location can be safely archived or deleted.
     */
    public function canArchive(?string &$reason = null): bool
    {
        if ($this->hasStock()) {
            $reason = 'Location still holds stock and cannot be archived or deleted. Transfer remaining stock out first.';
            return false;
        }

        if ($this->is_default) {
            $reason = 'Default location cannot be archived or deleted.';
            return false;
        }

        $otherActiveCount = self::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop_id)
            ->where('is_active', true)
            ->whereNull('deleted_at_ms')
            ->where('id', '!=', $this->id)
            ->count();

        if ($otherActiveCount === 0) {
            $reason = 'At least one active location must always exist in the shop.';
            return false;
        }

        return true;
    }
}
