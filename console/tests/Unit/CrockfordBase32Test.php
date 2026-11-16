<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CrockfordBase32;
use InvalidArgumentException;
use Tests\TestCase;

class CrockfordBase32Test extends TestCase
{
    public function test_round_trip_encode_decode(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $randomBytes = random_bytes(random_int(1, 95));
            $encoded = CrockfordBase32::encode($randomBytes);
            $decoded = CrockfordBase32::decode($encoded);
            $this->assertSame($randomBytes, substr($decoded, 0, strlen($randomBytes)));
        }
    }

    public function test_normalization_rules(): void
    {
        // Ignores case, dashes, spaces, maps O -> 0, I/L -> 1
        $input = "d-7me  5q0r-xfhd-qjs";
        $normalized = CrockfordBase32::normalize($input);
        $this->assertSame("D7ME5Q0RXFHDQJS", $normalized);

        $inputAliases = "OLIO";
        $this->assertSame("0110", CrockfordBase32::normalize($inputAliases));
    }

    public function test_make_code_and_parse_code_roundtrip(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $bindBytes = random_bytes(8);

            // Device code
            $devCode = CrockfordBase32::makeCode('D', $bindBytes);
            $this->assertStringStartsWith('D', $devCode);
            $parsedDev = CrockfordBase32::parseCode($devCode, $typeDev);
            $this->assertSame($bindBytes, $parsedDev);
            $this->assertSame('device', $typeDev);

            // Shop code
            $shopCode = CrockfordBase32::makeCode('S', $bindBytes);
            $this->assertStringStartsWith('S', $shopCode);
            $parsedShop = CrockfordBase32::parseCode($shopCode, $typeShop);
            $this->assertSame($bindBytes, $parsedShop);
            $this->assertSame('shop', $typeShop);
        }
    }

    public function test_detects_corrupted_checksum(): void
    {
        $bindBytes = random_bytes(8);
        $devCode = CrockfordBase32::makeCode('D', $bindBytes);

        // Alter the check character (last character)
        $lastChar = $devCode[strlen($devCode) - 1];
        $wrongChar = ($lastChar === '0') ? '1' : '0';
        $corrupted = substr($devCode, 0, -1) . $wrongChar;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Check character mismatch');
        CrockfordBase32::parseCode($corrupted);
    }
}
