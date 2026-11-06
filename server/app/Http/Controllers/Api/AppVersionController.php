<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AppVersionController extends Controller
{
    /**
     * Return latest release metadata and minimum supported version.
     */
    public function show(): JsonResponse
    {
        $config = config('app_release');

        return response()->json([
            'latest' => (string) ($config['latest'] ?? '1.0.0'),
            'latest_code' => (int) ($config['latest_code'] ?? 1),
            'min_supported' => (string) ($config['min_supported'] ?? '1.0.0'),
            'min_supported_code' => (int) ($config['min_supported_code'] ?? 1),
            'download_url' => (string) ($config['download_url'] ?? 'https://stock.yenesuq.com/app/'),
            'notes_en' => (string) ($config['notes_en'] ?? ''),
            'notes_am' => (string) ($config['notes_am'] ?? ''),
        ]);
    }
}
