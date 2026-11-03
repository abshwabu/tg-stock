<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
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
        then: function (): void {
            Route::middleware('api')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global security headers on all responses
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Body size protection against oversized payloads on shared hosting
        $middleware->append(\App\Http\Middleware\LimitRequestBodySize::class);

        $middleware->alias([
            'auth.device' => \App\Http\Middleware\DeviceTokenAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('v1/*') || $request->expectsJson(),
        );

        // SQLite write contention / busy locked handler: return 503 with Retry-After header
        $exceptions->render(function (QueryException $e, Request $request) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'database is locked') || str_contains($msg, 'busy') || str_contains($msg, 'sqlite_busy')) {
                return response()->json([
                    'error' => 'Database Busy',
                    'message' => 'SQLite write lock contention encountered. Please retry shortly.',
                    'retry_after' => 2,
                ], 503, [
                    'Retry-After' => '2',
                ]);
            }
            return null;
        });

        $exceptions->render(function (PDOException $e, Request $request) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'database is locked') || str_contains($msg, 'busy') || str_contains($msg, 'sqlite_busy')) {
                return response()->json([
                    'error' => 'Database Busy',
                    'message' => 'SQLite write lock contention encountered. Please retry shortly.',
                    'retry_after' => 2,
                ], 503, [
                    'Retry-After' => '2',
                ]);
            }
            return null;
        });
    })->create();
