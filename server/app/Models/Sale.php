<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use BelongsToShop;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'shop_id',
        'location_id',
        'user_id',
        'device_id',
        'customer_id',
        'total_cents',
        'due_date',
        'note',
        'legacy',
        'voided_at_ms',
        'created_at_ms',
        'updated_at_ms',
    ];

    protected $casts = [
        'total_cents' => 'integer',
        'legacy' => 'boolean',
        'voided_at_ms' => 'integer',
        'created_at_ms' => 'integer',
        'updated_at_ms' => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isVoided(): bool
    {
        return $this->voided_at_ms !== null;
    }
}
