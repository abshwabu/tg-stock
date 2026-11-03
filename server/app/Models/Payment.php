<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToShop;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'shop_id',
        'sale_id',
        'customer_id',
        'location_id',
        'user_id',
        'device_id',
        'method',
        'provider_key',
        'amount_cents',
        'reference',
        'received_at_ms',
        'created_at_ms',
        'server_seq',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'received_at_ms' => 'integer',
        'created_at_ms' => 'integer',
        'server_seq' => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isReversal(): bool
    {
        return $this->amount_cents < 0;
    }
}
