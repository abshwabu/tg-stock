<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ApiController;
use Illuminate\Support\Facades\Route;

// API v1 protected by HMAC signature, timestamp window, and replay protection
Route::prefix('v1')->middleware('api.hmac')->group(function () {
    Route::post('/payment-requests', [ApiController::class, 'submitPaymentRequest']);
    Route::get('/licenses/by-code/{code}', [ApiController::class, 'getLicenseByCode']);
    Route::post('/trials', [ApiController::class, 'claimTrial']);
    Route::get('/revocations', [ApiController::class, 'getRevocations']);
});

// Ping / status
Route::get('/ping', function () {
    return response()->json(['status' => 'ok', 'app' => 'TGStock License Console']);
});
