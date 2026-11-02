<?php
declare(strict_types=1);

/**
 * Shared Hosting Compatibility & Verification Script for "Inventory"
 *
 * Verifies:
 * 1. PHP Version (>= 8.2)
 * 2. Required PHP Extensions: pdo_sqlite, mbstring, openssl, curl, json, ctype, tokenizer, xml
 * 3. Outbound HTTPS connectivity to api.telegram.org
 * 4. SQLite directory writable & filesystem type check
 * 5. CLI PHP binary path
 */

$isCli = (PHP_SAPI === 'cli');

function output(string $msg, bool $isCli, string $type = 'info'): void {
    if ($isCli) {
        $prefix = match ($type) {
            'pass' => "\033[32m[PASS]\033[0m ",
            'fail' => "\033[31m[FAIL]\033[0m ",
            'warn' => "\033[33m[WARN]\033[0m ",
            default => "[INFO] ",
        };
        echo $prefix . $msg . PHP_EOL;
    } else {
        $color = match ($type) {
            'pass' => '#2e7d32',
            'fail' => '#c62828',
            'warn' => '#f57f17',
            default => '#333333',
        };
        echo "<div style='font-family:sans-serif; margin:4px 0; color:{$color};'><strong>[" . strtoupper($type) . "]</strong> " . htmlspecialchars($msg) . "</div>";
    }
}

$allPassed = true;

// 1. PHP Version Check
$minPhp = '8.2.0';
if (version_compare(PHP_VERSION, $minPhp, '>=')) {
    output("PHP Version: " . PHP_VERSION . " (>= {$minPhp})", $isCli, 'pass');
} else {
    output("PHP Version: " . PHP_VERSION . " is below the required minimum of {$minPhp}", $isCli, 'fail');
    $allPassed = false;
}

// 2. Required Extensions Check
$requiredExtensions = [
    'pdo_sqlite',
    'mbstring',
    'openssl',
    'curl',
    'json',
    'ctype',
    'tokenizer',
    'xml',
];

foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        output("Extension '{$ext}': Loaded", $isCli, 'pass');
    } else {
        output("Extension '{$ext}': MISSING. Please enable it in PHP settings.", $isCli, 'fail');
        $allPassed = false;
    }
}

// 3. Outbound HTTPS to api.telegram.org Check
if (extension_loaded('curl')) {
    $ch = curl_init('https://api.telegram.org');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode > 0 && empty($curlErr)) {
        output("Outbound HTTPS to api.telegram.org: Reachable (HTTP status {$httpCode})", $isCli, 'pass');
    } else {
        output("Outbound HTTPS to api.telegram.org: FAILED (" . ($curlErr ?: "HTTP status {$httpCode}") . ")", $isCli, 'fail');
        $allPassed = false;
    }
} else {
    output("Cannot test Telegram connectivity: curl extension is not loaded.", $isCli, 'warn');
}

// 4. SQLite Storage & Writable Check
$dbDir = __DIR__ . '/../server/database';
if (!is_dir($dbDir)) {
    @mkdir($dbDir, 0755, true);
}

if (is_writable($dbDir)) {
    output("Database directory writable: {$dbDir}", $isCli, 'pass');
    $testDbPath = $dbDir . '/.test_lock.sqlite';
    try {
        $pdo = new PDO("sqlite:{$testDbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE IF NOT EXISTS _test_check (id INTEGER PRIMARY KEY, val TEXT)");
        $pdo->exec("INSERT INTO _test_check (val) VALUES ('lock_test')");
        $pdo->exec("DROP TABLE _test_check");
        unset($pdo);
        @unlink($testDbPath);
        output("SQLite POSIX file locking & execution: Working", $isCli, 'pass');
    } catch (Throwable $e) {
        output("SQLite file execution failed: " . $e->getMessage(), $isCli, 'fail');
        $allPassed = false;
    }
} else {
    output("Database directory is NOT writable: {$dbDir}", $isCli, 'fail');
    $allPassed = false;
}

// 5. CLI PHP Path
if ($isCli) {
    $phpPath = PHP_BINARY;
    output("CLI PHP Binary Path: {$phpPath}", $isCli, 'info');
}

echo PHP_EOL;
if ($allPassed) {
    output("OVERALL RESULT: Host environment meets all requirements for Inventory server.", $isCli, 'pass');
    exit(0);
} else {
    output("OVERALL RESULT: Host environment failed one or more critical checks. Please resolve them.", $isCli, 'fail');
    exit(1);
}
