<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Change;
use App\Models\Device;
use App\Models\Movement;
use App\Models\PairingCode;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Scopes\ShopScope;
use App\Support\ImmediateTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    /**
     * Wipe all online data for the shop (movements, products, changes, devices, users, shop).
     * Strictly restricted to the shop owner.
     * DELETE /v1/shop
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        /** @var User|null $user */
        $user = $device->user;

        if ($user === null || $user->role !== 'owner') {
            return response()->json([
                'error' => 'Only the shop owner can wipe online shop data.',
            ], 403);
        }

        $shopId = (string) $device->shop_id;

        ImmediateTransaction::run(function () use ($shopId): void {
            // Delete all shop tenant entities in sequence
            Movement::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            Product::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            Change::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            PairingCode::where('shop_id', $shopId)->delete();
            Device::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            User::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->delete();
            Shop::where('id', $shopId)->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Shop and all associated online data wiped successfully.',
        ]);
    }
}
