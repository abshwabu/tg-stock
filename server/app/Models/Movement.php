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
        'user_id',
        'device_id',
        'type',
        'qty_milli',
        'unit_price_cents',
        'note',
        'created_at_ms',
        'server_seq',
    ];

    protected $casts = [
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
