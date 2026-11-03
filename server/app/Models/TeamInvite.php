<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TeamInvite extends Model
{
    use HasFactory, BelongsToShop;

    protected $table = 'team_invites';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'shop_id',
        'inviter_user_id',
        'token_hash',
        'role',
        'expires_at',
        'used_at',
        'used_by_telegram_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'used_by_telegram_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (TeamInvite $invite): void {
            if (empty($invite->id)) {
                $invite->id = (string) Str::uuid7();
            }
        });
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }
}
