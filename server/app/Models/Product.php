<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, BelongsToShop;

    protected $table = 'products';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'shop_id',
        'name',
        'sku',
        'unit',
        'cost_price_cents',
        'sell_price_cents',
        'low_stock_at_milli',
        'is_active',
        'updated_at_ms',
        'deleted_at_ms',
    ];

    protected $casts = [
        'cost_price_cents' => 'integer',
        'sell_price_cents' => 'integer',
        'low_stock_at_milli' => 'integer',
        'is_active' => 'boolean',
        'updated_at_ms' => 'integer',
        'deleted_at_ms' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (empty($product->id)) {
                $product->id = (string) Str::uuid7();
            }
            if (empty($product->updated_at_ms)) {
                $product->updated_at_ms = (int) (microtime(true) * 1000);
            }
        });
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'product_id');
    }
}
