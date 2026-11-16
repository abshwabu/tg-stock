<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Shop;
use App\Services\LicenseEnforcer;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SyncController extends Controller
{
    public function __construct(
        protected SyncService $syncService,
        protected LicenseEnforcer $licenseEnforcer
    ) {}

    /**
     * Process multi-device sync push/pull batch.
     * POST /v1/sync
     */
    public function sync(Request $request): JsonResponse
    {
        $rawContent = $request->getContent();
        $rawLength = strlen($rawContent);

        if ($rawLength > SyncService::MAX_BODY_BYTES) {
            return response()->json([
                'error' => 'Payload exceeds maximum limit of 256 KB.',
            ], 413);
        }

        $payload = json_decode($rawContent, true);

        if (!is_array($payload)) {
            return response()->json([
                'error' => 'Invalid JSON payload.',
            ], 400);
        }

        /** @var Device $device */
        $device = $request->attributes->get('device');
        $shop = Shop::find($device->shop_id);

        // License enforcement for sync pushes (pulls remain allowed)
        if ($shop !== null && !empty($payload['changes'])) {
            if (!$this->licenseEnforcer->hasFeature($shop, 'online_sync')) {
                return response()->json([
                    'error' => 'feature_not_licensed',
                    'feature' => 'online_sync',
                    'message' => 'Online sync feature is not included in current license plan.',
                    'server_time' => now()->toIso8601String(),
                ], 403);
            }

            if (!$this->licenseEnforcer->canWrite($shop)) {
                return response()->json([
                    'error' => 'license_expired',
                    'message' => 'Shop license has expired. The shop is in read-only mode.',
                    'server_time' => now()->toIso8601String(),
                ], 403);
            }
        }

        try {
            $result = $this->syncService->sync($device, $payload, $rawLength);
            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
