<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Shop;
use App\Models\ShopLicense;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Services\DateEpochService;
use App\Services\LicenseEnforcer;
use App\Services\LicenseVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LicenseController extends Controller
{
    public function __construct(
        protected LicenseEnforcer $licenseEnforcer
    ) {}

    /**
     * Upload and activate a shop license token.
     * Owner device only. Accept only shop-bound tokens matching this shop's UUID.
     * POST /v1/license
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        /** @var Device $device */
        $device = $request->attributes->get('device');
        $shop = Shop::find($device->shop_id);

        if ($shop === null) {
            return response()->json([
                'error' => 'Shop not found.',
            ], 404);
        }

        /** @var User|null $user */
        $user = !empty($device->user_id)
            ? User::withoutGlobalScope(ShopScope::class)->find($device->user_id)
            : null;

        if ($user?->role !== 'owner') {
            return response()->json([
                'error' => 'Only owner devices are authorized to upload license tokens.',
            ], 403);
        }

        $tokenInput = trim($validated['token']);
        $expectedBind = $shop->getBindBytes();
        $today = DateEpochService::today();

        $verification = LicenseVerifier::verify(
            tokenString: $tokenInput,
            expectedType: 2, // Must be shop-bound
            expectedBind: $expectedBind,
            today: $today
        );

        if ($verification['state'] === LicenseVerifier::STATE_WRONG_BIND) {
            return response()->json([
                'error' => 'Token is not bound to this shop.',
                'reason' => 'wrong_bind',
                'state' => $verification['state'],
                'server_time' => now()->toIso8601String(),
            ], 422);
        }

        if ($verification['state'] === LicenseVerifier::STATE_INVALID_SIGNATURE) {
            return response()->json([
                'error' => 'Invalid license token signature or checksum.',
                'reason' => 'invalid_signature',
                'state' => $verification['state'],
                'server_time' => now()->toIso8601String(),
            ], 422);
        }

        if ($verification['state'] === LicenseVerifier::STATE_UNSUPPORTED_VERSION) {
            return response()->json([
                'error' => 'Unsupported license token version.',
                'reason' => 'unsupported_version',
                'state' => $verification['state'],
                'server_time' => now()->toIso8601String(),
            ], 422);
        }

        if ($verification['state'] === LicenseVerifier::STATE_REVOKED) {
            return response()->json([
                'error' => 'This license token has been administratively revoked.',
                'reason' => 'revoked',
                'state' => $verification['state'],
                'server_time' => now()->toIso8601String(),
            ], 422);
        }

        $payload = $verification['payload'];

        $shopLicense = ShopLicense::updateOrCreate(
            ['shop_id' => $shop->id],
            [
                'token' => $tokenInput,
                'license_id' => $payload['license_id_hex'] ?? null,
                'plan' => $payload['plan'] ?? null,
                'exp_day' => $payload['exp_day'] ?? null,
                'grace_days' => $payload['grace_days'] ?? 7,
                'seats' => $payload['seats'] ?? 1,
                'features' => $payload['features'] ?? 0,
                'status' => strtolower($verification['state']),
            ]
        );

        $evaluation = $this->licenseEnforcer->evaluateShopLicense($shop);

        return response()->json([
            'success' => true,
            'message' => 'License activated successfully.',
            'license' => $evaluation,
            'server_time' => now()->toIso8601String(),
        ], 200);
    }

    /**
     * Retrieve current license status for any paired device.
     * GET /v1/license
     */
    public function show(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $shop = Shop::find($device->shop_id);

        if ($shop === null) {
            return response()->json([
                'error' => 'Shop not found.',
            ], 404);
        }

        $evaluation = $this->licenseEnforcer->evaluateShopLicense($shop);

        return response()->json($evaluation);
    }
}
