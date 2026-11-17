<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\User;
use App\Services\KeyManagerService;
use App\Services\TotpService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $totp = app(TotpService::class);
        $keyManager = app(KeyManagerService::class);

        // 1. Seed Root Keypair (kid = 1)
        $keyManager->initializeRootKey(1);

        // 2. Seed Super Admin User (totp_enabled_at = null prompts initial 2FA setup on first login)
        User::updateOrCreate(
            ['email' => 'admin@tgstock.et'],
            [
                'name' => 'Owner Super Admin',
                'password' => Hash::make('password123'),
                'role' => 'super_admin',
                'totp_secret' => null,
                'totp_enabled_at' => null,
                'backup_codes' => null,
            ]
        );

        // 3. Seed Support User
        $supportSecret = $totp->generateSecret();
        $supportBackup = $totp->generateBackupCodes(8);
        User::updateOrCreate(
            ['email' => 'support@tgstock.et'],
            [
                'name' => 'Support Agent',
                'password' => Hash::make('password123'),
                'role' => 'support',
                'totp_secret' => $supportSecret,
                'totp_enabled_at' => now(),
                'backup_codes' => $supportBackup['hashed'],
            ]
        );

        // 4. Seed Standard Commercial Plans (Data, never hardcoded)
        $plans = [
            [
                'name' => '14-Day Free Trial',
                'kind' => 'trial',
                'duration_days' => 14,
                'seats' => 3,
                'features' => Plan::FEATURE_ONLINE_SYNC | Plan::FEATURE_TELEGRAM_BOT | Plan::FEATURE_TEAM_ROLES | Plan::FEATURE_EXPORT,
                'grace_days' => 3,
                'price_etb' => 0.00,
                'active' => true,
            ],
            [
                'name' => 'Retail Starter Monthly (Single Device)',
                'kind' => 'monthly',
                'duration_days' => 30,
                'seats' => 1,
                'features' => Plan::FEATURE_ONLINE_SYNC | Plan::FEATURE_TELEGRAM_BOT | Plan::FEATURE_EXPORT,
                'grace_days' => 7,
                'price_etb' => 500.00,
                'active' => true,
            ],
            [
                'name' => 'Retail Pro Monthly (Shop Multi-Device)',
                'kind' => 'monthly',
                'duration_days' => 30,
                'seats' => 3,
                'features' => Plan::FEATURE_ONLINE_SYNC | Plan::FEATURE_TELEGRAM_BOT | Plan::FEATURE_TEAM_ROLES | Plan::FEATURE_EXPORT,
                'grace_days' => 7,
                'price_etb' => 1200.00,
                'active' => true,
            ],
            [
                'name' => 'Retail Yearly (Single Device)',
                'kind' => 'yearly',
                'duration_days' => 365,
                'seats' => 1,
                'features' => Plan::FEATURE_ONLINE_SYNC | Plan::FEATURE_TELEGRAM_BOT | Plan::FEATURE_TEAM_ROLES | Plan::FEATURE_EXPORT,
                'grace_days' => 14,
                'price_etb' => 5000.00,
                'active' => true,
            ],
            [
                'name' => 'Shop Master Yearly (5 Seats)',
                'kind' => 'yearly',
                'duration_days' => 365,
                'seats' => 5,
                'features' => Plan::FEATURE_ONLINE_SYNC | Plan::FEATURE_TELEGRAM_BOT | Plan::FEATURE_TEAM_ROLES | Plan::FEATURE_MULTI_LOCATION | Plan::FEATURE_EXPORT,
                'grace_days' => 14,
                'price_etb' => 12000.00,
                'active' => true,
            ],
            [
                'name' => 'Lifetime Terminal License',
                'kind' => 'lifetime',
                'duration_days' => 0, // 0 = never expires (0xFFFF)
                'seats' => 1,
                'features' => Plan::FEATURE_ONLINE_SYNC | Plan::FEATURE_TELEGRAM_BOT | Plan::FEATURE_TEAM_ROLES | Plan::FEATURE_MULTI_LOCATION | Plan::FEATURE_EXPORT,
                'grace_days' => 0,
                'price_etb' => 25000.00,
                'active' => true,
            ],
        ];

        foreach ($plans as $p) {
            Plan::updateOrCreate(['name' => $p['name']], $p);
        }
    }
}
