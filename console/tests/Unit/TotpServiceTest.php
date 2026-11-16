<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TotpService;
use Tests\TestCase;

class TotpServiceTest extends TestCase
{
    /**
     * Test against standard RFC 6238 Appendix B test vectors (SHA-1, 8-byte big-endian counter).
     */
    public function test_rfc_6238_appendix_b_vectors(): void
    {
        $totp = new TotpService();
        $secretKey = "12345678901234567890"; // 20 bytes ASCII seed from RFC 6238

        $expectedVectors = [
            59          => '287082',
            1111111109  => '081804',
            1111111111  => '050471',
            1234567890  => '005924',
            2000000000  => '279037',
            20000000000 => '353130',
        ];

        foreach ($expectedVectors as $timestamp => $expectedOtp) {
            $counter = intdiv($timestamp, 30);
            $actualOtp = $totp->generateHotp($secretKey, $counter, 6);
            $this->assertSame($expectedOtp, $actualOtp, "Failed RFC 6238 test vector for timestamp {$timestamp}");
        }
    }

    public function test_totp_verification_with_drift(): void
    {
        $totp = new TotpService();
        $secret = $totp->generateSecret(20);
        $currentTime = 1700000000;

        // Current code
        $code = $totp->generateOtp($secret, $currentTime);
        $this->assertTrue($totp->verify($secret, $code, 1, $currentTime));

        // Code from previous 30s step (drift = -1)
        $pastCode = $totp->generateOtp($secret, $currentTime - 30);
        $this->assertTrue($totp->verify($secret, $pastCode, 1, $currentTime));

        // Code from next 30s step (drift = +1)
        $futureCode = $totp->generateOtp($secret, $currentTime + 30);
        $this->assertTrue($totp->verify($secret, $futureCode, 1, $currentTime));

        // Code from 2 steps away (drift = 2, exceeds window = 1)
        $farPastCode = $totp->generateOtp($secret, $currentTime - 60);
        $this->assertFalse($totp->verify($secret, $farPastCode, 1, $currentTime));
    }

    public function test_backup_codes_generation_and_consumption(): void
    {
        $totp = new TotpService();
        $backupData = $totp->generateBackupCodes(8);

        $this->assertCount(8, $backupData['plain']);
        $this->assertCount(8, $backupData['hashed']);

        $hashedCodes = $backupData['hashed'];
        $firstPlain = $backupData['plain'][0];

        // First verification succeeds and consumes code
        $this->assertTrue($totp->verifyAndConsumeBackupCode($hashedCodes, $firstPlain));
        $this->assertCount(7, $hashedCodes);

        // Second verification with the same code fails (single-use)
        $this->assertFalse($totp->verifyAndConsumeBackupCode($hashedCodes, $firstPlain));
        $this->assertCount(7, $hashedCodes);
    }
}
