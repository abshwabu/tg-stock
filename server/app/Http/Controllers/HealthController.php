<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class HealthController extends Controller
{
    /**
     * Uptime & Health Check endpoint (/health).
     * Returns 200 OK only if SQLite database is writable and cron heartbeat is < 15 minutes old.
     * Dispatches Telegram alert to administrator if system health fails.
     */
    public function check(Request $request, TelegramClient $telegram): JsonResponse
    {
        $dbWritable = false;
        $dbError = null;

        // 1. Verify SQLite read and write capability
        try {
            DB::select('SELECT 1');

            // Quick atomic write test to verify journal & filesystem writability
            DB::table('cache')->updateOrInsert(
                ['key' => 'health:write_check'],
                ['value' => (string) now()->timestamp, 'expiration' => now()->addMinutes(5)->timestamp]
            );

            $dbWritable = true;
        } catch (Throwable $e) {
            $dbError = $e->getMessage();
            Log::error('Health check database failure: ' . $dbError);
        }

        // 2. Verify Cron heartbeat freshness (< 15 minutes = 900 seconds)
        $heartbeatTs = Cache::get('cron:heartbeat');
        $cronSecondsAgo = $heartbeatTs !== null ? (now()->timestamp - (int) $heartbeatTs) : null;
        $cronFresh = $cronSecondsAgo !== null && $cronSecondsAgo < 900;

        $isHealthy = $dbWritable && $cronFresh;

        $payload = [
            'status' => $isHealthy ? 'ok' : 'unhealthy',
            'database' => $dbWritable ? 'writable' : 'failed',
            'cron' => $cronFresh ? 'fresh' : 'stale',
            'cron_last_heartbeat_seconds_ago' => $cronSecondsAgo,
            'checked_at' => now()->toIso8601String(),
        ];

        if ($dbError !== null) {
            $payload['database_error'] = $dbError;
        }

        if (!$isHealthy) {
            // Debounce Telegram alert: at most once every 30 minutes
            $alertKey = 'health:last_alert_sent';
            if (!Cache::has($alertKey)) {
                Cache::put($alertKey, now()->timestamp, now()->addMinutes(30));
                $this->alertAdmin($telegram, $payload);
            }

            return response()->json($payload, 503);
        }

        return response()->json($payload, 200);
    }

    /**
     * Dispatches Telegram message to private admin chat if configured.
     */
    protected function alertAdmin(TelegramClient $telegram, array $payload): void
    {
        $adminChatId = config('services.telegram.admin_chat_id');
        if (empty($adminChatId)) {
            return;
        }

        $msg = "🚨 *SYSTEM HEALTH ALERT*\n\n"
            . "• *Status:* UNHEALTHY\n"
            . "• *Database:* " . ($payload['database'] ?? 'unknown') . "\n"
            . "• *Cron:* " . ($payload['cron'] ?? 'unknown')
            . ($payload['cron_last_heartbeat_seconds_ago'] !== null ? " (" . $payload['cron_last_heartbeat_seconds_ago'] . "s ago)" : " (No heartbeat)") . "\n"
            . "• *Time:* " . ($payload['checked_at'] ?? now()->toIso8601String()) . "\n\n"
            . "Please verify server processes and cron execution on your shared host.";

        try {
            $telegram->sendMessage((int) $adminChatId, $msg, ['parse_mode' => 'Markdown']);
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch health alert to admin Telegram: ' . $e->getMessage());
        }
    }
}
