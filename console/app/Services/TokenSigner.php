<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use InvalidArgumentException;
use RuntimeException;

class TokenSigner
{
    public const DOMAIN_SEPARATOR = 'LIC1';
    public const REVOCATION_DOMAIN_SEPARATOR = 'REV1';

    public function __construct(
        protected KeyManagerService $keyManager
    ) {}

    /**
     * Build, validate, sign, and encode a license token.
     * This is the strictly guarded code path touching the private signing key.
     */
    public function issueToken(
        string $type, // 'device' or 'shop'
        string $bindCode, // 'DXXX-XXXX-XXXX-XXX' or 'SXXX-XXXX-XXXX-XXX'
        int $planTypeInt, // 0..4
        int $issuedDay,
        int $nbfDay,
        int $expDay, // 0xFFFF = lifetime
        int $graceDays = 7,
        int $seats = 1,
        int $features = 0,
        int $maxMajor = 0,
        ?int $overrideLicenseId = null,
        ?int $signingKid = null
    ): array {
        // 1. Validate Code and Type Match
        $codeType = null;
        $bindBytes = CrockfordBase32::parseCode($bindCode, $codeType);

        if ($type === 'device' && $codeType !== 'device') {
            throw new InvalidArgumentException("Device license cannot use a shop code ('{$bindCode}')");
        }
        if ($type === 'shop' && $codeType !== 'shop') {
            throw new InvalidArgumentException("Shop license cannot use a device code ('{$bindCode}')");
        }

        // 2. Validate Seats
        if ($type === 'shop' && $seats < 1) {
            throw new InvalidArgumentException("Shop license requires at least 1 seat, got {$seats}");
        }
        if ($type === 'device' && $seats > 1) {
            throw new InvalidArgumentException("Device license covers a single device; seats cannot exceed 1 (got {$seats})");
        }

        // 3. Validate Date Logic
        if ($expDay !== 0xFFFF && $expDay < $nbfDay) {
            throw new InvalidArgumentException("Expiry day ({$expDay}) cannot be before start day ({$nbfDay})");
        }

        // 4. Retrieve Signing Key
        $keyData = $signingKid !== null
            ? $this->keyManager->getSigningKeyByKid($signingKid)
            : $this->keyManager->getActiveSigningKey();

        if (!$keyData) {
            throw new RuntimeException("Signing key (kid={$signingKid}) not found.");
        }

        $kid = $keyData['kid'];
        $secretKey = $keyData['secret_key'];

        // 5. Generate or assign random 64-bit license_id
        if ($overrideLicenseId !== null) {
            $licenseIdBytes = pack('J', $overrideLicenseId);
            $licenseIdHex = sprintf('%016x', $overrideLicenseId);
        } else {
            $licenseIdBytes = random_bytes(8);
            $licenseIdHex = bin2hex($licenseIdBytes);
        }

        $typeInt = ($type === 'device') ? 1 : 2;
        $ver = 1;

        // 6. Pack 31-byte binary payload (Big-Endian)
        $payload = pack(
            'CCCC',
            $ver,
            $kid,
            $typeInt,
            $planTypeInt
        ) . $licenseIdBytes . pack(
            'a8nnnCCnC',
            $bindBytes,
            $issuedDay,
            $nbfDay,
            $expDay,
            $graceDays,
            $seats,
            $features,
            $maxMajor
        );

        if (strlen($payload) !== 31) {
            throw new RuntimeException("Payload packaging error: expected 31 bytes, got " . strlen($payload));
        }

        // 7. Ed25519 Signature over "LIC1" || payload (35 bytes signed)
        $signedData = self::DOMAIN_SEPARATOR . $payload;
        $signature = sodium_crypto_sign_detached($signedData, $secretKey);

        if (strlen($signature) !== 64) {
            throw new RuntimeException("Signature generation failed: expected 64 bytes");
        }

        // 8. Combine into 95-byte binary token
        $rawBinary = $payload . $signature;

        // 9. Format into Crockford Base32 string (152 chars, grouped in 5s with LIC1- prefix)
        $b32 = CrockfordBase32::encode($rawBinary);
        $groups = str_split($b32, 5);
        $formattedToken = self::DOMAIN_SEPARATOR . '-' . implode('-', $groups);

        AuditLog::log('token_signed', 'LicenseToken', $licenseIdHex, [
            'kid' => $kid,
            'type' => $type,
            'plan_type' => $planTypeInt,
            'issued_day' => $issuedDay,
            'exp_day' => $expDay,
            'bind_code' => $bindCode,
        ]);

        return [
            'token' => $formattedToken,
            'kid' => $kid,
            'license_id_hex' => $licenseIdHex,
            'bind_bytes_hex' => bin2hex($bindBytes),
            'raw_binary_hex' => bin2hex($rawBinary),
            'raw_payload_hex' => bin2hex($payload),
            'issued_day' => $issuedDay,
            'nbf_day' => $nbfDay,
            'exp_day' => $expDay,
            'grace_days' => $graceDays,
            'seats' => $seats,
            'features' => $features,
            'max_major' => $maxMajor,
        ];
    }

    /**
     * Sign a revocation list in accordance with SPEC §12.
     * Format: "REV1" || ver(1)=1 || kid(1) || list_day(u16) || count(u16) || license_ids(8 bytes each).
     */
    public function signRevocationList(array $revokedLicenseIdHexes, ?int $listDay = null): array
    {
        $listDay = $listDay ?? DateEpochService::todayDay();
        $keyData = $this->keyManager->getActiveSigningKey();
        $kid = $keyData['kid'];
        $secretKey = $keyData['secret_key'];

        $count = count($revokedLicenseIdHexes);
        $payload = pack('CCnn', 1, $kid, $listDay, $count);

        foreach ($revokedLicenseIdHexes as $hex) {
            $hex = str_pad(strtolower(trim($hex)), 16, '0', STR_PAD_LEFT);
            $payload .= hex2bin($hex);
        }

        $signedData = self::REVOCATION_DOMAIN_SEPARATOR . $payload;
        $sig = sodium_crypto_sign_detached($signedData, $secretKey);

        return [
            'ver' => 1,
            'kid' => $kid,
            'list_day' => $listDay,
            'count' => $count,
            'revoked_ids' => $revokedLicenseIdHexes,
            'payload_hex' => bin2hex($payload),
            'signature_hex' => bin2hex($sig),
            'public_key_hex' => $keyData['public_key_hex'],
        ];
    }
}
