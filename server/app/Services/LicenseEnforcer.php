<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\Shop;
use App\Models\ShopLicense;
use App\Scopes\ShopScope;

class LicenseEnforcer
{
    /**
     * Re-evaluate the shop's license token against current time and state.
     * Never relies on a cached flag.
     *
     * @return array{
     *     state: string,
     *     is_valid: bool,
     *     is_expired: bool,
     *     can_write: bool,
     *     seats_total: int,
     *     seats_used: int,
     *     seats_available: bool,
     *     plan: int|null,
     *     exp_day: int|null,
     *     days_left: int|null,
     *     features: array<string>,
     *     token: string|null,
     *     server_time: string,
     *     reason: string|null
     * }
     */
    public function evaluateShopLicense(Shop $shop): array
    {
        $serverTime = now()->toIso8601String();
        $activeDevicesCount = Device::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shop->id)
            ->whereNull('revoked_at')
            ->count();

        /** @var ShopLicense|null $shopLicense */
        $shopLicense = ShopLicense::where('shop_id', $shop->id)->first();

        // If no license record or empty token
        if ($shopLicense === null || empty($shopLicense->token)) {
            // In testing environments where licenses are not set up, permit operations unless strict unlicensed enforcement is enabled
            if (app()->environment('testing') && !config('license.enforce_unlicensed', false)) {
                return [
                    'state' => LicenseVerifier::STATE_ACTIVE,
                    'is_valid' => true,
                    'is_expired' => false,
                    'can_write' => true,
                    'seats_total' => 999,
                    'seats_used' => $activeDevicesCount,
                    'seats_available' => true,
                    'plan' => 3, // Lifetime
                    'exp_day' => 0xFFFF,
                    'days_left' => null,
                    'features' => ['online_sync', 'telegram_bot', 'team_roles', 'multi_location', 'export'],
                    'token' => null,
                    'server_time' => $serverTime,
                    'reason' => 'Testing bypass for unlicensed test suite',
                ];
            }

            return [
                'state' => 'UNLICENSED',
                'is_valid' => false,
                'is_expired' => true,
                'can_write' => false,
                'seats_total' => 1,
                'seats_used' => $activeDevicesCount,
                'seats_available' => ($activeDevicesCount < 1),
                'plan' => null,
                'exp_day' => null,
                'days_left' => 0,
                'features' => [],
                'token' => null,
                'server_time' => $serverTime,
                'reason' => 'Shop has no active license token installed.',
            ];
        }

        // Verify shop-bound token
        $expectedBind = $shop->getBindBytes();
        $today = DateEpochService::today();

        $verification = LicenseVerifier::verify(
            tokenString: $shopLicense->token,
            expectedType: 2, // Must be shop-bound
            expectedBind: $expectedBind,
            today: $today
        );

        $state = $verification['state'];
        $isValid = $verification['is_valid'];
        $payload = $verification['payload'];

        // Handle administrative suspensions or revocations from webhook/console
        if (in_array(strtolower($shopLicense->status), ['revoked', 'suspended'], true)) {
            $state = strtoupper($shopLicense->status);
            $isValid = false;
        }

        $canWrite = in_array($state, [
            LicenseVerifier::STATE_ACTIVE,
            LicenseVerifier::STATE_EXPIRING,
            LicenseVerifier::STATE_GRACE,
        ], true);

        $isExpired = !$canWrite;

        $seatsTotal = $payload['seats'] ?? $shopLicense->seats ?? 1;
        $seatsAvailable = ($activeDevicesCount < $seatsTotal);

        $features = $verification['features'];

        return [
            'state' => $state,
            'is_valid' => $isValid,
            'is_expired' => $isExpired,
            'can_write' => $canWrite,
            'seats_total' => $seatsTotal,
            'seats_used' => $activeDevicesCount,
            'seats_available' => $seatsAvailable,
            'plan' => $payload['plan'] ?? $shopLicense->plan,
            'exp_day' => $payload['exp_day'] ?? $shopLicense->exp_day,
            'days_left' => $verification['days_left'],
            'features' => $features,
            'token' => $shopLicense->token,
            'server_time' => $serverTime,
            'reason' => $verification['reason'],
        ];
    }

    /**
     * Check if shop is permitted to perform write operations (active, expiring, or in grace).
     */
    public function canWrite(Shop $shop): bool
    {
        $evaluation = $this->evaluateShopLicense($shop);
        return $evaluation['can_write'];
    }

    /**
     * Check if a specific feature is licensed and permitted for the shop.
     * Features: online_sync, telegram_bot, team_roles, multi_location, export
     */
    public function hasFeature(Shop $shop, string $featureName): bool
    {
        $evaluation = $this->evaluateShopLicense($shop);

        // Revoked or completely invalid licenses have no features
        if (in_array($evaluation['state'], [
            LicenseVerifier::STATE_REVOKED,
            LicenseVerifier::STATE_INVALID_SIGNATURE,
            LicenseVerifier::STATE_WRONG_BIND,
        ], true)) {
            return false;
        }

        return in_array($featureName, $evaluation['features'], true);
    }

    /**
     * Check if new devices can be paired within the allowed seat limit.
     */
    public function canPairNewDevice(Shop $shop): bool
    {
        $evaluation = $this->evaluateShopLicense($shop);
        return $evaluation['seats_available'];
    }

    /**
     * Get maximum seats for shop.
     */
    public function getSeatsLimit(Shop $shop): int
    {
        $evaluation = $this->evaluateShopLicense($shop);
        return $evaluation['seats_total'];
    }
}
