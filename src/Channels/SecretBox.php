<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * Symmetric encryption for Channel.authConfig (passwords, OAuth
 * refresh-tokens, API keys).
 *
 * libsodium `crypto_secretbox` (XSalsa20-Poly1305). Key derived
 * once via BLAKE2b from APP_SECRET — same secret across processes,
 * survives container restarts, rotates iff APP_SECRET rotates
 * (which means re-encrypting all rows; documented operationally,
 * not automated yet).
 *
 * Wire format (base64 of `nonce || ciphertext`) keeps the JSON
 * column human-inspectable as a single string per encrypted field.
 *
 * Usage from the Channel listener:
 *
 *   $encrypted = $secretBox->seal($plaintext);
 *   $decrypted = $secretBox->open($encrypted);   // null if tampered
 */
final class SecretBox
{
    private string $key;

    public function __construct(string $appSecret)
    {
        if ($appSecret === '') {
            throw new \LogicException('APP_SECRET must be set for channel auth-config encryption.');
        }
        // 32-byte key via BLAKE2b, domain-separated from any other
        // hash-of-APP_SECRET use elsewhere in the app.
        $this->key = sodium_crypto_generichash(
            'worktide.channels.authconfig.v1' . $appSecret,
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
        );
    }

    /**
     * Encrypt a UTF-8 string. Returns a base64-encoded
     * `nonce || ciphertext` blob safe to store in a VARCHAR/JSON
     * column.
     */
    public function seal(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        return base64_encode($nonce . $cipher);
    }

    /**
     * Decrypt a blob produced by {@see seal()}. Returns null when
     * the blob is malformed or has been tampered with — callers
     * should treat this as "credentials missing" and surface a
     * useful error instead of crashing.
     */
    public function open(string $blob): ?string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        return $plain === false ? null : $plain;
    }
}
