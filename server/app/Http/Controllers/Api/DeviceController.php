<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Scopes\ShopScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /**
     * List all devices in the current shop.
     * GET /v1/devices
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Device $currentDevice */
        $currentDevice = $request->attributes->get('device');
        $shopId = (string) $currentDevice->shop_id;

        $devices = Device::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (Device $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'revoked_at' => $d->revoked_at?->toIso8601String(),
                'is_revoked' => $d->revoked_at !== null,
                'is_current' => $d->id === $currentDevice->id,
                'created_at' => $d->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'devices' => $devices,
        ]);
    }

    /**
     * Revoke a device's access immediately.
     * POST /v1/devices/{id}/revoke or DELETE /v1/devices/{id}
     */
    public function revoke(Request $request, string $id): JsonResponse
    {
        /** @var Device $currentDevice */
        $currentDevice = $request->attributes->get('device');
        $shopId = (string) $currentDevice->shop_id;

        /** @var Device|null $targetDevice */
        $targetDevice = Device::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('id', $id)
            ->first();

        if ($targetDevice === null) {
            return response()->json([
                'error' => 'Device not found in this shop.',
            ], 404);
        }

        if ($targetDevice->revoked_at === null) {
            $targetDevice->revoked_at = now();
            $targetDevice->save();
        }

        return response()->json([
            'success' => true,
            'message' => "Device [{$targetDevice->name}] access has been revoked.",
            'revoked_at' => $targetDevice->revoked_at->toIso8601String(),
        ]);
    }
}
