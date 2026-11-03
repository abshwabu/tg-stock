<?php

declare(strict_types=1);

/**
 * Payment Provider Catalog for Ethiopian Banks and Mobile Wallets.
 *
 * NOTE: Amharic names marked with human verification note; verify before production release.
 */
return [
    'verification_status' => 'verify before release',

    'banks' => [
        'bank.cbe' => [
            'name_en' => 'Commercial Bank of Ethiopia',
            'name_am' => 'የኢትዮጵያ ንግድ ባንክ',
            'slug' => 'cbe',
        ],
        'bank.awash' => [
            'name_en' => 'Awash Bank',
            'name_am' => 'አዋሽ ባንክ',
            'slug' => 'awash',
        ],
        'bank.dashen' => [
            'name_en' => 'Dashen Bank',
            'name_am' => 'ዳሸን ባንክ',
            'slug' => 'dashen',
        ],
        'bank.abyssinia' => [
            'name_en' => 'Bank of Abyssinia',
            'name_am' => 'አቢሲኒያ ባንክ',
            'slug' => 'abyssinia',
        ],
        'bank.wegagen' => [
            'name_en' => 'Wegagen Bank',
            'name_am' => 'ወጋገን ባንክ',
            'slug' => 'wegagen',
        ],
        'bank.hibret' => [
            'name_en' => 'Hibret Bank',
            'name_am' => 'ኅብረት ባንክ',
            'slug' => 'hibret',
        ],
        'bank.nib' => [
            'name_en' => 'Nib International Bank',
            'name_am' => 'ንብ ኢንተርናሽናል ባንክ',
            'slug' => 'nib',
        ],
        'bank.coop' => [
            'name_en' => 'Cooperative Bank of Oromia',
            'name_am' => 'የኦሮሚያ ኅብረት ሥራ ባንክ',
            'slug' => 'coop',
        ],
        'bank.oromia' => [
            'name_en' => 'Oromia Bank',
            'name_am' => 'ኦሮሚያ ባንክ',
            'slug' => 'oromia',
        ],
        'bank.lion' => [
            'name_en' => 'Lion International Bank',
            'name_am' => 'አንበሳ ኢንተርናሽናል ባንክ',
            'slug' => 'lion',
        ],
        'bank.zemen' => [
            'name_en' => 'Zemen Bank',
            'name_am' => 'ዘመን ባንክ',
            'slug' => 'zemen',
        ],
        'bank.bunna' => [
            'name_en' => 'Bunna International Bank',
            'name_am' => 'ቡና ኢንተርናሽናል ባንክ',
            'slug' => 'bunna',
        ],
        'bank.berhan' => [
            'name_en' => 'Berhan Bank',
            'name_am' => 'ብርሃን ባንክ',
            'slug' => 'berhan',
        ],
        'bank.abay' => [
            'name_en' => 'Abay Bank',
            'name_am' => 'ዓባይ ባንክ',
            'slug' => 'abay',
        ],
        'bank.enat' => [
            'name_en' => 'Enat Bank',
            'name_am' => 'እናት ባንክ',
            'slug' => 'enat',
        ],
        'bank.amhara' => [
            'name_en' => 'Amhara Bank',
            'name_am' => 'አማራ ባንክ',
            'slug' => 'amhara',
        ],
        'bank.ahadu' => [
            'name_en' => 'Ahadu Bank',
            'name_am' => 'አሃዱ ባንክ',
            'slug' => 'ahadu',
        ],
        'bank.siinqee' => [
            'name_en' => 'Siinqee Bank',
            'name_am' => 'ሲንቄ ባንክ',
            'slug' => 'siinqee',
        ],
        'bank.tsehay' => [
            'name_en' => 'Tsehay Bank',
            'name_am' => 'ፀሐይ ባንክ',
            'slug' => 'tsehay',
        ],
        'bank.global' => [
            'name_en' => 'Global Bank Ethiopia',
            'name_am' => 'ግሎባል ባንክ ኢትዮጵያ',
            'slug' => 'global',
        ],
        'bank.gadaa' => [
            'name_en' => 'Gadaa Bank',
            'name_am' => 'ገዳ ባንክ',
            'slug' => 'gadaa',
        ],
        'bank.hijra' => [
            'name_en' => 'Hijra Bank',
            'name_am' => 'ሒጅራ ባንክ',
            'slug' => 'hijra',
        ],
        'bank.zamzam' => [
            'name_en' => 'ZamZam Bank',
            'name_am' => 'ዘምዘም ባንክ',
            'slug' => 'zamzam',
        ],
        'bank.addis' => [
            'name_en' => 'Addis International Bank',
            'name_am' => 'አዲስ ኢንተርናሽናል ባንክ',
            'slug' => 'addis',
        ],
        'bank.debub_global' => [
            'name_en' => 'Debub Global Bank',
            'name_am' => 'ደቡብ ግሎባል ባንክ',
            'slug' => 'debub_global',
        ],
        'bank.shabelle' => [
            'name_en' => 'Shabelle Bank',
            'name_am' => 'ሻበሌ ባንክ',
            'slug' => 'shabelle',
        ],
    ],

    'wallets' => [
        'wallet.telebirr' => [
            'name_en' => 'Telebirr',
            'name_am' => 'ቴሌብር',
            'slug' => 'telebirr',
        ],
        'wallet.mpesa' => [
            'name_en' => 'M-Pesa',
            'name_am' => 'ኤም-ፔሳ',
            'slug' => 'mpesa',
        ],
        'wallet.cbe_birr' => [
            'name_en' => 'CBE Birr',
            'name_am' => 'ሲቢኢ ብር',
            'slug' => 'cbe_birr',
        ],
        'wallet.hellocash' => [
            'name_en' => 'HelloCash',
            'name_am' => 'ሄሎካሽ',
            'slug' => 'hellocash',
        ],
        'wallet.amole' => [
            'name_en' => 'Amole',
            'name_am' => 'አሞሌ',
            'slug' => 'amole',
        ],
        'wallet.kacha' => [
            'name_en' => 'Kacha',
            'name_am' => 'ካቻ',
            'slug' => 'kacha',
        ],
        'wallet.awash_birr' => [
            'name_en' => 'Awash Birr',
            'name_am' => 'አዋሽ ብር',
            'slug' => 'awash_birr',
        ],
    ],
];
