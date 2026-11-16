<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted Ed25519 Public Keys for Offline Licensing
    |--------------------------------------------------------------------------
    |
    | Public keys bundled with the shop server and client applications.
    | Keyed by 1-byte Key ID (kid: 1..255).
    |
    */
    'keys' => [
        1 => [
            'kid' => 1,
            'description' => 'Primary production key (kid = 1)',
            'public_key_hex' => env('LICENSE_KEY_1_PUBLIC_HEX', '0110750deee76ecbe8e5b980061e61e4271929e289a7d3ccf9ce1ff6d9a90f58'),
        ],
        2 => [
            'kid' => 2,
            'description' => 'Secondary rotation key (kid = 2)',
            'public_key_hex' => env('LICENSE_KEY_2_PUBLIC_HEX', '731f9b900ed6c14d00e265f590cbc89f8f5a88baae5d56173ede17b312cd1871'),
        ],
    ],
];
