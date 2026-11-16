<?php
declare(strict_types=1);

/**
 * License Test Vectors Generator for TGStock
 *
 * Deterministic generation of test vectors for:
 * 1. License tokens (Ed25519 signed, Crockford Base32)
 * 2. Device codes and shop codes (Crockford Base32 with check character)
 *
 * Requirements: ext-sodium (auto-loads scripts/ext/sodium.so if not loaded globally)
 */

if (!extension_loaded('sodium')) {
    $localSodium = __DIR__ . '/ext/sodium.so';
    if (file_exists($localSodium)) {
        // Re-exec with extension loaded
        $cmd = escapeshellarg(PHP_BINARY) . ' -d extension=' . escapeshellarg($localSodium) . ' ' . escapeshellarg(__FILE__);
        if (!empty($argv[1])) {
            $cmd .= ' ' . escapeshellarg($argv[1]);
        }
        passthru($cmd, $exitCode);
        exit($exitCode);
    } else {
        fwrite(STDERR, "Error: ext-sodium is required to run this generator.\n");
        exit(1);
    }
}

// -----------------------------------------------------------------------------
// Constants & Epoch
// -----------------------------------------------------------------------------
const CROCKFORD_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const DOMAIN_SEPARATOR = 'LIC1';
const REVOCATION_DOMAIN_SEPARATOR = 'REV1';
const EPOCH_DATE = '2024-01-01T00:00:00+03:00';
const TIMEZONE = 'Africa/Addis_Ababa';

// Fixed deterministic seeds for test keypairs
const SEED_KEY_1 = 'tgstock-offline-licensing-test-key-1'; // kid = 1 (main key)
const SEED_KEY_2 = 'tgstock-offline-licensing-test-key-2'; // kid = 2 (rotation key)
const SEED_KEY_UNTRUSTED = 'tgstock-offline-licensing-test-untrusted-key'; // kid = 99 (unknown/untrusted)

// Feature bitmask constants
const FEATURE_ONLINE_SYNC    = 1 << 0; // 0x0001
const FEATURE_TELEGRAM_BOT   = 1 << 1; // 0x0002
const FEATURE_TEAM_ROLES     = 1 << 2; // 0x0004
const FEATURE_MULTI_LOCATION = 1 << 3; // 0x0008
const FEATURE_EXPORT         = 1 << 4; // 0x0010

// Plan types
const PLAN_TRIAL    = 0;
const PLAN_MONTHLY  = 1;
const PLAN_YEARLY   = 2;
const PLAN_LIFETIME = 3;
const PLAN_CUSTOM   = 4;

// Token types
const TYPE_DEVICE_BOUND = 1;
const TYPE_SHOP_BOUND   = 2;

// Verification States
const STATE_ACTIVE              = 'ACTIVE';
const STATE_EXPIRING            = 'EXPIRING';
const STATE_GRACE               = 'GRACE';
const STATE_EXPIRED             = 'EXPIRED';
const STATE_NOT_YET_VALID       = 'NOT_YET_VALID';
const STATE_INVALID_SIGNATURE   = 'INVALID_SIGNATURE';
const STATE_WRONG_BIND          = 'WRONG_BIND';
const STATE_UNSUPPORTED_VERSION = 'UNSUPPORTED_VERSION';
const STATE_REVOKED             = 'REVOKED';

// -----------------------------------------------------------------------------
// Keypair Helper
// -----------------------------------------------------------------------------
function makeDeterministicKeypair(string $seedString): array {
    $seed = hash('sha256', $seedString, true);
    $kp = sodium_crypto_sign_seed_keypair($seed);
    return [
        'secret_key' => sodium_crypto_sign_secretkey($kp),
        'public_key' => sodium_crypto_sign_publickey($kp),
        'public_key_hex' => bin2hex(sodium_crypto_sign_publickey($kp)),
    ];
}

$keys = [
    1 => makeDeterministicKeypair(SEED_KEY_1),
    2 => makeDeterministicKeypair(SEED_KEY_2),
    99 => makeDeterministicKeypair(SEED_KEY_UNTRUSTED),
];

// -----------------------------------------------------------------------------
// Date / Day Conversion Helpers
// -----------------------------------------------------------------------------
$tz = new DateTimeZone(TIMEZONE);
$epoch = new DateTimeImmutable('2024-01-01 00:00:00', $tz);

function dayToDate(int $day): string {
    global $epoch;
    if ($day === 0xFFFF) return 'NEVER';
    return $epoch->modify("+{$day} days")->format('Y-m-d');
}

function dateToDay(string $dateStr): int {
    global $epoch, $tz;
    $d = new DateTimeImmutable($dateStr . ' 00:00:00', $tz);
    $diff = $epoch->diff($d);
    return (int)$diff->format('%r%a');
}

// -----------------------------------------------------------------------------
// Crockford Base32 Encoding / Decoding
// -----------------------------------------------------------------------------
function encodeCrockford(string $bytes): string {
    $alphabet = CROCKFORD_ALPHABET;
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

function decodeCrockford(string $str): string {
    $alphabet = CROCKFORD_ALPHABET;
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

// -----------------------------------------------------------------------------
// Device Code & Shop Code Helpers
// -----------------------------------------------------------------------------
function deriveBindBytes(int $type, string $sourceId): string {
    if ($type === TYPE_DEVICE_BOUND) {
        $hash = hash('sha256', $sourceId, true);
    } else {
        $hash = hash('sha256', strtolower(trim($sourceId)), true);
    }
    return substr($hash, 0, 8);
}

function encodeBindTo13Chars(string $bindBytes): string {
    if (strlen($bindBytes) !== 8) {
        throw new InvalidArgumentException("Bind bytes must be exactly 8 bytes");
    }
    return encodeCrockford($bindBytes); // 8 bytes -> 64 bits + 1 bit pad = 65 bits = 13 chars
}

function calculateCheckChar(string $str14): string {
    $alphabet = CROCKFORD_ALPHABET;
    $sum = 0;
    for ($i = 0; $i < 14; $i++) {
        $v = strpos($alphabet, $str14[$i]);
        if ($v === false) {
            throw new InvalidArgumentException("Invalid character: " . $str14[$i]);
        }
        // Weights: (2*i + 1) guarantees odd weight, coprime to 32
        $sum += $v * (2 * $i + 1);
    }
    return $alphabet[$sum % 32];
}

function makeCode(string $prefix, string $bindBytes): string {
    if ($prefix !== 'D' && $prefix !== 'S') {
        throw new InvalidArgumentException("Prefix must be 'D' or 'S'");
    }
    $b32 = encodeBindTo13Chars($bindBytes);
    $raw14 = $prefix . $b32;
    $check = calculateCheckChar($raw14);
    $full15 = $raw14 . $check;
    // Group as XXXX-XXXX-XXXX-XXX (4-4-4-3)
    return substr($full15, 0, 4) . '-' . substr($full15, 4, 4) . '-' . substr($full15, 8, 4) . '-' . substr($full15, 12, 3);
}

function parseCode(string $input, ?string &$outType = null): string {
    $clean = strtoupper(str_replace(['-', ' '], '', $input));
    $clean = strtr($clean, ['O' => '0', 'I' => '1', 'L' => '1']);
    if (strlen($clean) !== 15) {
        throw new InvalidArgumentException("Code must be exactly 15 characters, got " . strlen($clean));
    }
    $prefix = $clean[0];
    if ($prefix === 'D') {
        $outType = 'device';
    } elseif ($prefix === 'S') {
        $outType = 'shop';
    } else {
        throw new InvalidArgumentException("Invalid code prefix: {$prefix}");
    }
    $raw14 = substr($clean, 0, 14);
    $expectedCheck = calculateCheckChar($raw14);
    if ($clean[14] !== $expectedCheck) {
        throw new InvalidArgumentException("Check character mismatch: expected {$expectedCheck}, got {$clean[14]}");
    }
    $b32 = substr($clean, 1, 13);
    $decoded = decodeCrockford($b32);
    return substr($decoded, 0, 8);
}

// -----------------------------------------------------------------------------
// Token Building & Formatting Helpers
// -----------------------------------------------------------------------------
function packPayload(
    int $ver,
    int $kid,
    int $type,
    int $plan,
    int|string $licenseId,
    string $bindBytes,
    int $issuedDay,
    int $nbfDay,
    int $expDay,
    int $graceDays,
    int $seats,
    int $features,
    int $maxMajor
): string {
    $licIdBytes = is_string($licenseId) && strlen($licenseId) === 16 && ctype_xdigit($licenseId)
        ? hex2bin($licenseId)
        : (is_string($licenseId) && strlen($licenseId) === 8 ? $licenseId : pack('J', (int)$licenseId));

    return pack(
        'CCCC',
        $ver,
        $kid,
        $type,
        $plan
    ) . $licIdBytes . pack(
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
}

function signToken(string $payload, string $secretKey): string {
    $signedData = DOMAIN_SEPARATOR . $payload;
    $sig = sodium_crypto_sign_detached($signedData, $secretKey);
    return $payload . $sig; // 31 + 64 = 95 bytes
}

function formatTokenString(string $tokenBinary): string {
    if (strlen($tokenBinary) !== 95) {
        throw new InvalidArgumentException("Token binary must be 95 bytes, got " . strlen($tokenBinary));
    }
    $b32 = encodeCrockford($tokenBinary); // exactly 152 chars
    $groups = str_split($b32, 5); // 30 blocks of 5, 1 block of 2
    return DOMAIN_SEPARATOR . '-' . implode('-', $groups);
}

// -----------------------------------------------------------------------------
// Reference Verification Implementation
// -----------------------------------------------------------------------------
function verifyLicense(
    string $tokenString,
    int $clockDay,
    int $highWaterDay,
    int $expectedType,
    string $contextId,
    int $appMajorVersion,
    array $trustedKeys,
    array $revocationList
): array {
    // 1. String normalisation
    $clean = strtoupper(str_replace(['-', ' '], '', $tokenString));
    if (!str_starts_with($clean, DOMAIN_SEPARATOR)) {
        return ['state' => STATE_INVALID_SIGNATURE, 'clock_suspect' => false, 'error' => 'Missing LIC1 prefix'];
    }
    $body = substr($clean, strlen(DOMAIN_SEPARATOR));
    $body = strtr($body, ['O' => '0', 'I' => '1', 'L' => '1']);

    if (strlen($body) !== 152) {
        return ['state' => STATE_INVALID_SIGNATURE, 'clock_suspect' => false, 'error' => 'Invalid body length'];
    }

    try {
        $raw = decodeCrockford($body);
    } catch (Exception $e) {
        return ['state' => STATE_INVALID_SIGNATURE, 'clock_suspect' => false, 'error' => 'Base32 decode error'];
    }

    if (strlen($raw) !== 95) {
        return ['state' => STATE_INVALID_SIGNATURE, 'clock_suspect' => false, 'error' => 'Invalid raw binary length'];
    }

    $payload = substr($raw, 0, 31);
    $signature = substr($raw, 31, 64);

    // Unpack payload: use a8 for license_id to preserve full 64-bit unsigned value
    $fields = unpack(
        'Cver/Ckid/Ctype/Cplan/a8license_id/a8bind/nissued_day/nnbf_day/nexp_day/Cgrace_days/Cseats/nfeatures/Cmax_major',
        $payload
    );

    // 2. Version check
    if ($fields['ver'] !== 1) {
        return ['state' => STATE_UNSUPPORTED_VERSION, 'clock_suspect' => false, 'error' => 'Unsupported payload version'];
    }

    // 3. Signature check by kid
    $kid = $fields['kid'];
    if (!isset($trustedKeys[$kid])) {
        return ['state' => STATE_INVALID_SIGNATURE, 'clock_suspect' => false, 'error' => "Untrusted kid: {$kid}"];
    }

    $pubKey = hex2bin($trustedKeys[$kid]['public_key_hex']);
    $signedData = DOMAIN_SEPARATOR . $payload;
    if (!sodium_crypto_sign_verify_detached($signature, $signedData, $pubKey)) {
        return ['state' => STATE_INVALID_SIGNATURE, 'clock_suspect' => false, 'error' => 'Signature verification failed'];
    }

    // 4. Revocation check
    $licenseIdHex = bin2hex($fields['license_id']);
    if (in_array($licenseIdHex, $revocationList, true)) {
        return ['state' => STATE_REVOKED, 'clock_suspect' => false, 'error' => 'License is revoked'];
    }

    // 5. App Major Version check
    if ($fields['max_major'] > 0 && $appMajorVersion > $fields['max_major']) {
        return ['state' => STATE_UNSUPPORTED_VERSION, 'clock_suspect' => false, 'error' => 'App major version exceeds max_major'];
    }

    // 6. Bind check
    if ($fields['type'] !== $expectedType) {
        return ['state' => STATE_WRONG_BIND, 'clock_suspect' => false, 'error' => 'Token type does not match context'];
    }

    $expectedBind = deriveBindBytes($expectedType, $contextId);
    if ($fields['bind'] !== $expectedBind) {
        return ['state' => STATE_WRONG_BIND, 'clock_suspect' => false, 'error' => 'Bind bytes mismatch'];
    }

    // 7. Clock and High Water evaluation
    $effectiveHighWater = max($highWaterDay, $fields['issued_day']);
    $effectiveToday = max($clockDay, $effectiveHighWater);
    $clockSuspect = ($clockDay < ($effectiveHighWater - 1));

    // 8. Date validity
    if ($effectiveToday < $fields['nbf_day']) {
        return ['state' => STATE_NOT_YET_VALID, 'clock_suspect' => $clockSuspect, 'fields' => $fields];
    }

    if ($fields['exp_day'] === 0xFFFF) {
        return ['state' => STATE_ACTIVE, 'clock_suspect' => $clockSuspect, 'fields' => $fields];
    }

    if ($effectiveToday <= $fields['exp_day']) {
        $remaining = $fields['exp_day'] - $effectiveToday;
        $state = ($remaining <= 7) ? STATE_EXPIRING : STATE_ACTIVE;
        return ['state' => $state, 'clock_suspect' => $clockSuspect, 'fields' => $fields];
    }

    // Past expiry
    $pastExpiry = $effectiveToday - $fields['exp_day'];
    if ($pastExpiry <= $fields['grace_days']) {
        return ['state' => STATE_GRACE, 'clock_suspect' => $clockSuspect, 'fields' => $fields];
    }

    return ['state' => STATE_EXPIRED, 'clock_suspect' => $clockSuspect, 'fields' => $fields];
}

// -----------------------------------------------------------------------------
// Building Shared Test Vectors
// -----------------------------------------------------------------------------
$sampleInstallId1 = 'a1b2c3d4-e5f6-7a8b-9c0d-1e2f3a4b5c6d';
$sampleInstallId2 = 'ffffffff-0000-1111-2222-333333333333';
$sampleShopUuid1   = 'e3b0c442-98fc-1c14-9afb-4c8996fb9242';

$deviceBind1 = deriveBindBytes(TYPE_DEVICE_BOUND, $sampleInstallId1);
$deviceBind2 = deriveBindBytes(TYPE_DEVICE_BOUND, $sampleInstallId2);
$shopBind1   = deriveBindBytes(TYPE_SHOP_BOUND, $sampleShopUuid1);

$cases = [];

// Base reference day: 1050 (2026-11-16)
$REF_DAY = 1050;

// Helper to assemble test vector case
function makeCase(
    string $id,
    string $description,
    int $ver,
    int $kid,
    int $type,
    int $plan,
    int|string $licenseId,
    string $bindBytes,
    int $issuedDay,
    int $nbfDay,
    int $expDay,
    int $graceDays,
    int $seats,
    int $features,
    int $maxMajor,
    string $signingKey,
    int $clockDay,
    int $highWaterDay,
    int $expectedType,
    string $contextId,
    int $appMajor,
    string $expectedState,
    bool $expectedClockSuspect,
    ?callable $formatTransform = null,
    ?string $tamperBinary = null
): array {
    $payload = packPayload(
        $ver,
        $kid,
        $type,
        $plan,
        $licenseId,
        $bindBytes,
        $issuedDay,
        $nbfDay,
        $expDay,
        $graceDays,
        $seats,
        $features,
        $maxMajor
    );

    $binary = signToken($payload, $signingKey);
    if ($tamperBinary !== null) {
        $binary = $tamperBinary;
    }

    $tokenStr = formatTokenString($binary);
    if ($formatTransform !== null) {
        $tokenStr = $formatTransform($tokenStr);
    }

    $licIdHex = is_string($licenseId) && strlen($licenseId) === 16 && ctype_xdigit($licenseId)
        ? strtolower($licenseId)
        : (is_string($licenseId) && strlen($licenseId) === 8 ? bin2hex($licenseId) : sprintf('%016x', (int)$licenseId));
    if ($features & FEATURE_ONLINE_SYNC)    $featureList[] = 'online_sync';
    if ($features & FEATURE_TELEGRAM_BOT)   $featureList[] = 'telegram_bot';
    if ($features & FEATURE_TEAM_ROLES)     $featureList[] = 'team_roles';
    if ($features & FEATURE_MULTI_LOCATION) $featureList[] = 'multi_location';
    if ($features & FEATURE_EXPORT)         $featureList[] = 'export';

    return [
        'id' => $id,
        'description' => $description,
        'token' => $tokenStr,
        'raw_token_hex' => bin2hex($binary),
        'clock_day' => $clockDay,
        'clock_date' => dayToDate($clockDay),
        'high_water_day' => $highWaterDay,
        'context_type' => ($expectedType === TYPE_DEVICE_BOUND ? 'device' : 'shop'),
        'context_id' => $contextId,
        'app_major_version' => $appMajor,
        'expected_state' => $expectedState,
        'expected_clock_suspect' => $expectedClockSuspect,
        'expected_payload' => [
            'ver' => $ver,
            'kid' => $kid,
            'type' => ($type === TYPE_DEVICE_BOUND ? 'device' : 'shop'),
            'type_int' => $type,
            'plan' => ['trial', 'monthly', 'yearly', 'lifetime', 'custom'][$plan] ?? 'unknown',
            'plan_int' => $plan,
            'license_id_hex' => $licIdHex,
            'bind_hex' => bin2hex($bindBytes),
            'issued_day' => $issuedDay,
            'issued_date' => dayToDate($issuedDay),
            'nbf_day' => $nbfDay,
            'nbf_date' => dayToDate($nbfDay),
            'exp_day' => $expDay,
            'exp_date' => dayToDate($expDay),
            'grace_days' => $graceDays,
            'seats' => $seats,
            'features_bitmask' => $features,
            'features' => $featureList,
            'max_major' => $maxMajor,
        ],
    ];
}

// 1. Valid Monthly
$cases[] = makeCase(
    id: 'valid_monthly',
    description: 'Valid monthly device license with 20 days remaining until expiry',
    ver: 1,
    kid: 1,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_MONTHLY,
    licenseId: 0x0123456789ABCDEF,
    bindBytes: $deviceBind1,
    issuedDay: 1040,
    nbfDay: 1040,
    expDay: 1070, // 20 days left at day 1050
    graceDays: 7,
    seats: 1,
    features: FEATURE_ONLINE_SYNC | FEATURE_TELEGRAM_BOT | FEATURE_EXPORT,
    maxMajor: 1,
    signingKey: $keys[1]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 1,
    expectedState: STATE_ACTIVE,
    expectedClockSuspect: false
);

// 2. Expiring (<= 7 days left)
$cases[] = makeCase(
    id: 'expiring',
    description: 'Monthly device license in expiring warning window (5 days left)',
    ver: 1,
    kid: 1,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_MONTHLY,
    licenseId: 0x0123456789ABCD02,
    bindBytes: $deviceBind1,
    issuedDay: 1025,
    nbfDay: 1025,
    expDay: 1055, // 5 days left at day 1050 (<= 7 days)
    graceDays: 7,
    seats: 1,
    features: FEATURE_ONLINE_SYNC | FEATURE_EXPORT,
    maxMajor: 1,
    signingKey: $keys[1]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 1,
    expectedState: STATE_EXPIRING,
    expectedClockSuspect: false
);

// 3. Grace (expired but within grace_days)
$cases[] = makeCase(
    id: 'grace',
    description: 'Monthly license 5 days past expiry, within 7-day grace window',
    ver: 1,
    kid: 1,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_MONTHLY,
    licenseId: 0x0123456789ABCD03,
    bindBytes: $deviceBind1,
    issuedDay: 1015,
    nbfDay: 1015,
    expDay: 1045, // expired 5 days ago, grace is 7
    graceDays: 7,
    seats: 1,
    features: FEATURE_ONLINE_SYNC | FEATURE_EXPORT,
    maxMajor: 1,
    signingKey: $keys[1]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 1,
    expectedState: STATE_GRACE,
    expectedClockSuspect: false
);

// 4. Expired (past expiry and past grace window)
$cases[] = makeCase(
    id: 'expired',
    description: 'Monthly license 20 days past expiry, exceeding 7-day grace period',
    ver: 1,
    kid: 1,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_MONTHLY,
    licenseId: 0x0123456789ABCD04,
    bindBytes: $deviceBind1,
    issuedDay: 1000,
    nbfDay: 1000,
    expDay: 1030, // 20 days past expiry
    graceDays: 7,
    seats: 1,
    features: FEATURE_ONLINE_SYNC | FEATURE_EXPORT,
    maxMajor: 1,
    signingKey: $keys[1]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 1,
    expectedState: STATE_EXPIRED,
    expectedClockSuspect: false
);

// 5. Lifetime (exp_day = 0xFFFF)
$cases[] = makeCase(
    id: 'lifetime',
    description: 'Lifetime license with exp_day = 0xFFFF (never expires)',
    ver: 1,
    kid: 1,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_LIFETIME,
    licenseId: 0x0123456789ABCD05,
    bindBytes: $deviceBind1,
    issuedDay: 1000,
    nbfDay: 1000,
    expDay: 0xFFFF, // never
    graceDays: 0,
    seats: 1,
    features: FEATURE_ONLINE_SYNC | FEATURE_TELEGRAM_BOT | FEATURE_TEAM_ROLES | FEATURE_MULTI_LOCATION | FEATURE_EXPORT,
    maxMajor: 0, // any app version
    signingKey: $keys[1]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 2,
    expectedState: STATE_ACTIVE,
    expectedClockSuspect: false
);

// 6. Trial
$cases[] = makeCase(
    id: 'trial',
    description: '14-day free trial license issued on day 1045, expiring day 1059',
    ver: 1,
    kid: 1,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_TRIAL,
    licenseId: 0x0123456789ABCD06,
    bindBytes: $deviceBind1,
    issuedDay: 1045,
    nbfDay: 1045,
    expDay: 1059, // 9 days remaining (> 7)
    graceDays: 3,
    seats: 1,
    features: FEATURE_ONLINE_SYNC | FEATURE_TELEGRAM_BOT | FEATURE_EXPORT,
    maxMajor: 1,
    signingKey: $keys[1]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 1,
    expectedState: STATE_ACTIVE,
    expectedClockSuspect: false
);

// 7. Not Yet Valid (nbf_day in future)
$cases[] = makeCase(
    id: 'not_yet_valid',
    description: 'Future license scheduled to become valid on day 1060 (current day 1050)',
    ver: 1,
    kid: 1,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_MONTHLY,
    licenseId: 0x0123456789ABCD07,
    bindBytes: $deviceBind1,
    issuedDay: 1050,
    nbfDay: 1060, // future
    expDay: 1090,
    graceDays: 7,
    seats: 1,
    features: FEATURE_ONLINE_SYNC | FEATURE_EXPORT,
    maxMajor: 1,
    signingKey: $keys[1]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 1,
    expectedState: STATE_NOT_YET_VALID,
    expectedClockSuspect: false
);

// 8. Tampered Payload (signature fails)
$validPayload8 = packPayload(
    1, 1, TYPE_DEVICE_BOUND, PLAN_MONTHLY, 0x0123456789ABCD08,
    $deviceBind1, 1040, 1040, 1070, 7, 1, FEATURE_ONLINE_SYNC, 1
);
$signed8 = signToken($validPayload8, $keys[1]['secret_key']);
// Tamper byte 24 (high byte of expDay from 1070 to 2000 -> 0x07D0)
$tampered8 = $signed8;
$tampered8[24] = chr(ord($tampered8[24]) ^ 0xFF);

$cases[] = makeCase(
    id: 'tampered_payload',
    description: 'Payload byte modified after signing; signature verification must fail',
    ver: 1,
    kid: 1,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_MONTHLY,
    licenseId: 0x0123456789ABCD08,
    bindBytes: $deviceBind1,
    issuedDay: 1040,
    nbfDay: 1040,
    expDay: 1070,
    graceDays: 7,
    seats: 1,
    features: FEATURE_ONLINE_SYNC,
    maxMajor: 1,
    signingKey: $keys[1]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 1,
    expectedState: STATE_INVALID_SIGNATURE,
    expectedClockSuspect: false,
    tamperBinary: $tampered8
);

// 9. Wrong Key ID (kid = 99 not in app trusted keys)
$cases[] = makeCase(
    id: 'wrong_kid',
    description: 'Signed with untrusted key id 99 missing from app trusted public keys',
    ver: 1,
    kid: 99,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_MONTHLY,
    licenseId: 0x0123456789ABCD09,
    bindBytes: $deviceBind1,
    issuedDay: 1040,
    nbfDay: 1040,
    expDay: 1070,
    graceDays: 7,
    seats: 1,
    features: FEATURE_ONLINE_SYNC,
    maxMajor: 1,
    signingKey: $keys[99]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 1,
    expectedState: STATE_INVALID_SIGNATURE,
    expectedClockSuspect: false
);

// 10. Wrong Device Bind
$cases[] = makeCase(
    id: 'wrong_device_bind',
    description: 'Valid token bound to Device 1 presented on Device 2 (token sharing prevented)',
    ver: 1,
    kid: 1,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_MONTHLY,
    licenseId: 0x0123456789ABCD10,
    bindBytes: $deviceBind1, // bound to device 1
    issuedDay: 1040,
    nbfDay: 1040,
    expDay: 1070,
    graceDays: 7,
    seats: 1,
    features: FEATURE_ONLINE_SYNC,
    maxMajor: 1,
    signingKey: $keys[1]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId2, // tested on device 2
    appMajor: 1,
    expectedState: STATE_WRONG_BIND,
    expectedClockSuspect: false
);

// 11. Shop Bound (type = 2, verified against shop UUID)
$cases[] = makeCase(
    id: 'shop_bound',
    description: 'Shop-bound yearly license with 5 seats verified against lowercase shop UUID',
    ver: 1,
    kid: 1,
    type: TYPE_SHOP_BOUND,
    plan: PLAN_YEARLY,
    licenseId: 0x0123456789ABCD11,
    bindBytes: $shopBind1,
    issuedDay: 1000,
    nbfDay: 1000,
    expDay: 1365, // valid through day 1365
    graceDays: 14,
    seats: 5,
    features: FEATURE_ONLINE_SYNC | FEATURE_TELEGRAM_BOT | FEATURE_TEAM_ROLES | FEATURE_MULTI_LOCATION | FEATURE_EXPORT,
    maxMajor: 0,
    signingKey: $keys[1]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_SHOP_BOUND,
    contextId: $sampleShopUuid1,
    appMajor: 1,
    expectedState: STATE_ACTIVE,
    expectedClockSuspect: false
);

// 12. Max Major Too Low
$cases[] = makeCase(
    id: 'max_major_too_low',
    description: 'Token restricted to major version 1 verified on app v2.0.0',
    ver: 1,
    kid: 1,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_MONTHLY,
    licenseId: 0x0123456789ABCD12,
    bindBytes: $deviceBind1,
    issuedDay: 1040,
    nbfDay: 1040,
    expDay: 1070,
    graceDays: 7,
    seats: 1,
    features: FEATURE_ONLINE_SYNC,
    maxMajor: 1, // max major 1
    signingKey: $keys[1]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 2, // running on app v2
    expectedState: STATE_UNSUPPORTED_VERSION,
    expectedClockSuspect: false
);

// 13. Encoding Variants: Lowercase & Spaces
$cases[] = makeCase(
    id: 'encoding_variants_lowercase_spaces',
    description: 'Valid token with lowercase letters, random whitespace, and irregular dashes',
    ver: 1,
    kid: 1,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_MONTHLY,
    licenseId: 0x0123456789ABCD13,
    bindBytes: $deviceBind1,
    issuedDay: 1040,
    nbfDay: 1040,
    expDay: 1070,
    graceDays: 7,
    seats: 1,
    features: FEATURE_ONLINE_SYNC | FEATURE_EXPORT,
    maxMajor: 1,
    signingKey: $keys[1]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 1,
    expectedState: STATE_ACTIVE,
    expectedClockSuspect: false,
    formatTransform: function(string $token): string {
        $lower = strtolower($token);
        // Add random spaces around dashes
        return str_replace('-', ' - ', $lower);
    }
);

// 14. Encoding Variants: O/0 and I/L/1 Crockford Aliases
$cases[] = makeCase(
    id: 'encoding_variants_o0_il1',
    description: 'Token text with Crockford aliases O substituted for 0, and I / L substituted for 1',
    ver: 1,
    kid: 1,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_MONTHLY,
    licenseId: 0x0123456789ABCD14,
    bindBytes: $deviceBind1,
    issuedDay: 1040,
    nbfDay: 1040,
    expDay: 1070,
    graceDays: 7,
    seats: 1,
    features: FEATURE_ONLINE_SYNC,
    maxMajor: 1,
    signingKey: $keys[1]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 1,
    expectedState: STATE_ACTIVE,
    expectedClockSuspect: false,
    formatTransform: function(string $token): string {
        // Replace '0' with 'O', '1' with alternating 'I' and 'L' in the body
        $parts = explode('-', $token, 2);
        $prefix = $parts[0];
        $body = $parts[1];
        $bodyReplaced = '';
        $toggle = false;
        for ($i = 0; $i < strlen($body); $i++) {
            $c = $body[$i];
            if ($c === '0') {
                $bodyReplaced .= 'O';
            } elseif ($c === '1') {
                $bodyReplaced .= $toggle ? 'I' : 'L';
                $toggle = !$toggle;
            } else {
                $bodyReplaced .= $c;
            }
        }
        return $prefix . '-' . $bodyReplaced;
    }
);

// 15. Clock Suspect (clock rolled back: clockDay = 1040, highWaterDay = 1050)
$cases[] = makeCase(
    id: 'clock_suspect',
    description: 'Device clock rolled back 10 days behind high-water mark (effectiveToday = highWater, CLOCK_SUSPECT set)',
    ver: 1,
    kid: 1,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_MONTHLY,
    licenseId: 0x0123456789ABCD15,
    bindBytes: $deviceBind1,
    issuedDay: 1030,
    nbfDay: 1030,
    expDay: 1070, // still valid at day 1050
    graceDays: 7,
    seats: 1,
    features: FEATURE_ONLINE_SYNC,
    maxMajor: 1,
    signingKey: $keys[1]['secret_key'],
    clockDay: 1040, // clock rolled back!
    highWaterDay: 1050, // high water was 1050
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 1,
    expectedState: STATE_ACTIVE,
    expectedClockSuspect: true
);

// 16. Revoked Token
$revokedLicenseIdHex = 'deadbeef00000001';
$cases[] = makeCase(
    id: 'revoked',
    description: 'Active license whose license_id is listed in the app revocation list',
    ver: 1,
    kid: 1,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_MONTHLY,
    licenseId: $revokedLicenseIdHex,
    bindBytes: $deviceBind1,
    issuedDay: 1040,
    nbfDay: 1040,
    expDay: 1070,
    graceDays: 7,
    seats: 1,
    features: FEATURE_ONLINE_SYNC,
    maxMajor: 1,
    signingKey: $keys[1]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 1,
    expectedState: STATE_REVOKED,
    expectedClockSuspect: false
);

// 17. Key Rotation (signed with rotated kid = 2, verified against trusted key 2)
$cases[] = makeCase(
    id: 'key_rotation_kid_2',
    description: 'Token signed with key id 2 during key rotation; verified using secondary trusted key',
    ver: 1,
    kid: 2,
    type: TYPE_DEVICE_BOUND,
    plan: PLAN_YEARLY,
    licenseId: 0x0123456789ABCD17,
    bindBytes: $deviceBind1,
    issuedDay: 1045,
    nbfDay: 1045,
    expDay: 1410,
    graceDays: 14,
    seats: 1,
    features: FEATURE_ONLINE_SYNC | FEATURE_EXPORT,
    maxMajor: 1,
    signingKey: $keys[2]['secret_key'],
    clockDay: $REF_DAY,
    highWaterDay: $REF_DAY,
    expectedType: TYPE_DEVICE_BOUND,
    contextId: $sampleInstallId1,
    appMajor: 1,
    expectedState: STATE_ACTIVE,
    expectedClockSuspect: false
);

// -----------------------------------------------------------------------------
// Device & Shop Code Test Vectors
// -----------------------------------------------------------------------------
$codeCases = [];

// Device Code 1
$devCode1 = makeCode('D', $deviceBind1);
$codeCases[] = [
    'id' => 'device_code_sample_1',
    'description' => 'Valid device code derived from sampleInstallId1',
    'type' => 'device',
    'source_id' => $sampleInstallId1,
    'bind_hex' => bin2hex($deviceBind1),
    'code' => $devCode1,
    'normalized' => strtoupper(str_replace('-', '', $devCode1)),
    'expected_valid' => true,
];

// Device Code 2
$devCode2 = makeCode('D', $deviceBind2);
$codeCases[] = [
    'id' => 'device_code_sample_2',
    'description' => 'Valid device code derived from sampleInstallId2',
    'type' => 'device',
    'source_id' => $sampleInstallId2,
    'bind_hex' => bin2hex($deviceBind2),
    'code' => $devCode2,
    'normalized' => strtoupper(str_replace('-', '', $devCode2)),
    'expected_valid' => true,
];

// Shop Code 1
$shopCode1 = makeCode('S', $shopBind1);
$codeCases[] = [
    'id' => 'shop_code_sample_1',
    'description' => 'Valid shop code derived from sampleShopUuid1',
    'type' => 'shop',
    'source_id' => $sampleShopUuid1,
    'bind_hex' => bin2hex($shopBind1),
    'code' => $shopCode1,
    'normalized' => strtoupper(str_replace('-', '', $shopCode1)),
    'expected_valid' => true,
];

// Device Code with Crockford Aliases and Lowercase
$codeAliased = strtolower($devCode1);
$codeAliased = str_replace('-', ' ', $codeAliased);
$codeCases[] = [
    'id' => 'device_code_aliases_spaces',
    'description' => 'Device code with lowercase letters and space separators instead of dashes',
    'type' => 'device',
    'source_id' => $sampleInstallId1,
    'bind_hex' => bin2hex($deviceBind1),
    'code' => $codeAliased,
    'normalized' => strtoupper(str_replace('-', '', $devCode1)),
    'expected_valid' => true,
];

// Invalid Device Code (corrupted check character)
$corruptedCode = $devCode1;
$lastChar = $corruptedCode[strlen($corruptedCode) - 1];
$wrongCheck = ($lastChar === '0') ? '1' : '0';
$corruptedCode[strlen($corruptedCode) - 1] = $wrongCheck;
$codeCases[] = [
    'id' => 'device_code_bad_checksum',
    'description' => 'Device code with altered check character; must be rejected with check character mismatch',
    'type' => 'device',
    'source_id' => $sampleInstallId1,
    'bind_hex' => bin2hex($deviceBind1),
    'code' => $corruptedCode,
    'normalized' => strtoupper(str_replace('-', '', $corruptedCode)),
    'expected_valid' => false,
    'expected_error' => 'Check character mismatch',
];

// -----------------------------------------------------------------------------
// Self-Test Verification
// -----------------------------------------------------------------------------
$trustedKeysForVerification = [
    1 => ['public_key_hex' => $keys[1]['public_key_hex']],
    2 => ['public_key_hex' => $keys[2]['public_key_hex']],
];
$revocationList = [$revokedLicenseIdHex];

$allPassed = true;
foreach ($cases as $c) {
    $res = verifyLicense(
        $c['token'],
        $c['clock_day'],
        $c['high_water_day'],
        ($c['context_type'] === 'device' ? TYPE_DEVICE_BOUND : TYPE_SHOP_BOUND),
        $c['context_id'],
        $c['app_major_version'],
        $trustedKeysForVerification,
        $revocationList
    );

    if ($res['state'] !== $c['expected_state']) {
        echo "FAILED Case {$c['id']}: expected state {$c['expected_state']}, got {$res['state']} ({$res['error']})\n";
        $allPassed = false;
    }
    if ($res['clock_suspect'] !== $c['expected_clock_suspect']) {
        echo "FAILED Case {$c['id']}: expected clock_suspect " . var_export($c['expected_clock_suspect'], true) . ", got " . var_export($res['clock_suspect'], true) . "\n";
        $allPassed = false;
    }
}

foreach ($codeCases as $cc) {
    $valid = true;
    try {
        $parsedType = null;
        $bind = parseCode($cc['code'], $parsedType);
        if ($parsedType !== $cc['type']) $valid = false;
        if (bin2hex($bind) !== $cc['bind_hex']) $valid = false;
    } catch (Exception $e) {
        $valid = false;
    }
    if ($valid !== $cc['expected_valid']) {
        echo "FAILED Code Case {$cc['id']}: expected valid " . var_export($cc['expected_valid'], true) . ", got " . var_export($valid, true) . "\n";
        $allPassed = false;
    }
}

if (!$allPassed) {
    fwrite(STDERR, "Self-test failed!\n");
    exit(1);
}

// -----------------------------------------------------------------------------
// Output JSON Fixture
// -----------------------------------------------------------------------------
$outputData = [
    '$schema' => './license_vectors.schema.json',
    'title' => 'TGStock Offline Licensing Test Vectors',
    'description' => 'Deterministic shared test vectors for TGStock offline licensing verification across PHP Console, PHP Shop Server, and Flutter/Dart client.',
    'version' => 1,
    'generated_at' => (new DateTimeImmutable('now', $tz))->format(DateTimeInterface::ATOM),
    'epoch' => EPOCH_DATE,
    'timezone' => TIMEZONE,
    'trusted_keys' => [
        '1' => [
            'kid' => 1,
            'description' => 'Primary production key (kid = 1)',
            'public_key_hex' => $keys[1]['public_key_hex'],
        ],
        '2' => [
            'kid' => 2,
            'description' => 'Secondary rotation key (kid = 2)',
            'public_key_hex' => $keys[2]['public_key_hex'],
        ],
    ],
    'untrusted_keys' => [
        '99' => [
            'kid' => 99,
            'description' => 'Untrusted key not present in app client (kid = 99)',
            'public_key_hex' => $keys[99]['public_key_hex'],
        ],
    ],
    'revocation_list' => $revocationList,
    'sample_entities' => [
        'device_1' => [
            'install_id' => $sampleInstallId1,
            'bind_hex' => bin2hex($deviceBind1),
            'device_code' => $devCode1,
        ],
        'device_2' => [
            'install_id' => $sampleInstallId2,
            'bind_hex' => bin2hex($deviceBind2),
            'device_code' => $devCode2,
        ],
        'shop_1' => [
            'shop_uuid' => $sampleShopUuid1,
            'bind_hex' => bin2hex($shopBind1),
            'shop_code' => $shopCode1,
        ],
    ],
    'license_test_cases' => $cases,
    'code_test_cases' => $codeCases,
];

$outputFile = dirname(__DIR__) . '/docs/fixtures/license_vectors.json';
if (!is_dir(dirname($outputFile))) {
    mkdir(dirname($outputFile), 0755, true);
}

file_put_contents(
    $outputFile,
    json_encode($outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

echo "Successfully generated " . count($cases) . " license test cases and " . count($codeCases) . " code test cases.\n";
echo "Saved to: " . realpath($outputFile) . "\n";
