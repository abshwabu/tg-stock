<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Mobile App Release Configuration
    |--------------------------------------------------------------------------
    |
    | Defines the latest available release version, minimum supported version
    | for online sync, direct download landing page URL, and bilingual release notes.
    |
    */

    'latest' => env('APP_RELEASE_LATEST', '1.0.0'),
    'latest_code' => (int) env('APP_RELEASE_LATEST_CODE', 1),
    'min_supported' => env('APP_RELEASE_MIN_SUPPORTED', '1.0.0'),
    'min_supported_code' => (int) env('APP_RELEASE_MIN_SUPPORTED_CODE', 1),
    'download_url' => env('APP_RELEASE_DOWNLOAD_URL', 'https://stock.yenesuq.com/app/'),
    'notes_en' => env('APP_RELEASE_NOTES_EN', 'New features, performance enhancements, and bug fixes for Ethiopian shopkeepers.'),
    'notes_am' => env('APP_RELEASE_NOTES_AM', 'አዳዲስ ገጽታዎች፣ ፈጣን አሰራር እና ለኢትዮጵያ ሱቆች የተዘጋጁ ማስተካከያዎች።'),
];
