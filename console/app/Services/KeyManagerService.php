<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Keypair;
use App\Models\Setting;
use Exception;
use RuntimeException;

class KeyManagerService
{
    /**
     * Derive a 32-byte encryption key from the application master secret.
     */
    protected function getMasterKey(): string
    {
        $rawSecret = config('license.master_key', 'tgstock-license-console-master-key-seed-2026');
        return hash('sha256', $rawSecret, true);
    }

    /**
     * Encrypt binary secret key at rest using libsodium crypto_secretbox.
     */
    public function encryptSecretAtRest(string $secretKeyBytes): string
    {
        $masterKey = $this->getMasterKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($secretKeyBytes, $nonce, $masterKey);

        return json_encode([
            'nonce' => bin2hex($nonce),
            'ciphertext' => bin2hex($ciphertext),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Decrypt secret key from encrypted JSON storage using libsodium crypto_secretbox_open.
     */
    public function decryptSecretAtRest(string $encryptedJson): string
    {
        $data = json_decode($encryptedJson, true, 512, JSON_THROW_ON_ERROR);
        $nonce = hex2bin($data['nonce']);
        $ciphertext = hex2bin($data['ciphertext']);
        $masterKey = $this->getMasterKey();

        $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $masterKey);
        if ($decrypted === false) {
            throw new RuntimeException("Failed to decrypt root keypair secret. Master key mismatch or corrupted storage.");
        }

        return $decrypted;
    }

    /**
     * Get or initialize the active keypair.
     */
    public function getActiveKeypair(): Keypair
    {
        $active = Keypair::where('is_active', true)->first();
        if ($active) {
            return $active;
        }

        // If no keys exist at all, initialize the root key kid = 1
        return $this->initializeRootKey(1);
    }

    /**
     * Initialize a key with a specific seed or random bytes.
     */
    public function initializeRootKey(int $kid = 1, ?string $seed = null): Keypair
    {
        if ($seed === null && $kid === 1) {
            $seed = env('LICENSE_ROOT_SEED_1', 'tgstock-offline-licensing-test-key-1');
        } elseif ($seed === null && $kid === 2) {
            $seed = env('LICENSE_ROOT_SEED_2', 'tgstock-offline-licensing-test-key-2');
        }

        if ($seed !== null) {
            $seedBytes = hash('sha256', $seed, true);
            $kp = sodium_crypto_sign_seed_keypair($seedBytes);
        } else {
            $kp = sodium_crypto_sign_keypair();
        }

        $sk = sodium_crypto_sign_secretkey($kp);
        $pk = sodium_crypto_sign_publickey($kp);
        $pkHex = bin2hex($pk);
        $fingerprint = hash('sha256', $pk);

        $encrypted = $this->encryptSecretAtRest($sk);

        // Deactivate any existing active key
        Keypair::where('is_active', true)->update(['is_active' => false]);

        $keypair = Keypair::updateOrCreate(
            ['kid' => $kid],
            [
                'public_key_hex' => $pkHex,
                'encrypted_secret_key' => $encrypted,
                'fingerprint' => $fingerprint,
                'is_active' => true,
            ]
        );

        Setting::set('active_kid', (string)$kid, 'Current active signing key ID');

        AuditLog::log('key_initialized', 'Keypair', (string)$kid, [
            'kid' => $kid,
            'public_key_hex' => $pkHex,
            'fingerprint' => $fingerprint,
        ]);

        return $keypair;
    }

    /**
     * Get the active key's raw binary components for signing.
     */
    public function getActiveSigningKey(): array
    {
        $keypair = $this->getActiveKeypair();
        $sk = $this->decryptSecretAtRest($keypair->encrypted_secret_key);
        $pk = hex2bin($keypair->public_key_hex);

        return [
            'kid' => $keypair->kid,
            'secret_key' => $sk,
            'public_key' => $pk,
            'public_key_hex' => $keypair->public_key_hex,
            'fingerprint' => $keypair->fingerprint,
        ];
    }

    /**
     * Get raw binary signing components for a specific kid.
     */
    public function getSigningKeyByKid(int $kid): ?array
    {
        $keypair = Keypair::find($kid);
        if (!$keypair) {
            return null;
        }

        $sk = $this->decryptSecretAtRest($keypair->encrypted_secret_key);
        $pk = hex2bin($keypair->public_key_hex);

        return [
            'kid' => $keypair->kid,
            'secret_key' => $sk,
            'public_key' => $pk,
            'public_key_hex' => $keypair->public_key_hex,
            'fingerprint' => $keypair->fingerprint,
        ];
    }

    /**
     * Rotate root signing key to a new keypair (kid = max_kid + 1).
     * Old keys remain in the database to verify old tokens.
     */
    public function rotateKey(?string $seed = null): Keypair
    {
        $maxKid = (int)(Keypair::max('kid') ?? 0);
        $nextKid = $maxKid + 1;

        if ($nextKid > 255) {
            throw new RuntimeException("Maximum key ID limit (255) reached.");
        }

        $newKeypair = $this->initializeRootKey($nextKid, $seed);

        AuditLog::log('key_rotated', 'Keypair', (string)$nextKid, [
            'old_active_kid' => $maxKid,
            'new_active_kid' => $nextKid,
            'fingerprint' => $newKeypair->fingerprint,
        ]);

        return $newKeypair;
    }

    /**
     * Export an encrypted backup of the private key using user export passphrase.
     * Uses libsodium crypto_pwhash (Argon2id) + crypto_secretbox.
     * Never returns plaintext secret key.
     */
    public function exportEncryptedBackup(int $kid, string $exportPassphrase): array
    {
        $keypair = Keypair::findOrFail($kid);
        $rawSecret = $this->decryptSecretAtRest($keypair->encrypted_secret_key);

        // Derive key from passphrase using Argon2id
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $derivedKey = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $exportPassphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($rawSecret, $nonce, $derivedKey);

        AuditLog::log('key_backup_exported', 'Keypair', (string)$kid, [
            'kid' => $kid,
            'public_key_hex' => $keypair->public_key_hex,
        ]);

        return [
            'format' => 'tgstock-key-backup-v1',
            'kid' => $kid,
            'public_key_hex' => $keypair->public_key_hex,
            'fingerprint' => $keypair->fingerprint,
            'kdf' => 'argon2id',
            'salt_hex' => bin2hex($salt),
            'nonce_hex' => bin2hex($nonce),
            'ciphertext_hex' => bin2hex($ciphertext),
            'exported_at' => (new \DateTimeImmutable('now', DateEpochService::getTimeZone()))->format(\DateTimeInterface::ATOM),
        ];
    }
}
