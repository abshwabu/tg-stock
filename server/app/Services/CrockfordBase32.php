<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

class CrockfordBase32
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * Encode binary bytes into Crockford Base32 string.
     */
    public static function encode(string $bytes): string
    {
        $alphabet = self::ALPHABET;
        $out = '';
        $buffer = 0;
        $bits = 0;
        $len = strlen($bytes);

        for ($i = 0; $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($bytes[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $idx = ($buffer >> $bits) & 0x1F;
                $out .= $alphabet[$idx];
            }
        }

        if ($bits > 0) {
            $idx = ($buffer << (5 - $bits)) & 0x1F;
            $out .= $alphabet[$idx];
        }

        return $out;
    }

    /**
     * Decode Crockford Base32 string into binary bytes.
     */
    public static function decode(string $str): string
    {
        $alphabet = self::ALPHABET;
        $buffer = 0;
        $bits = 0;
        $bytes = '';
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $char = $str[$i];
            $val = strpos($alphabet, $char);
            if ($val === false) {
                throw new InvalidArgumentException("Invalid Crockford Base32 character: {$char}");
            }
            $buffer = ($buffer << 5) | $val;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $bytes .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $bytes;
    }

    /**
     * Normalize user-entered string by stripping spaces and dashes, uppercasing,
     * and mapping ambiguous characters (O -> 0, I/L -> 1).
     */
    public static function normalize(string $input): string
    {
        $clean = strtoupper(str_replace(['-', ' '], '', $input));
        return strtr($clean, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    /**
     * Calculate single check character for a 14-character string using weighted modulo 32.
     * Weights: (2*i + 1) are always odd and therefore coprime to 32.
     */
    public static function calculateCheckChar(string $str14): string
    {
        if (strlen($str14) !== 14) {
            throw new InvalidArgumentException("Input must be exactly 14 characters to compute check character");
        }

        $alphabet = self::ALPHABET;
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $v = strpos($alphabet, $str14[$i]);
            if ($v === false) {
                throw new InvalidArgumentException("Invalid character: " . $str14[$i]);
            }
            $sum += $v * (2 * $i + 1);
        }

        return $alphabet[$sum % 32];
    }

    /**
     * Format an 8-byte bind into a 15-character code with prefix and check character, grouped by 4.
     * Prefix 'D' for device, 'S' for shop.
     * e.g. DXXX-XXXX-XXXX-XXX or SXXX-XXXX-XXXX-XXX
     */
    public static function formatCode(string $prefix, string $bind8Bytes): string
    {
        if (strlen($bind8Bytes) !== 8) {
            throw new InvalidArgumentException("Bind must be exactly 8 bytes");
        }

        $prefix = strtoupper($prefix);
        if ($prefix !== 'D' && $prefix !== 'S') {
            throw new InvalidArgumentException("Prefix must be 'D' or 'S'");
        }

        $b32 = substr(self::encode($bind8Bytes), 0, 13);
        $payload14 = $prefix . $b32;
        $check = self::calculateCheckChar($payload14);
        $full15 = $payload14 . $check;

        return substr($full15, 0, 4) . '-' .
               substr($full15, 4, 4) . '-' .
               substr($full15, 8, 4) . '-' .
               substr($full15, 12, 3);
    }

    /**
     * Format a device code from install ID (UUID).
     */
    public static function formatDeviceCode(string $installId): string
    {
        $bind = substr(hash('sha256', trim($installId), true), 0, 8);
        return self::formatCode('D', $bind);
    }

    /**
     * Format a shop code from shop UUID.
     */
    public static function formatShopCode(string $shopUuid): string
    {
        $bind = substr(hash('sha256', strtolower(trim($shopUuid)), true), 0, 8);
        return self::formatCode('S', $bind);
    }

    /**
     * Parse and validate a 15-character code (ignoring spaces, dashes, case, O/I/L).
     *
     * @return array{prefix: string, type: int, bind_bytes: string, bind_hex: string, formatted: string}
     */
    public static function parseCode(string $input): array
    {
        $normalized = self::normalize($input);
        if (strlen($normalized) !== 15) {
            throw new InvalidArgumentException("Invalid code length. Expected 15 characters, got " . strlen($normalized));
        }

        $prefix = $normalized[0];
        if ($prefix !== 'D' && $prefix !== 'S') {
            throw new InvalidArgumentException("Invalid code prefix '{$prefix}'. Expected 'D' (device) or 'S' (shop)");
        }

        $payload14 = substr($normalized, 0, 14);
        $checkChar = $normalized[14];
        $expectedCheck = self::calculateCheckChar($payload14);

        if ($checkChar !== $expectedCheck) {
            throw new InvalidArgumentException("Check character mismatch. Typo in code.");
        }

        $b32_13 = substr($payload14, 1, 13);
        // Pad to 65 bits (13 * 5 = 65 bits = 8 bytes + 1 bit 0)
        $rawBytes = self::decode($b32_13);
        $bind8Bytes = substr($rawBytes, 0, 8);

        return [
            'prefix' => $prefix,
            'type' => $prefix === 'D' ? 1 : 2,
            'bind_bytes' => $bind8Bytes,
            'bind_hex' => bin2hex($bind8Bytes),
            'formatted' => substr($normalized, 0, 4) . '-' .
                           substr($normalized, 4, 4) . '-' .
                           substr($normalized, 8, 4) . '-' .
                           substr($normalized, 12, 3),
        ];
    }
}
