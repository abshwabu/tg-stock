<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RejectedChange extends Model
{
    use HasFactory, BelongsToShop;

    protected $table = 'rejected_changes';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'shop_id',
        'user_id',
        'device_id',
        'change_id',
        'entity',
        'op',
        'change_data',
        'client_ts',
        'reason',
        'details',
        'status', // pending | approved | dismissed
        'reviewed_by',
        'reviewed_at_ms',
    ];

    protected $casts = [
        'change_data' => 'array',
        'client_ts' => 'integer',
        'reviewed_at_ms' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (RejectedChange $rc): void {
            if (empty($rc->id)) {
                $rc->id = (string) Str::uuid7();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
