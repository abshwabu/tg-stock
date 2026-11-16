<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CrockfordBase32;
use App\Services\LicenseVerifier;
use Tests\TestCase;

class LicenseVerifierTest extends TestCase
{
    /**
     * Test verification against all deterministic shared vectors.
     */
    public function test_license_verifier_against_all_shared_vectors(): void
    {
        $fixturePath = base_path('../docs/fixtures/license_vectors.json');
        if (!file_exists($fixturePath)) {
            $fixturePath = base_path('docs/fixtures/license_vectors.json');
        }

        $this->assertFileExists($fixturePath);
        $fixture = json_decode(file_get_contents($fixturePath), true);
        $this->assertIsArray($fixture);

        $trustedKeys = [];
        foreach ($fixture['trusted_keys'] as $kid => $keyData) {
            $trustedKeys[(int) $kid] = $keyData['public_key_hex'];
        }

        $revocationList = $fixture['revocation_list'] ?? [];
        $sampleEntities = $fixture['sample_entities'];

        foreach ($fixture['license_test_cases'] as $case) {
            $id = $case['id'];
            $token = $case['token'];
            $clockDay = $case['clock_day'];
            $highWater = $case['high_water_day'] ?? $clockDay;
            $appMajor = $case['app_major_version'] ?? 1;

            $expectedState = $case['expected_state'];
            $expectedSuspect = $case['expected_clock_suspect'];

            $contextType = $case['context_type'] ?? null;
            $contextId = $case['context_id'] ?? null;

            $expectedType = null;
            $expectedBind = null;

            if ($contextType === 'device' && $contextId) {
                $expectedType = 1;
                $expectedBind = substr(hash('sha256', $contextId, true), 0, 8);
            } elseif ($contextType === 'shop' && $contextId) {
                $expectedType = 2;
                $expectedBind = substr(hash('sha256', strtolower($contextId), true), 0, 8);
            }

            $result = LicenseVerifier::verify(
                tokenString: $token,
                expectedType: $expectedType,
                expectedBind: $expectedBind,
                today: $clockDay,
                highWater: $highWater,
                appMajor: $appMajor,
                revocationList: $revocationList,
                trustedKeys: $trustedKeys
            );

            $this->assertSame(
                $expectedState,
                $result['state'],
                "Test case '{$id}' state mismatch. Got: {$result['state']}, Expected: {$expectedState}. Reason: " . ($result['reason'] ?? '')
            );

            $this->assertSame(
                $expectedSuspect,
                $result['clock_suspect'],
                "Test case '{$id}' clock_suspect mismatch."
            );
        }
    }

    /**
     * Test code parsing and formatting against code fixtures.
     */
    public function test_code_parsing_and_formatting_against_fixtures(): void
    {
        $fixturePath = base_path('../docs/fixtures/license_vectors.json');
        if (!file_exists($fixturePath)) {
            $fixturePath = base_path('docs/fixtures/license_vectors.json');
        }

        $fixture = json_decode(file_get_contents($fixturePath), true);
        $sampleEntities = $fixture['sample_entities'];

        foreach ($fixture['code_test_cases'] as $case) {
            $id = $case['id'];
            $input = $case['code'];
            $isValid = $case['expected_valid'];

            if ($isValid) {
                $parsed = CrockfordBase32::parseCode($input);
                $this->assertSame($case['bind_hex'], $parsed['bind_hex']);
                $this->assertSame($case['normalized'], str_replace('-', '', $parsed['formatted']));
            } else {
                $caught = false;
                try {
                    CrockfordBase32::parseCode($input);
                } catch (\InvalidArgumentException $e) {
                    $caught = true;
                }
                $this->assertTrue($caught, "Expected invalid code '{$id}' to fail parsing");
            }
        }
    }
}
