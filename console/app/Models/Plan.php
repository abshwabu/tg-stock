<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    public const FEATURE_ONLINE_SYNC    = 1 << 0; // 0x0001
    public const FEATURE_TELEGRAM_BOT   = 1 << 1; // 0x0002
    public const FEATURE_TEAM_ROLES     = 1 << 2; // 0x0004
    public const FEATURE_MULTI_LOCATION = 1 << 3; // 0x0008
    public const FEATURE_EXPORT         = 1 << 4; // 0x0010

    protected $fillable = [
        'name',
        'kind',
        'duration_days',
        'seats',
        'features',
        'grace_days',
        'price_etb',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'seats' => 'integer',
            'features' => 'integer',
            'grace_days' => 'integer',
            'price_etb' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function planTypeInt(): int
    {
        return match ($this->kind) {
            'trial' => 0,
            'monthly' => 1,
            'yearly' => 2,
            'lifetime' => 3,
            default => 4,
        };
    }

    public function featureList(): array
    {
        $list = [];
        if ($this->features & self::FEATURE_ONLINE_SYNC) $list[] = 'online_sync';
        if ($this->features & self::FEATURE_TELEGRAM_BOT) $list[] = 'telegram_bot';
        if ($this->features & self::FEATURE_TEAM_ROLES) $list[] = 'team_roles';
        if ($this->features & self::FEATURE_MULTI_LOCATION) $list[] = 'multi_location';
        if ($this->features & self::FEATURE_EXPORT) $list[] = 'export';
        return $list;
    }
}
