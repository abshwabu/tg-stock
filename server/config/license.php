<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | License Console Integration Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for interacting with the standalone super-admin License Console.
    |
    */
    'console_url' => env('LICENSE_CONSOLE_URL', 'https://console.yenesuq.com'),

    'hmac_secret' => env('LICENSE_HMAC_SECRET', 'tgstock-console-shared-api-hmac-secret-v1'),

    'webhook_secret' => env('LICENSE_WEBHOOK_SECRET', env('LICENSE_HMAC_SECRET', 'tgstock-console-shared-api-hmac-secret-v1')),

    /*
    | When false in testing, requests for shops without a license are allowed
    | so that existing test suites continue passing without mock licenses.
    */
    'enforce_unlicensed' => env('LICENSE_ENFORCE_UNLICENSED', false),

    /*
    | Default plans list for user presentation in the Telegram bot
    */
    'plans' => [
        'monthly' => [
            'id' => 'monthly',
            'name_en' => 'Monthly Plan (30 Days)',
            'name_am' => 'የወር ፈቃድ (30 ቀናት)',
            'price_etb' => 500,
            'duration_days' => 30,
            'seats' => 3,
            'features' => 0x001F,
        ],
        'yearly' => [
            'id' => 'yearly',
            'name_en' => 'Yearly Plan (365 Days)',
            'name_am' => 'የዓመት ፈቃድ (365 ቀናት)',
            'price_etb' => 5000,
            'duration_days' => 365,
            'seats' => 5,
            'features' => 0x001F,
        ],
        'lifetime' => [
            'id' => 'lifetime',
            'name_en' => 'Lifetime License',
            'name_am' => 'የዕድሜ ልክ ፈቃድ',
            'price_etb' => 15000,
            'duration_days' => 0,
            'seats' => 10,
            'features' => 0x001F,
        ],
    ],

    /*
    | Owner-configurable payment instructions shown to Telegram bot users
    */
    'payment_instructions' => [
        'telebirr' => env('PAYMENT_TELEBIRR_NUMBER', '0911234567'),
        'cbe' => env('PAYMENT_CBE_ACCOUNT', '1000123456789'),
        'account_name' => env('PAYMENT_ACCOUNT_NAME', 'TGStock Technologies PLC'),
    ],
];
