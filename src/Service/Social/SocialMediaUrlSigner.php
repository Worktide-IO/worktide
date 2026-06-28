<?php

declare(strict_types=1);

namespace App\Service\Social;

use Symfony\Component\Uid\Uuid;

/**
 * Mints short-lived, HMAC-signed public URLs for a {@see \App\Entity\File}.
 *
 * Instagram's Graph API pulls media from a public `image_url` server-side
 * (it never sends our JWT), so IG attachments need a URL reachable without
 * auth. Rather than make stored files world-readable, we hand out a signed,
 * expiring token — the token IS the credential, exactly like the inbound
 * webhook + workspace-invitation routes. The token is served by
 * {@see \App\Controller\Api\PublicSocialMediaController}.
 *
 * Token = base64url("<fileId>:<expiryUnix>") . "." . base64url(HMAC-SHA256).
 */
final class SocialMediaUrlSigner
{
    private const DEFAULT_TTL = 600; // 10 minutes — enough for Graph to fetch.

    private string $key;

    public function __construct(string $appSecret, private readonly string $apiBaseUrl)
    {
        if ($appSecret === '') {
            throw new \LogicException('APP_SECRET must be set for social media URL signing.');
        }
        // Domain-separated from SecretBox's key use.
        $this->key = hash_hmac('sha256', 'worktide.social.media-url.v1', $appSecret, true);
    }

    public function publicUrl(Uuid $fileId, int $ttlSeconds = self::DEFAULT_TTL): string
    {
        return rtrim($this->apiBaseUrl, '/') . '/v1/social/media/' . $this->sign($fileId, $ttlSeconds);
    }

    public function sign(Uuid $fileId, int $ttlSeconds = self::DEFAULT_TTL): string
    {
        $payload = $fileId->toRfc4122() . ':' . (time() + $ttlSeconds);
        $sig = hash_hmac('sha256', $payload, $this->key, true);

        return $this->b64($payload) . '.' . $this->b64($sig);
    }

    /** Returns the file id if the token is authentic and unexpired, else null. */
    public function verify(string $token): ?Uuid
    {
        $parts = explode('.', $token, 2);
        if (\count($parts) !== 2) {
            return null;
        }
        $payload = $this->unb64($parts[0]);
        $sig = $this->unb64($parts[1]);
        if ($payload === null || $sig === null) {
            return null;
        }
        $expected = hash_hmac('sha256', $payload, $this->key, true);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $bits = explode(':', $payload, 2);
        if (\count($bits) !== 2 || (int) $bits[1] < time()) {
            return null;
        }
        try {
            return Uuid::fromString($bits[0]);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function unb64(string $enc): ?string
    {
        $raw = base64_decode(strtr($enc, '-_', '+/'), true);
        return $raw === false ? null : $raw;
    }
}
