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
     * Create a formatted Device Code (prefix 'D') or Shop Code (prefix 'S')
     * from 8 bytes of bind data.
     * Returns grouped format: 'DXXX-XXXX-XXXX-XXX' or 'SXXX-XXXX-XXXX-XXX' (15 chars).
     */
    public static function makeCode(string $prefix, string $bindBytes): string
    {
        $prefix = strtoupper($prefix);
        if ($prefix !== 'D' && $prefix !== 'S') {
            throw new InvalidArgumentException("Prefix must be 'D' (device) or 'S' (shop)");
        }
        if (strlen($bindBytes) !== 8) {
            throw new InvalidArgumentException("Bind bytes must be exactly 8 bytes");
        }

        // 8 bytes (64 bits) -> 13 Base32 characters (65 bits with 1 bit pad)
        $b32 = self::encode($bindBytes);
        $raw14 = $prefix . $b32;
        $check = self::calculateCheckChar($raw14);
        $full15 = $raw14 . $check;

        return substr($full15, 0, 4) . '-' . substr($full15, 4, 4) . '-' . substr($full15, 8, 4) . '-' . substr($full15, 12, 3);
    }

    /**
     * Parse and validate a Device or Shop code.
     * Returns the 8-byte binary bind on success.
     * Throws InvalidArgumentException on invalid format, prefix, or checksum.
     */
    public static function parseCode(string $input, ?string &$outType = null): string
    {
        $clean = self::normalize($input);
        if (strlen($clean) !== 15) {
            throw new InvalidArgumentException("Code must be 15 characters, got " . strlen($clean));
        }

        $prefix = $clean[0];
        if ($prefix === 'D') {
            $outType = 'device';
        } elseif ($prefix === 'S') {
            $outType = 'shop';
        } else {
            throw new InvalidArgumentException("Invalid code prefix '{$prefix}'. Expected 'D' for device or 'S' for shop.");
        }

        $raw14 = substr($clean, 0, 14);
        $expectedCheck = self::calculateCheckChar($raw14);
        if ($clean[14] !== $expectedCheck) {
            throw new InvalidArgumentException("Check character mismatch: expected {$expectedCheck}, got {$clean[14]}");
        }

        $b32 = substr($clean, 1, 13);
        $decoded = self::decode($b32);

        return substr($decoded, 0, 8);
    }

    /**
     * Derive 8-byte bind bytes from install_id or shop_uuid.
     */
    public static function deriveBindBytes(string $type, string $sourceId): string
    {
        if ($type === 'device') {
            $hash = hash('sha256', trim($sourceId), true);
        } else {
            $hash = hash('sha256', strtolower(trim($sourceId)), true);
        }
        return substr($hash, 0, 8);
    }
}
