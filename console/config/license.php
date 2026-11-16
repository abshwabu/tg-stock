<?php

declare(strict_types=1);

return [
    'epoch' => '2024-01-01T00:00:00+03:00',
    'timezone' => 'Africa/Addis_Ababa',
    'master_key' => env('LICENSE_MASTER_KEY', 'tgstock-license-console-master-key-seed-2026'),
    'hmac_secret' => env('CONSOLE_HMAC_SECRET', 'tgstock-console-shared-api-hmac-secret-v1'),
    'webhook_secret' => env('CONSOLE_WEBHOOK_SECRET', 'tgstock-console-webhook-shared-secret-v1'),
    'backup_encryption_key' => env('BACKUP_ENCRYPTION_KEY', 'tgstock-offline-backup-aes-key-v1-2026'),
    'reissue_yearly_warning_limit' => 2,
    'default_grace_days' => 7,
];
