<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use Throwable;

class LicenseVerifier
{
    public const STATE_ACTIVE = 'ACTIVE';
    public const STATE_EXPIRING = 'EXPIRING';
    public const STATE_GRACE = 'GRACE';
    public const STATE_EXPIRED = 'EXPIRED';
    public const STATE_NOT_YET_VALID = 'NOT_YET_VALID';
    public const STATE_WRONG_BIND = 'WRONG_BIND';
    public const STATE_INVALID_SIGNATURE = 'INVALID_SIGNATURE';
    public const STATE_UNSUPPORTED_VERSION = 'UNSUPPORTED_VERSION';
    public const STATE_REVOKED = 'REVOKED';

    public const FEATURE_ONLINE_SYNC = 0x0001;
    public const FEATURE_TELEGRAM_BOT = 0x0002;
    public const FEATURE_TEAM_ROLES = 0x0004;
    public const FEATURE_MULTI_LOCATION = 0x0008;
    public const FEATURE_EXPORT = 0x0010;

    /**
     * Map of bitmask flags to named features.
     */
    public const FEATURE_NAMES = [
        self::FEATURE_ONLINE_SYNC => 'online_sync',
        self::FEATURE_TELEGRAM_BOT => 'telegram_bot',
        self::FEATURE_TEAM_ROLES => 'team_roles',
        self::FEATURE_MULTI_LOCATION => 'multi_location',
        self::FEATURE_EXPORT => 'export',
    ];

    /**
     * Built-in trusted public keys by Key ID (kid) fallback.
     */
    public const TRUSTED_KEYS = [
        1 => '0110750deee76ecbe8e5b980061e61e4271929e289a7d3ccf9ce1ff6d9a90f58',
        2 => '731f9b900ed6c14d00e265f590cbc89f8f5a88baae5d56173ede17b312cd1871',
    ];

    /**
     * Verify a license token according to LICENSE_SPEC.md §8.
     *
     * @param string $tokenString Raw token string (e.g. LIC1-XXXXX-...)
     * @param int|null $expectedType 1=device-bound, 2=shop-bound
     * @param string|null $expectedBind 8 bytes binary of expected bind
     * @param int|null $today Days since 2024-01-01 in Addis Ababa (defaults to current day)
     * @param int|null $highWater Highest day previously observed
     * @param int $appMajor Current app major version (default 1)
     * @param array<int|string> $revocationList List of revoked license_id hex strings or integers
     * @param array<int, string>|null $trustedKeys Optional override for trusted public keys [kid => hex]
     * @return array{
     *     state: string,
     *     is_valid: bool,
     *     clock_suspect: bool,
     *     effective_today: int,
     *     days_left: int|null,
     *     payload: array|null,
     *     features: array<string>,
     *     reason: string|null
     * }
     */
    public static function verify(
        string $tokenString,
        ?int $expectedType = null,
        ?string $expectedBind = null,
        ?int $today = null,
        ?int $highWater = null,
        int $appMajor = 1,
        array $revocationList = [],
        ?array $trustedKeys = null
    ): array {
        $today = $today ?? DateEpochService::today();
        $highWater = $highWater ?? $today;
        $clockSuspect = ($today < ($highWater - 1));

        // 1. Strip spaces, dashes, uppercase
        $raw = strtoupper(str_replace(['-', ' '], '', $tokenString));
        if (!str_starts_with($raw, 'LIC1')) {
            return self::buildResult(self::STATE_INVALID_SIGNATURE, false, $clockSuspect, $today, null, null, 'Missing LIC1 prefix');
        }

        $b32Raw = substr($raw, 4); // Strip LIC1
        // Normalize Crockford Base32 aliases (O -> 0, I -> 1, L -> 1) on the Base32 payload
        $b32 = strtr($b32Raw, ['O' => '0', 'I' => '1', 'L' => '1']);
        if (strlen($b32) !== 152) {
            return self::buildResult(self::STATE_INVALID_SIGNATURE, false, $clockSuspect, $today, null, null, 'Invalid token length');
        }

        // 2. Decode Crockford Base32 -> 95 bytes
        try {
            $binary = CrockfordBase32::decode($b32);
        } catch (Throwable $e) {
            return self::buildResult(self::STATE_INVALID_SIGNATURE, false, $clockSuspect, $today, null, null, 'Base32 decoding failed: ' . $e->getMessage());
        }

        if (strlen($binary) !== 95) {
            return self::buildResult(self::STATE_INVALID_SIGNATURE, false, $clockSuspect, $today, null, null, 'Decoded token length must be 95 bytes');
        }

        $payload = substr($binary, 0, 31);
        $signature = substr($binary, 31, 64);

        // 3. Parse payload fields
        $fields = self::parsePayload($payload);

        // 4. Version check
        if ($fields['ver'] !== 1) {
            return self::buildResult(self::STATE_UNSUPPORTED_VERSION, false, $clockSuspect, $today, null, $fields, 'Unsupported token version');
        }

        // 5. Look up trusted public key by kid
        $pubKeyHex = self::getPublicKeyHex($fields['kid'], $trustedKeys);
        if ($pubKeyHex === null) {
            return self::buildResult(self::STATE_INVALID_SIGNATURE, false, $clockSuspect, $today, null, $fields, 'Untrusted kid: ' . $fields['kid']);
        }

        $pubKey = hex2bin($pubKeyHex);
        if ($pubKey === false || strlen($pubKey) !== 32) {
            return self::buildResult(self::STATE_INVALID_SIGNATURE, false, $clockSuspect, $today, null, $fields, 'Invalid public key length');
        }

        // 6. Verify Ed25519 signature over "LIC1" || payload (35 bytes)
        $dataToVerify = "LIC1" . $payload;
        if (!sodium_crypto_sign_verify_detached($signature, $dataToVerify, $pubKey)) {
            return self::buildResult(self::STATE_INVALID_SIGNATURE, false, $clockSuspect, $today, null, $fields, 'Ed25519 signature verification failed');
        }

        // 7. Revocation check
        if (self::isRevoked($fields['license_id_hex'], $revocationList)) {
            return self::buildResult(self::STATE_REVOKED, false, $clockSuspect, $today, null, $fields, 'License has been administratively revoked');
        }

        // 8. Max major version check
        if ($fields['max_major'] > 0 && $appMajor > $fields['max_major']) {
            return self::buildResult(self::STATE_UNSUPPORTED_VERSION, false, $clockSuspect, $today, null, $fields, 'App major version too high for license');
        }

        // 9. Type & Bind check
        if ($expectedType !== null && $fields['type'] !== $expectedType) {
            return self::buildResult(self::STATE_WRONG_BIND, false, $clockSuspect, $today, null, $fields, 'License binding type mismatch');
        }

        if ($expectedBind !== null && !hash_equals($fields['bind_bytes'], $expectedBind)) {
            return self::buildResult(self::STATE_WRONG_BIND, false, $clockSuspect, $today, null, $fields, 'License bind mismatch');
        }

        // 10. Monotonic effective today calculation
        $effectiveToday = max($today, $highWater, $fields['issued_day']);

        // 11. Date state machine
        if ($effectiveToday < $fields['nbf_day']) {
            return self::buildResult(self::STATE_NOT_YET_VALID, false, $clockSuspect, $effectiveToday, null, $fields, 'License is not valid yet');
        }

        if ($fields['exp_day'] === 0xFFFF) {
            // Lifetime
            return self::buildResult(self::STATE_ACTIVE, true, $clockSuspect, $effectiveToday, null, $fields, null);
        }

        $daysLeft = $fields['exp_day'] - $effectiveToday;

        if ($effectiveToday <= $fields['exp_day']) {
            if ($daysLeft <= 7) {
                return self::buildResult(self::STATE_EXPIRING, true, $clockSuspect, $effectiveToday, $daysLeft, $fields, null);
            }
            return self::buildResult(self::STATE_ACTIVE, true, $clockSuspect, $effectiveToday, $daysLeft, $fields, null);
        }

        $daysPastExp = $effectiveToday - $fields['exp_day'];
        if ($daysPastExp <= $fields['grace_days']) {
            $graceLeft = $fields['grace_days'] - $daysPastExp;
            return self::buildResult(self::STATE_GRACE, true, $clockSuspect, $effectiveToday, $graceLeft, $fields, 'Operating in grace period');
        }

        return self::buildResult(self::STATE_EXPIRED, false, $clockSuspect, $effectiveToday, 0, $fields, 'License has expired');
    }

    /**
     * Parse binary 31-byte payload into structured associative array.
     */
    public static function parsePayload(string $payload): array
    {
        $arr = unpack(
            'Cver/Ckid/Ctype/Cplan/Jlicense_id/a8bind/nissued_day/nnbf_day/nexp_day/Cgrace_days/Cseats/nfeatures/Cmax_major',
            $payload
        );

        $licenseIdHex = str_pad(dechex($arr['license_id']), 16, '0', STR_PAD_LEFT);

        return [
            'ver' => $arr['ver'],
            'kid' => $arr['kid'],
            'type' => $arr['type'],
            'plan' => $arr['plan'],
            'license_id' => $arr['license_id'],
            'license_id_hex' => $licenseIdHex,
            'bind_bytes' => $arr['bind'],
            'bind_hex' => bin2hex($arr['bind']),
            'issued_day' => $arr['issued_day'],
            'nbf_day' => $arr['nbf_day'],
            'exp_day' => $arr['exp_day'],
            'grace_days' => $arr['grace_days'],
            'seats' => $arr['seats'],
            'features' => $arr['features'],
            'max_major' => $arr['max_major'],
        ];
    }

    /**
     * Resolve public key hex for given kid.
     */
    protected static function getPublicKeyHex(int $kid, ?array $customTrustedKeys = null): ?string
    {
        if ($customTrustedKeys !== null) {
            return $customTrustedKeys[$kid] ?? $customTrustedKeys[(string) $kid] ?? null;
        }

        $configured = config('license_keys.keys', []);
        return $configured[$kid]['public_key_hex']
            ?? $configured[(string) $kid]['public_key_hex']
            ?? self::TRUSTED_KEYS[$kid]
            ?? null;
    }

    /**
     * Check if a license ID is in the revocation list.
     */
    protected static function isRevoked(string $licenseIdHex, array $revocationList): bool
    {
        $target = strtolower(ltrim($licenseIdHex, '0'));
        if ($target === '') {
            $target = '0';
        }

        foreach ($revocationList as $entry) {
            $entryHex = is_int($entry) ? dechex($entry) : (string) $entry;
            $cleanEntry = strtolower(ltrim($entryHex, '0'));
            if ($cleanEntry === '') {
                $cleanEntry = '0';
            }
            if ($cleanEntry === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract active feature names from bitmask.
     *
     * @return array<string>
     */
    public static function extractFeatures(int $bitmask): array
    {
        $features = [];
        foreach (self::FEATURE_NAMES as $bit => $name) {
            if (($bitmask & $bit) === $bit) {
                $features[] = $name;
            }
        }
        return $features;
    }

    /**
     * Build normalized result structure.
     */
    protected static function buildResult(
        string $state,
        bool $isValid,
        bool $clockSuspect,
        int $effectiveToday,
        ?int $daysLeft,
        ?array $payload,
        ?string $reason
    ): array {
        $features = [];
        if ($payload !== null && isset($payload['features'])) {
            $features = self::extractFeatures($payload['features']);
        }

        return [
            'state' => $state,
            'is_valid' => $isValid,
            'clock_suspect' => $clockSuspect,
            'effective_today' => $effectiveToday,
            'days_left' => $daysLeft,
            'payload' => $payload,
            'features' => $features,
            'reason' => $reason,
        ];
    }
}
