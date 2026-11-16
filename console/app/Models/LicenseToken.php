<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'license_id',
        'token',
        'kid',
        'issued_day',
        'nbf_day',
        'exp_day',
        'grace_days',
        'seats',
        'features',
        'max_major',
        'issued_by',
        'payment_id',
        'raw_payload_hex',
    ];

    protected function casts(): array
    {
        return [
            'kid' => 'integer',
            'issued_day' => 'integer',
            'nbf_day' => 'integer',
            'exp_day' => 'integer',
            'grace_days' => 'integer',
            'seats' => 'integer',
            'features' => 'integer',
            'max_major' => 'integer',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
