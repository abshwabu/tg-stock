<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Movement extends Model
{
    use HasFactory, BelongsToShop;

    protected $table = 'movements';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'shop_id',
        'product_id',
        'sale_id',
        'location_id',
        'transfer_id',
        'user_id',
        'device_id',
        'type',
        'tracks_stock',
        'source_movement_id',
        'qty_milli',
        'unit_price_cents',
        'note',
        'created_at_ms',
        'server_seq',
    ];

    protected $casts = [
        'tracks_stock' => 'boolean',
        'source_movement_id' => 'string',
        'qty_milli' => 'integer',
        'unit_price_cents' => 'integer',
        'created_at_ms' => 'integer',
        'server_seq' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Movement $movement): void {
            if (empty($movement->id)) {
                $movement->id = (string) Str::uuid7();
            }
            if (empty($movement->created_at_ms)) {
                $movement->created_at_ms = (int) (microtime(true) * 1000);
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function sourceMovement(): BelongsTo
    {
        return $this->belongsTo(Movement::class, 'source_movement_id');
    }
}
