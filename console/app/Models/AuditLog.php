<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_name',
        'action',
        'resource_type',
        'resource_id',
        'ip_address',
        'user_agent',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function log(
        string $action,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?array $payload = null,
        ?Request $request = null,
        ?User $user = null
    ): static {
        $user = $user ?? auth()->user();
        $request = $request ?? request();

        return static::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'System/API',
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId ? (string)$resourceId : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'payload' => $payload,
        ]);
    }
}
