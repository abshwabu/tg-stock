<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        // Check 2FA requirement
        if ($user->hasTwoFactorEnabled() && !session('2fa_verified')) {
            return redirect()->route('2fa.challenge');
        }

        return $next($request);
    }
}
