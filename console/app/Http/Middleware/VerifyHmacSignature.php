<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\HmacService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyHmacSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $verification = HmacService::verifyRequest($request);

        if (!$verification['valid']) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => $verification['error'],
            ], $verification['code'] ?? 401);
        }

        return $next($request);
    }
}
