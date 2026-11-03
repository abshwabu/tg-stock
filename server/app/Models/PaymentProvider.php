<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProvider extends Model
{
    use BelongsToShop;

    public $incrementing = false;
    protected $primaryKey = 'provider_key';
    protected $keyType = 'string';

    protected $fillable = [
        'shop_id',
        'provider_key',
        'kind',
        'name',
        'enabled',
        'favorite',
        'updated_at_ms',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'favorite' => 'boolean',
        'updated_at_ms' => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
