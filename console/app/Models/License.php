<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\DateEpochService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class License extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'customer_id',
        'plan_id',
        'type', // 'device' or 'shop'
        'bind_code',
        'bind_bytes_hex',
        'status', // 'active', 'suspended', 'revoked', 'cancelled'
        'current_exp_day',
    ];

    protected function casts(): array
    {
        return [
            'current_exp_day' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(LicenseToken::class)->orderByDesc('id');
    }

    public function latestToken(): HasOne
    {
        return $this->hasOne(LicenseToken::class)->latestOfMany();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function reissues(): HasMany
    {
        return $this->hasMany(Reissue::class)->orderByDesc('id');
    }

    public function revocation(): HasOne
    {
        return $this->hasOne(Revocation::class);
    }

    /**
     * Compute real-time operational state according to SPEC §8.
     */
    public function getRealtimeState(?int $today = null): string
    {
        if ($this->status === 'revoked') {
            return 'REVOKED';
        }
        if ($this->status === 'suspended') {
            return 'SUSPENDED';
        }
        if ($this->status === 'cancelled') {
            return 'CANCELLED';
        }

        $today = $today ?? DateEpochService::todayDay();
        $expDay = $this->current_exp_day;

        if ($expDay === 0xFFFF) {
            return 'ACTIVE';
        }

        $graceDays = $this->plan?->grace_days ?? 7;

        if ($today <= $expDay) {
            $remaining = $expDay - $today;
            return ($remaining <= 7) ? 'EXPIRING' : 'ACTIVE';
        }

        // Past expiry
        $past = $today - $expDay;
        if ($past <= $graceDays) {
            return 'GRACE';
        }

        return 'EXPIRED';
    }

    public function getRemainingDays(?int $today = null): int|string
    {
        if ($this->current_exp_day === 0xFFFF) {
            return 'Lifetime';
        }
        $today = $today ?? DateEpochService::todayDay();
        return $this->current_exp_day - $today;
    }
}
