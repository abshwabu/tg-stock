<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user || !$user->isSuperAdmin()) {
            AuditLog::log('unauthorized_super_admin_attempt', 'AccessControl', null, [
                'user_id' => $user?->id,
                'role' => $user?->role,
                'uri' => $request->getRequestUri(),
            ], $request, $user);

            abort(403, 'Unauthorized. This action requires Super Admin privileges.');
        }

        return $next($request);
    }
}
