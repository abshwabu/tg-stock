<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashFlowEntry extends Model
{
    use BelongsToShop;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'shop_id',
        'user_id',
        'device_id',
        'type',
        'amount_cents',
        'category',
        'payment_method',
        'note',
        'created_at_ms',
        'updated_at_ms',
        'deleted_at_ms',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'created_at_ms' => 'integer',
        'updated_at_ms' => 'integer',
        'deleted_at_ms' => 'integer',
    ];

    public function isIncome(): bool
    {
        return $this->type === 'income';
    }

    public function isExpense(): bool
    {
        return $this->type === 'expense';
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
