<?php

namespace Fluffy\Services\Security;

use Fluffy\Domain\Configuration\Config;

/**
 * Reversible encryption (AES-256-GCM) for secrets we must be able to read back, such as a
 * two-factor secret (we compute the expected code from it). Stored as
 * base64(iv12 | tag16 | ciphertext).
 *
 * The key is derived from config `hashSalt` and a PURPOSE string, so each kind of secret has its
 * own key and none shares the value used for cookie/HMAC signatures. A purpose is part of the
 * stored data's contract: changing one makes every blob stored under it unreadable. Apps add
 * their own purposes by extending this class.
 */
class SecretCipher
{
    public const PURPOSE_TOTP_SECRET = 'totp-secret-key-v1';

    public function __construct(private Config $config) {}

    public function encrypt(string $plain, string $purpose): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key($purpose), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        return base64_encode($iv . $tag . $cipher);
    }

    /** The plaintext, or null if the blob is malformed, tampered with, or from another purpose. */
    public function decrypt(string $stored, string $purpose): ?string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 28) { // 12 iv + 16 tag + >=0 cipher
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key($purpose), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    private function key(string $purpose): string
    {
        return hash('sha256', $purpose . ':' . $this->config->values['hashSalt'], true);
    }
}
