<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PairingCode extends Model
{
    use HasFactory;

    protected $table = 'pairing_codes';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'code_hash',
        'shop_name',
        'device_name',
        'status',
        'device_token',
        'device_id',
        'shop_id',
        'user_id',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PairingCode $pairing): void {
            if (empty($pairing->id)) {
                $pairing->id = (string) Str::uuid7();
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
