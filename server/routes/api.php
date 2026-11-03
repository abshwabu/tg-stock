<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\PairingController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Stateless endpoints for device pairing, sync, device management, and Telegram webhook.
|
*/

// Telegram Webhook endpoint (stateless, verified via secret token header)
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);

Route::prefix('v1')->group(function (): void {
    // Webhook alias
    Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
    // 1. Device pairing endpoints (unauthenticated, short-polling)
    Route::post('/pair/start', [PairingController::class, 'start']);
    Route::get('/pair/status', [PairingController::class, 'status']);
    Route::post('/pair/confirm', [PairingController::class, 'confirm']);

    // 2. Authenticated device endpoints
    Route::middleware('auth.device')->group(function (): void {
        Route::post('/sync', [SyncController::class, 'sync']);
        Route::get('/devices', [DeviceController::class, 'index']);
        Route::post('/devices/{id}/revoke', [DeviceController::class, 'revoke']);
        Route::delete('/devices/{id}', [DeviceController::class, 'revoke']);
        Route::delete('/shop', [ShopController::class, 'destroy']);
    });
});
