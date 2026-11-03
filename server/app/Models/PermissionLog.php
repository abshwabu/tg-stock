<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PermissionLog extends Model
{
    use HasFactory;

    protected $table = 'permission_log';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'effective_from_ms',
        'snapshot', // JSON {role, permissions, location_ids}
        'changed_by',
    ];

    protected $casts = [
        'effective_from_ms' => 'integer',
        'snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (PermissionLog $log): void {
            if (empty($log->id)) {
                $log->id = (string) Str::uuid7();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
