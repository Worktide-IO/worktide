<?php

declare(strict_types=1);

namespace App\Service\Newsletter;

use Symfony\Component\Uid\Uuid;

/**
 * Mints + verifies the HMAC-signed double-opt-in confirmation token emailed to a
 * contact who subscribed while double opt-in is on. Mirrors
 * {@see NewsletterUnsubscribeSigner} but is domain-separated and EXPIRES — an
 * unconfirmed opt-in should not stay confirmable forever.
 *
 * Token = base64url("<contactId>:<newsletterId>:<expiresAtUnix>") . "." . base64url(HMAC).
 */
final class NewsletterConfirmSigner
{
    /** Confirmation links are valid for 7 days. */
    private const TTL_SECONDS = 7 * 24 * 3600;

    private string $key;

    public function __construct(string $appSecret, private readonly string $portalBaseUrl)
    {
        if ($appSecret === '') {
            throw new \LogicException('APP_SECRET must be set for newsletter confirmation signing.');
        }
        $this->key = hash_hmac('sha256', 'worktide.newsletter.confirm.v1', $appSecret, true);
    }

    public function confirmUrl(Uuid $contactId, Uuid $newsletterId): string
    {
        return rtrim($this->portalBaseUrl, '/') . '/newsletter/confirm/' . $this->sign($contactId, $newsletterId);
    }

    public function sign(Uuid $contactId, Uuid $newsletterId): string
    {
        $expiresAt = time() + self::TTL_SECONDS;
        $payload = $contactId->toRfc4122() . ':' . $newsletterId->toRfc4122() . ':' . $expiresAt;
        $sig = hash_hmac('sha256', $payload, $this->key, true);

        return $this->b64($payload) . '.' . $this->b64($sig);
    }

    /**
     * @return array{contactId: Uuid, newsletterId: Uuid}|null null if malformed,
     *                                                          forged, or expired
     */
    public function verify(string $token): ?array
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

        $bits = explode(':', $payload, 3);
        if (\count($bits) !== 3) {
            return null;
        }
        if (!ctype_digit($bits[2]) || (int) $bits[2] < time()) {
            return null; // expired
        }
        try {
            return ['contactId' => Uuid::fromString($bits[0]), 'newsletterId' => Uuid::fromString($bits[1])];
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
