<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckIpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowlistRaw = Setting::get('ip_allowlist');
        if (empty($allowlistRaw)) {
            return $next($request);
        }

        $allowedIps = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $allowlistRaw)));
        if (empty($allowedIps)) {
            return $next($request);
        }

        $clientIp = $request->ip();

        if (!in_array($clientIp, $allowedIps, true)) {
            AuditLog::log('ip_allowlist_blocked', 'Security', $clientIp, [
                'client_ip' => $clientIp,
                'uri' => $request->getRequestUri(),
            ]);

            abort(403, "Access Forbidden: IP address '{$clientIp}' is not authorized.");
        }

        return $next($request);
    }
}
