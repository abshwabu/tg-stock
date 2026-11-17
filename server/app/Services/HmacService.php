<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiNonce;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HmacService
{
    public const WINDOW_SECONDS = 300; // 5 minutes

    /**
     * Compute canonical HMAC-SHA256 signature.
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
     *
     * @return array{valid: bool, error?: string}
     */
    public static function verifyRequest(Request $request, ?string $secret = null): array
    {
        $secret = $secret ?? config('license.webhook_secret', config('license.hmac_secret'));

        $timestamp = $request->header('X-TGStock-Timestamp');
        $nonce = $request->header('X-TGStock-Nonce');
        $signature = $request->header('X-TGStock-Signature');

        if (!$timestamp || !$nonce || !$signature) {
            return ['valid' => false, 'error' => 'Missing required HMAC security headers.'];
        }

        // 1. Timestamp drift window check (<= 300 seconds)
        $reqTime = (int)$timestamp;
        $now = time();
        if (abs($now - $reqTime) > self::WINDOW_SECONDS) {
            return ['valid' => false, 'error' => 'Request timestamp outside allowed 5-minute drift window.'];
        }

        // 2. Nonce replay protection
        if (ApiNonce::where('nonce', $nonce)->exists()) {
            return ['valid' => false, 'error' => 'Nonce replay detected.'];
        }

        // 3. Compute and compare signature
        $method = $request->method();
        $uri = '/' . ltrim($request->path(), '/');
        $body = $request->getContent();

        $expectedSig = self::computeSignature($secret, $timestamp, $nonce, $method, $uri, $body);

        if (!hash_equals($expectedSig, $signature)) {
            // Also try with /api prefix or without /api prefix in case of path rewriting
            $altUri = str_starts_with($uri, '/api/') ? substr($uri, 4) : '/api' . $uri;
            $altSig = self::computeSignature($secret, $timestamp, $nonce, $method, $altUri, $body);
            if (!hash_equals($altSig, $signature)) {
                return ['valid' => false, 'error' => 'Invalid HMAC signature.'];
            }
        }

        // Store nonce and prune nonces older than 10 minutes
        try {
            ApiNonce::create([
                'nonce' => $nonce,
                'created_at' => now(),
            ]);
            ApiNonce::where('created_at', '<', now()->subMinutes(10))->delete();
        } catch (\Throwable $e) {
            // Duplicate insert race condition
            return ['valid' => false, 'error' => 'Nonce replay detected.'];
        }

        return ['valid' => true];
    }

    /**
     * Send authenticated JSON request to the License Console.
     */
    public static function sendToConsole(string $method, string $path, array $data = []): array
    {
        $consoleUrl = rtrim(config('license.console_url', 'http://127.0.0.1:8000'), '/');
        $url = $consoleUrl . '/' . ltrim($path, '/');
        $secret = config('license.hmac_secret', 'tgstock-console-shared-api-hmac-secret-v1');

        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $body = in_array(strtoupper($method), ['GET', 'HEAD', 'DELETE'], true) ? '' : json_encode($data, JSON_UNESCAPED_SLASHES);

        $parsed = parse_url($url);
        $uri = $parsed['path'] ?? '/' . ltrim($path, '/');

        $signature = self::computeSignature($secret, $timestamp, $nonce, $method, $uri, $body);

        try {
            $req = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-TGStock-Timestamp' => (string) $timestamp,
                'X-TGStock-Nonce' => $nonce,
                'X-TGStock-Signature' => $signature,
            ])->connectTimeout(4)->timeout(6);

            if (strtoupper($method) === 'GET') {
                $response = $req->get($url, $data);
            } else {
                $response = $req->post($url, $data);
            }

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::warning("Failed to connect to License Console at {$url}: " . $e->getMessage());
            return [
                'success' => false,
                'status' => 500,
                'error' => $e->getMessage(),
            ];
        }
    }
}
