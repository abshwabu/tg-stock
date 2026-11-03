<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Context\CurrentShopContext;
use App\Models\Device;
use App\Models\Shop;
use App\Scopes\ShopScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class DeviceTokenAuth
{
    /**
     * Authenticate request using Bearer device token.
     * Enforces constant-time hash comparison, instant revocation check,
     * throttled last_seen_at updates (<= once per minute), and per-device rate limiting.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (empty($token)) {
            return response()->json([
                'error' => 'Missing or invalid Authorization header.',
            ], 401);
        }

        $tokenHash = hash('sha256', $token);

        /** @var Device|null $device */
        $device = Device::withoutGlobalScope(ShopScope::class)
            ->where('token_hash', $tokenHash)
            ->first();

        if ($device === null) {
            return response()->json([
                'error' => 'Invalid device token.',
            ], 401);
        }

        // Constant-time hash verification against timing attacks
        if (!hash_equals($device->token_hash, $tokenHash)) {
            return response()->json([
                'error' => 'Invalid device token.',
            ], 401);
        }

        // Check revocation & deactivation status
        $isRevoked = $device->revoked_at !== null || ($device->user !== null && !$device->user->is_active);

        if ($isRevoked) {
            $isSyncPost = (str_ends_with($request->path(), 'v1/sync') || str_ends_with($request->path(), 'sync')) && $request->isMethod('POST');
            $revokedAt = $device->revoked_at ?? $device->user?->updated_at ?? now();

            // Allow one upload-only sync within 7 days for work created before revocation
            if ($isSyncPost && !$device->revoked_sync_used && $revokedAt->diffInDays(now()) <= 7) {
                $request->attributes->set('revoked_upload_only', true);
                $request->attributes->set('revocation_time_ms', (int) $revokedAt->getTimestampMs());
            } else {
                return response()->json([
                    'error' => 'Device token has been revoked.',
                ], 401);
            }
        }

        $shop = Shop::find($device->shop_id);
        if ($shop === null) {
            return response()->json([
                'error' => 'Associated shop does not exist.',
            ], 401);
        }

        // Per-device rate limiting (default: 120 requests per minute)
        $rateKey = 'device_rate:' . $device->id;
        $maxAttempts = 120;
        $decaySeconds = 60;

        if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($rateKey);
            return response()->json([
                'error' => 'Too many requests. Please slow down.',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        RateLimiter::hit($rateKey, $decaySeconds);

        // Update last_seen_at at most once per minute
        $now = now();
        if ($device->last_seen_at === null || $now->diffInSeconds($device->last_seen_at) >= 60) {
            $device->timestamps = false;
            $device->last_seen_at = $now;
            $device->save();
        }

        // Establish tenant context for the duration of the request
        CurrentShopContext::set($shop);

        $request->attributes->set('device', $device);
        $request->attributes->set('shop', $shop);
        $request->setUserResolver(fn () => $device->user);

        try {
            return $next($request);
        } finally {
            CurrentShopContext::clear();
        }
    }
}
