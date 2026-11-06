<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AppVersionController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\PairingController;
use App\Http\Controllers\Api\RejectedChangeController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Stateless endpoints for device pairing, sync, device management, and Telegram webhook.
|
*/

// System Health & Uptime endpoint
Route::get('/health', [HealthController::class, 'check']);

// Telegram Webhook endpoint (stateless, verified via secret token header)
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);

Route::prefix('v1')->group(function (): void {
    // Mobile App Version & Update check endpoint (unauthenticated)
    Route::get('/app/version', [AppVersionController::class, 'show']);

    // Webhook alias
    Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
    // 1. Device pairing endpoints (unauthenticated, short-polling)
    Route::post('/pair/start', [PairingController::class, 'start']);
    Route::get('/pair/status', [PairingController::class, 'status']);
    Route::post('/pair/confirm', [PairingController::class, 'confirm']);

    // 2. Authenticated device endpoints
    Route::middleware('auth.device')->group(function (): void {
        Route::get('/me', [UserController::class, 'me']);
        Route::post('/sync', [SyncController::class, 'sync']);
        Route::get('/devices', [DeviceController::class, 'index']);
        Route::post('/devices/{id}/revoke', [DeviceController::class, 'revoke']);
        Route::delete('/devices/{id}', [DeviceController::class, 'revoke']);
        Route::delete('/shop', [ShopController::class, 'destroy']);
        Route::get('/rejected-changes', [RejectedChangeController::class, 'index']);
        Route::post('/rejected-changes/{id}/approve', [RejectedChangeController::class, 'approve']);
        Route::post('/rejected-changes/{id}/dismiss', [RejectedChangeController::class, 'dismiss']);
    });
});
