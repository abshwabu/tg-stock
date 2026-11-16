<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reissue extends Model
{
    use HasFactory;

    protected $fillable = [
        'license_id',
        'old_bind_code',
        'old_bind_bytes_hex',
        'new_bind_code',
        'new_bind_bytes_hex',
        'reason',
        'issued_by',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
