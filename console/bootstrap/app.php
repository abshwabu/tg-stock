<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global security headers on all web and API responses (Strict CSP, no third-party scripts)
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Optional IP allowlist checking for admin panel
        $middleware->alias([
            'admin.auth' => \App\Http\Middleware\AdminAuthenticate::class,
            'role.super_admin' => \App\Http\Middleware\RequireSuperAdmin::class,
            'ip.allowlist' => \App\Http\Middleware\CheckIpAllowlist::class,
            'api.hmac' => \App\Http\Middleware\VerifyHmacSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
