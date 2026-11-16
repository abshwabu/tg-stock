<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Revocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'license_id',
        'license_id_u64_hex',
        'reason',
        'revoked_by',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
