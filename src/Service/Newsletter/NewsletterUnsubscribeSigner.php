<?php

declare(strict_types=1);

namespace App\Service\Newsletter;

use Symfony\Component\Uid\Uuid;

/**
 * Mints + verifies the HMAC-signed, non-expiring one-click unsubscribe token
 * embedded in every newsletter email. The token IS the credential (no auth) —
 * same idea as the booking cancel-token and social media URL signer, but with
 * NO expiry: an unsubscribe link must keep working for as long as the recipient
 * has the mail.
 *
 * Token = base64url("<contactId>:<newsletterId>") . "." . base64url(HMAC-SHA256).
 * It carries no secret about the subscription, only the two ids to remove.
 */
final class NewsletterUnsubscribeSigner
{
    private string $key;

    public function __construct(string $appSecret, private readonly string $portalBaseUrl)
    {
        if ($appSecret === '') {
            throw new \LogicException('APP_SECRET must be set for newsletter unsubscribe signing.');
        }
        // Domain-separated from the other HMAC uses (SecretBox, social URLs).
        $this->key = hash_hmac('sha256', 'worktide.newsletter.unsubscribe.v1', $appSecret, true);
    }

    public function unsubscribeUrl(Uuid $contactId, Uuid $newsletterId): string
    {
        return rtrim($this->portalBaseUrl, '/') . '/newsletter/unsubscribe/' . $this->sign($contactId, $newsletterId);
    }

    public function sign(Uuid $contactId, Uuid $newsletterId): string
    {
        $payload = $contactId->toRfc4122() . ':' . $newsletterId->toRfc4122();
        $sig = hash_hmac('sha256', $payload, $this->key, true);

        return $this->b64($payload) . '.' . $this->b64($sig);
    }

    /**
     * @return array{contactId: Uuid, newsletterId: Uuid}|null null if the token
     *                                                          is malformed or forged
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

        $bits = explode(':', $payload, 2);
        if (\count($bits) !== 2) {
            return null;
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
