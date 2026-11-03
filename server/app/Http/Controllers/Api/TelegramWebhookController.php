<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotUpdate;
use App\Services\Telegram\TelegramRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramRouter $router
    ) {}

    /**
     * Handle incoming Telegram webhook updates.
     * Enforces secret token verification, update_id deduplication,
     * and immediate 200 responses.
     */
    public function handle(Request $request): JsonResponse
    {
        // 1. Verify X-Telegram-Bot-Api-Secret-Token
        $expectedSecret = (string) config('services.telegram.webhook_secret', '');
        $providedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($expectedSecret !== '') {
            if (empty($providedSecret) || !hash_equals($expectedSecret, $providedSecret)) {
                Log::warning('Telegram webhook rejected due to invalid or missing secret token.');
                return response()->json(['error' => 'Invalid secret token.'], 403);
            }
        }

        // 2. Validate and dedupe update_id
        $updateId = $request->input('update_id');
        if (!empty($updateId) && is_numeric($updateId)) {
            $intUpdateId = (int) $updateId;

            // Check if already processed
            $exists = BotUpdate::where('update_id', $intUpdateId)->exists();
            if ($exists) {
                return response()->json(['ok' => true, 'status' => 'duplicate']);
            }

            try {
                BotUpdate::create(['update_id' => $intUpdateId]);
            } catch (\Throwable $e) {
                // Caught SQLite duplicate key constraint
                if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'unique')) {
                    return response()->json(['ok' => true, 'status' => 'duplicate']);
                }
                Log::error('Failed to insert Telegram update_id: ' . $e->getMessage());
            }
        }

        // 3. Dispatch to Telegram Router
        try {
            $this->router->dispatch($request->all());
        } catch (\Throwable $e) {
            Log::error('Telegram webhook handling exception: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
