<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitRequestBodySize
{
    /**
     * Maximum allowed request payload size in bytes (2 MB).
     */
    protected int $maxBytes = 2 * 1024 * 1024;

    /**
     * Handle an incoming request and enforce body size restrictions.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $contentLength = $request->header('Content-Length');

        if ($contentLength !== null && (int) $contentLength > $this->maxBytes) {
            return response()->json([
                'error' => 'Payload Too Large',
                'message' => 'Request body exceeds the maximum permitted size of 2 MB.',
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return $next($request);
    }
}
