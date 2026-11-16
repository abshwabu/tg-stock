<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class TotpService
{
    public const RFC4648_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Encode binary string to RFC 4648 Base32 (used by standard Authenticator apps).
     */
    public static function base32Encode(string $data): string
    {
        $alphabet = self::RFC4648_ALPHABET;
        $binary = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($binary, 5);
        $out = '';
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= $alphabet[bindec($chunk)];
        }

        return $out;
    }

    /**
     * Decode RFC 4648 Base32 string to binary data.
     */
    public static function base32Decode(string $b32): string
    {
        $alphabet = self::RFC4648_ALPHABET;
        $clean = strtoupper(str_replace([' ', '=', '-'], '', $b32));
        $binary = '';
        $len = strlen($clean);

        for ($i = 0; $i < $len; $i++) {
            $val = strpos($alphabet, $clean[$i]);
            if ($val === false) {
                throw new InvalidArgumentException("Invalid RFC4648 Base32 character: {$clean[$i]}");
            }
            $binary .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        $byteChunks = str_split($binary, 8);
        foreach ($byteChunks as $byteChunk) {
            if (strlen($byteChunk) === 8) {
                $bytes .= chr(bindec($byteChunk));
            }
        }

        return $bytes;
    }

    /**
     * Generate a new random Base32 secret for TOTP (default 20 bytes = 160 bits).
     */
    public function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Generate standard RFC 6238 TOTP code for a secret and timestamp.
     */
    public function generateOtp(string $secretBase32, ?int $timestamp = null, int $digits = 6, int $step = 30): string
    {
        $timestamp = $timestamp ?? time();
        $counter = intdiv($timestamp, $step);
        $secretKey = self::base32Decode($secretBase32);

        return $this->generateHotp($secretKey, $counter, $digits);
    }

    /**
     * Generate HOTP code from raw binary key and integer counter (RFC 4226 / RFC 6238).
     */
    public function generateHotp(string $rawKey, int $counter, int $digits = 6): string
    {
        $binCounter = pack('J', $counter);
        $hash = hash_hmac('sha1', $binCounter, $rawKey, true);

        $offset = ord($hash[19]) & 0x0F;
        $binCode = ((ord($hash[$offset]) & 0x7F) << 24)
                 | ((ord($hash[$offset + 1]) & 0xFF) << 16)
                 | ((ord($hash[$offset + 2]) & 0xFF) << 8)
                 | (ord($hash[$offset + 3]) & 0xFF);

        $otp = $binCode % (10 ** $digits);
        return str_pad((string)$otp, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a submitted TOTP code with time-step drift tolerance.
     */
    public function verify(
        string $secretBase32,
        string $code,
        int $discrepancy = 1,
        ?int $timestamp = null,
        int $digits = 6,
        int $step = 30
    ): bool {
        $timestamp = $timestamp ?? time();
        $code = trim($code);
        if (strlen($code) !== $digits || !ctype_digit($code)) {
            return false;
        }

        $currentCounter = intdiv($timestamp, $step);
        $secretKey = self::base32Decode($secretBase32);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $expected = $this->generateHotp($secretKey, $currentCounter + $i, $digits);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate standard otpauth:// URL for QR code / authenticator apps.
     */
    public function getOtpAuthUrl(string $issuer, string $accountName, string $secretBase32): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            $secretBase32,
            rawurlencode($issuer)
        );
    }

    /**
     * Generate emergency single-use backup codes.
     */
    public function generateBackupCodes(int $count = 8): array
    {
        $plain = [];
        $hashed = [];

        for ($i = 0; $i < $count; $i++) {
            // Format: XXXX-XXXX (8 hex/alphanumeric characters)
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 4) . '-' . substr(bin2hex(random_bytes(4)), 0, 4));
            $plain[] = $code;
            $hashed[] = Hash::make(str_replace('-', '', $code));
        }

        return [
            'plain' => $plain,
            'hashed' => $hashed,
        ];
    }

    /**
     * Verify and consume a backup code.
     */
    public function verifyAndConsumeBackupCode(array &$hashedCodes, string $enteredCode): bool
    {
        $normalized = strtoupper(str_replace(['-', ' '], '', $enteredCode));

        foreach ($hashedCodes as $index => $hash) {
            if (Hash::check($normalized, $hash)) {
                unset($hashedCodes[$index]);
                $hashedCodes = array_values($hashedCodes);
                return true;
            }
        }

        return false;
    }
}
