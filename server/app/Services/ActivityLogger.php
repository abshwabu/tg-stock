<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Scopes\ShopScope;
use Illuminate\Support\Str;

class ActivityLogger
{
    /**
     * Record a sensitive action in activity logs.
     */
    public static function log(string $shopId, ?string $userId, string $action, string $description, array $metadata = []): ActivityLog
    {
        return ActivityLog::withoutGlobalScope(ShopScope::class)->create([
            'id' => (string) Str::uuid7(),
            'shop_id' => $shopId,
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'metadata' => !empty($metadata) ? $metadata : null,
            'created_at' => now(),
        ]);
    }
}
