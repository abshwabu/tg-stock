<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PairingController extends Controller
{
    public function __construct(
        protected PairingService $pairingService
    ) {}

    /**
     * Start a new pairing session.
     * POST /v1/pair/start
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shop_name' => ['nullable', 'string', 'max:100'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $result = $this->pairingService->startPairing(
            $validated['shop_name'] ?? null,
            $validated['device_name']
        );

        return response()->json($result, 201);
    }

    /**
     * Check status of a pairing code (short polling).
     * GET /v1/pair/status?code=123456
     */
    public function status(Request $request): JsonResponse
    {
        $code = $request->query('code');

        if (empty($code) || !is_string($code)) {
            return response()->json([
                'error' => 'Missing code parameter.',
            ], 400);
        }

        $result = $this->pairingService->getPairingStatus($code);

        return response()->json($result);
    }

    /**
     * Confirm a pairing code (invoked by Telegram bot webhook or bot module).
     * POST /v1/pair/confirm
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'telegram_id' => ['required', 'integer'],
            'telegram_name' => ['required', 'string', 'max:100'],
            'language' => ['nullable', 'string', 'max:10'],
        ]);

        try {
            $result = $this->pairingService->confirmPairing(
                $validated['code'],
                $validated['telegram_id'],
                $validated['telegram_name'],
                $validated['language'] ?? 'am'
            );

            return response()->json([
                'success' => true,
                'shop_id' => $result['shop']->id,
                'shop_name' => $result['shop']->name,
                'device_id' => $result['device']->id,
                'device_name' => $result['device']->name,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
