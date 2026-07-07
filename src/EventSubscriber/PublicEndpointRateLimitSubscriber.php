<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Brute-force / abuse protection for the public (unauthenticated) endpoints
 * that {@see AuthRateLimitSubscriber}, the login firewall and the public-form
 * controller don't already cover. All keyed on client IP; a 429 with a
 * Retry-After header is returned when the budget is exhausted.
 *
 * Coverage (see security.yaml access_control PUBLIC_ACCESS):
 *   - POST /v1/workspace_invitations/{token}/accept  → public_token_accept
 *     (token-as-credential; also serves upcoming customer-invitation accepts)
 *   - POST|PUT /v1/inbound/(entity-)webhooks/{token}  → inbound_webhook
 *     (generous ceiling; token guessing capped without dropping legit bursts)
 *   - GET /v1/branding, /v1/setup/*, GET /v1/forms/{slug},
 *     /v1/social/media/*, /v1/channels/oauth/callback  → public_anonymous
 *     (anti-scraping / enumeration / amplification safety net)
 *
 * The auth endpoints (/v1/auth/*, /v1/me/password) and public-form *submission*
 * are deliberately NOT matched here — they keep their own tighter limiters.
 */
final readonly class PublicEndpointRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RateLimiterFactory $publicTokenAcceptLimiter,
        private RateLimiterFactory $inboundWebhookLimiter,
        private RateLimiterFactory $publicAnonymousLimiter,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Run very early so we abort before the firewall + controller resolve
        // (same priority as AuthRateLimitSubscriber; the two match disjoint paths).
        return [KernelEvents::REQUEST => ['onRequest', 64]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $isWrite = $request->isMethod('POST') || $request->isMethod('PUT');

        $factory = match (true) {
            $isWrite && preg_match('#^/v1/workspace_invitations/[^/]+/accept$#', $path) === 1
                => $this->publicTokenAcceptLimiter,
            $isWrite && (str_starts_with($path, '/v1/inbound/webhooks/')
                || str_starts_with($path, '/v1/inbound/entity-webhooks/'))
                => $this->inboundWebhookLimiter,
            $this->isAnonymousPublicPath($path)
                => $this->publicAnonymousLimiter,
            default => null,
        };
        if ($factory === null) {
            return;
        }

        $limiter = $factory->create($request->getClientIp() ?? 'unknown');
        $reservation = $limiter->consume(1);
        if (!$reservation->isAccepted()) {
            $retryAfter = max(1, (int) ceil($reservation->getRetryAfter()->getTimestamp() - time()));
            throw new TooManyRequestsHttpException(
                $retryAfter,
                sprintf('Too many requests — retry in %ds.', $retryAfter),
            );
        }
    }

    /** The cheap anonymous read/redirect endpoints covered by the catch-all. */
    private function isAnonymousPublicPath(string $path): bool
    {
        return $path === '/v1/branding'
            || str_starts_with($path, '/v1/setup')
            || str_starts_with($path, '/v1/forms/')
            || str_starts_with($path, '/v1/social/media/')
            || str_starts_with($path, '/v1/channels/oauth/callback');
    }
}
