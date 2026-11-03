<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Device extends Model
{
    use HasFactory, BelongsToShop;

    protected $table = 'devices';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'shop_id',
        'user_id',
        'name',
        'token_hash',
        'last_seen_at',
        'revoked_at',
        'revoked_sync_used',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
        'revoked_sync_used' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Device $device): void {
            if (empty($device->id)) {
                $device->id = (string) Str::uuid7();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScope(\App\Scopes\ShopScope::class);
    }
}
