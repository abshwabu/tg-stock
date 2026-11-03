<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        protected PermissionService $permissionService
    ) {}

    /**
     * Get profile, permissions, and locations for the authenticated user/device.
     * GET /v1/me
     */
    public function me(Request $request): JsonResponse
    {
        /** @var Device|null $device */
        $device = $request->attributes->get('device');
        $user = $device?->user;

        if ($user === null) {
            return response()->json([
                'error' => 'Device is not associated with an active user.',
            ], 404);
        }

        $permissions = $this->permissionService->getPermissions($user);
        $locationIds = $this->permissionService->getLocationIds($user);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'telegram_id' => $user->telegram_id,
                'language' => $user->language,
                'shop_id' => $user->shop_id,
                'role' => $user->role,
                'perm_version' => $user->perm_version ?? 1,
                'is_active' => (bool) $user->is_active,
            ],
            'role' => $user->role,
            'permissions' => $permissions,
            'location_ids' => $locationIds,
            'perm_version' => $user->perm_version ?? 1,
        ]);
    }
}
