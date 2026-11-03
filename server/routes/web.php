<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Allow direct /v1/ routes alongside /api/v1/ routes
require __DIR__ . '/api.php';
