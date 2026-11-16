<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Keypair;
use App\Services\CrockfordBase32;
use App\Services\KeyManagerService;
use App\Services\TokenSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TokenSignerTest extends TestCase
{
    use RefreshDatabase;

    protected TokenSigner $signer;
    protected KeyManagerService $keyManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyManager = app(KeyManagerService::class);
        $this->signer = app(TokenSigner::class);
    }

    public function test_signer_against_shared_vectors(): void
    {
        $fixturePath = base_path('../docs/fixtures/license_vectors.json');
        $this->assertFileExists($fixturePath, 'license_vectors.json must exist');

        $fixtureData = json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        // Seed deterministic keys 1 and 2 matching the fixtures
        $this->keyManager->initializeRootKey(1, 'tgstock-offline-licensing-test-key-1');
        $this->keyManager->initializeRootKey(2, 'tgstock-offline-licensing-test-key-2');

        $trustedKeys = $fixtureData['trusted_keys'];

        foreach ($fixtureData['license_test_cases'] as $case) {
            $tokenStr = $case['token'];
            $expectedState = $case['expected_state'];

            // Test parsing Crockford Base32 format
            $clean = strtoupper(str_replace(['-', ' '], '', $tokenStr));
            $this->assertStringStartsWith('LIC1', $clean);

            $body = substr($clean, 4);
            $body = strtr($body, ['O' => '0', 'I' => '1', 'L' => '1']);

            $this->assertSame(152, strlen($body));
            $rawBinary = CrockfordBase32::decode($body);
            $this->assertSame(95, strlen($rawBinary));

            $payload = substr($rawBinary, 0, 31);
            $signature = substr($rawBinary, 31, 64);

            $kid = ord($payload[1]);

            // If signature should be valid according to expected test case:
            if (!in_array($expectedState, ['INVALID_SIGNATURE', 'UNSUPPORTED_VERSION'], true) && isset($trustedKeys[(string)$kid])) {
                $pubKeyHex = $trustedKeys[(string)$kid]['public_key_hex'];
                $pubKey = hex2bin($pubKeyHex);
                $signedData = 'LIC1' . $payload;

                $verifyResult = sodium_crypto_sign_verify_detached($signature, $signedData, $pubKey);
                $this->assertTrue($verifyResult, "Signature verification failed for case: {$case['id']}");
            }
        }
    }

    public function test_refuses_shop_type_without_seats(): void
    {
        $this->keyManager->initializeRootKey(1);
        $bindBytes = random_bytes(8);
        $shopCode = CrockfordBase32::makeCode('S', $bindBytes);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Shop license requires at least 1 seat');

        $this->signer->issueToken(
            type: 'shop',
            bindCode: $shopCode,
            planTypeInt: 1,
            issuedDay: 1050,
            nbfDay: 1050,
            expDay: 1080,
            seats: 0
        );
    }

    public function test_refuses_device_type_with_shop_code(): void
    {
        $this->keyManager->initializeRootKey(1);
        $bindBytes = random_bytes(8);
        $shopCode = CrockfordBase32::makeCode('S', $bindBytes);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Device license cannot use a shop code');

        $this->signer->issueToken(
            type: 'device',
            bindCode: $shopCode,
            planTypeInt: 1,
            issuedDay: 1050,
            nbfDay: 1050,
            expDay: 1080,
            seats: 1
        );
    }

    public function test_refuses_shop_type_with_device_code(): void
    {
        $this->keyManager->initializeRootKey(1);
        $bindBytes = random_bytes(8);
        $devCode = CrockfordBase32::makeCode('D', $bindBytes);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Shop license cannot use a device code');

        $this->signer->issueToken(
            type: 'shop',
            bindCode: $devCode,
            planTypeInt: 1,
            issuedDay: 1050,
            nbfDay: 1050,
            expDay: 1080,
            seats: 3
        );
    }

    public function test_refuses_expiry_before_start(): void
    {
        $this->keyManager->initializeRootKey(1);
        $bindBytes = random_bytes(8);
        $devCode = CrockfordBase32::makeCode('D', $bindBytes);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expiry day (1000) cannot be before start day (1050)');

        $this->signer->issueToken(
            type: 'device',
            bindCode: $devCode,
            planTypeInt: 1,
            issuedDay: 1050,
            nbfDay: 1050,
            expDay: 1000,
            seats: 1
        );
    }

    public function test_refuses_multiple_seats_for_device_license(): void
    {
        $this->keyManager->initializeRootKey(1);
        $bindBytes = random_bytes(8);
        $devCode = CrockfordBase32::makeCode('D', $bindBytes);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('seats cannot exceed 1');

        $this->signer->issueToken(
            type: 'device',
            bindCode: $devCode,
            planTypeInt: 1,
            issuedDay: 1050,
            nbfDay: 1050,
            expDay: 1080,
            seats: 3
        );
    }
}
