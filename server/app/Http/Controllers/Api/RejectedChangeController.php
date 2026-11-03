<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\RejectedChange;
use App\Models\Shop;
use App\Scopes\ShopScope;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RejectedChangeController extends Controller
{
    public function __construct(
        protected PermissionService $permissionService
    ) {}

    /**
     * List rejected changes pending review for this shop.
     * GET /v1/rejected-changes
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $user = $device->user;

        if ($user === null || ($user->role !== 'owner' && !$this->permissionService->can($user, 'team.manage'))) {
            return response()->json(['error' => 'Forbidden. Only shop owners can review rejected changes.'], 403);
        }

        $status = $request->query('status', 'pending');
        $query = RejectedChange::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $device->shop_id);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $records = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'rejected_changes' => $records,
        ]);
    }

    /**
     * Approve a rejected change and apply it as the original user.
     * POST /v1/rejected-changes/{id}/approve
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $user = $device->user;

        if ($user === null || ($user->role !== 'owner' && !$this->permissionService->can($user, 'team.manage'))) {
            return response()->json(['error' => 'Forbidden. Only shop owners can review rejected changes.'], 403);
        }

        /** @var RejectedChange|null $rejectedChange */
        $rejectedChange = RejectedChange::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $device->shop_id)
            ->where('id', $id)
            ->first();

        if ($rejectedChange === null) {
            return response()->json(['error' => 'Rejected change not found.'], 404);
        }

        if ($rejectedChange->status !== 'pending') {
            return response()->json(['error' => "Change is already {$rejectedChange->status}."], 400);
        }

        $applied = $this->permissionService->approveRejectedChange($rejectedChange, $user);

        return response()->json([
            'success' => $applied,
            'message' => 'Change approved and applied successfully as the original user.',
        ]);
    }

    /**
     * Dismiss a rejected change.
     * POST /v1/rejected-changes/{id}/dismiss
     */
    public function dismiss(Request $request, string $id): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $user = $device->user;

        if ($user === null || ($user->role !== 'owner' && !$this->permissionService->can($user, 'team.manage'))) {
            return response()->json(['error' => 'Forbidden. Only shop owners can review rejected changes.'], 403);
        }

        /** @var RejectedChange|null $rejectedChange */
        $rejectedChange = RejectedChange::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $device->shop_id)
            ->where('id', $id)
            ->first();

        if ($rejectedChange === null) {
            return response()->json(['error' => 'Rejected change not found.'], 404);
        }

        if ($rejectedChange->status !== 'pending') {
            return response()->json(['error' => "Change is already {$rejectedChange->status}."], 400);
        }

        $dismissed = $this->permissionService->dismissRejectedChange($rejectedChange, $user);

        return response()->json([
            'success' => $dismissed,
            'message' => 'Change dismissed.',
        ]);
    }
}
