<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the RouteServiceProvider or bootstrap/app.php
| within a group which is assigned the "api" middleware group.
| All routes in this file are completely stateless (no session cookies).
|
*/

Route::get('/ping', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => (int) (microtime(true) * 1000),
    ]);
});
