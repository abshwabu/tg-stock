<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trial extends Model
{
    use HasFactory;

    protected $fillable = [
        'telegram_id',
        'bind_bytes_hex',
        'bind_code',
        'license_id',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
