<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopLicense extends Model
{
    protected $table = 'shop_licenses';
    protected $primaryKey = 'shop_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'shop_id',
        'token',
        'license_id',
        'plan',
        'exp_day',
        'grace_days',
        'seats',
        'features',
        'status',
    ];

    protected $casts = [
        'plan' => 'integer',
        'exp_day' => 'integer',
        'grace_days' => 'integer',
        'seats' => 'integer',
        'features' => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id', 'id');
    }
}
