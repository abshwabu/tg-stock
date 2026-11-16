<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiNonce;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HmacService
{
    public const WINDOW_SECONDS = 300; // 5 minutes

    /**
     * Compute HMAC signature for request data.
     */
    public static function computeSignature(
        string $secret,
        int|string $timestamp,
        string $nonce,
        string $method,
        string $uri,
        string $body = ''
    ): string {
        $canonicalBody = in_array(strtoupper($method), ['GET', 'HEAD', 'DELETE'], true) ? '' : $body;

        $canonical = (string)$timestamp . "\n"
                   . $nonce . "\n"
                   . strtoupper($method) . "\n"
                   . $uri . "\n"
                   . $canonicalBody;

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Verify incoming request HMAC signature and replay protection.
     */
    public static function verifyRequest(Request $request, ?string $secret = null): array
    {
        $secret = $secret ?? config('license.hmac_secret', 'tgstock-console-shared-api-hmac-secret-v1');

        $timestamp = $request->header('X-TGStock-Timestamp');
        $nonce = $request->header('X-TGStock-Nonce');
        $signature = $request->header('X-TGStock-Signature');

        if (!$timestamp || !$nonce || !$signature) {
            return [
                'valid' => false,
                'error' => 'Missing required HMAC security headers (X-TGStock-Timestamp, X-TGStock-Nonce, X-TGStock-Signature)',
                'code' => 401,
            ];
        }

        // 1. Timestamp drift window check (<= 300 seconds)
        $now = time();
        $ts = (int)$timestamp;
        if (abs($now - $ts) > self::WINDOW_SECONDS) {
            return [
                'valid' => false,
                'error' => "Request timestamp drift too large (allowed +-300s, received drift " . abs($now - $ts) . "s)",
                'code' => 401,
            ];
        }

        // 2. Replay attack protection via Nonce
        if (ApiNonce::where('nonce', $nonce)->exists()) {
            AuditLog::log('replay_attack_blocked', 'ApiNonce', $nonce, [
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
            ]);
            return [
                'valid' => false,
                'error' => 'Replay attack detected: nonce has already been processed.',
                'code' => 401,
            ];
        }

        // 3. Signature verification
        $uri = '/' . ltrim($request->path(), '/');
        $expectedSignature = self::computeSignature(
            $secret,
            $timestamp,
            $nonce,
            $request->method(),
            $uri,
            $request->getContent()
        );

        if (!hash_equals($expectedSignature, $signature)) {
            return [
                'valid' => false,
                'error' => 'Invalid HMAC signature.',
                'code' => 401,
            ];
        }

        // Store nonce and prune older nonces
        try {
            ApiNonce::create([
                'nonce' => $nonce,
                'created_at' => now(),
            ]);
            ApiNonce::where('created_at', '<', now()->subMinutes(10))->delete();
        } catch (\Throwable $e) {
            // Ignore duplicate insert race condition
        }

        return ['valid' => true];
    }

    /**
     * Dispatch outgoing webhook to shop server.
     */
    public static function dispatchWebhook(string $url, string $event, array $payload, ?string $secret = null): bool
    {
        $secret = $secret ?? config('license.webhook_secret', config('license.hmac_secret'));
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $data = [
            'event' => $event,
            'timestamp' => $timestamp,
            'payload' => $payload,
        ];
        $body = json_encode($data, JSON_UNESCAPED_SLASHES);
        $parsedUrl = parse_url($url);
        $uri = $parsedUrl['path'] ?? '/api/console/license-events';

        $signature = self::computeSignature($secret, $timestamp, $nonce, 'POST', $uri, $body);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-TGStock-Timestamp' => (string)$timestamp,
                'X-TGStock-Nonce' => $nonce,
                'X-TGStock-Signature' => $signature,
            ])->timeout(5)->post($url, $data);

            Log::info("Dispatched license webhook '{$event}' to {$url}, response: " . $response->status());
            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning("Failed to dispatch license webhook to {$url}: " . $e->getMessage());
            return false;
        }
    }
}
