<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request and attach hardened security headers.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent clickjacking / frame embedding
        $response->headers->set('X-Frame-Options', 'DENY');

        // Referrer policy
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Restrict browser features and sensors
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');

        // Content Security Policy (strict defaults for API & administration)
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'self';"
        );

        // Cross-site scripting filter protection
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        return $response;
    }
}
