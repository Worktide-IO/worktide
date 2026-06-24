<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Rate-limit two more auth-adjacent endpoints that Symfony's built-in
 * `login_throttling` doesn't cover:
 *
 *   POST /v1/auth/refresh     → rejecting a flood of refresh-token
 *                               re-issues so a leaked refresh token
 *                               can't be ground into many short JWTs
 *                               at machine speed
 *   POST /v1/me/password      → defence against credential-stuffing
 *                               where attacker has stolen the JWT and
 *                               wants to walk through "old" passwords
 *
 * Both are keyed on client IP. The login limiter (security.yaml) is
 * keyed on IP+username, which is strictly stronger but only available
 * inside the json_login firewall — for these custom endpoints we settle
 * for IP-only.
 *
 * 429 responses include the standard `Retry-After` header.
 */
final class AuthRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RateLimiterFactory $authRefreshLimiter,
        private readonly RateLimiterFactory $authPasswordChangeLimiter,
        private readonly RateLimiterFactory $authForgotPasswordLimiter,
        private readonly RateLimiterFactory $authResetPasswordLimiter,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Run very early so we abort before the firewall + controller resolve.
        return [KernelEvents::REQUEST => ['onRequest', 64]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();

        $factory = match (true) {
            $path === '/v1/auth/refresh' && $request->isMethod('POST')
                => $this->authRefreshLimiter,
            $path === '/v1/me/password' && $request->isMethod('POST')
                => $this->authPasswordChangeLimiter,
            $path === '/v1/auth/forgot-password' && $request->isMethod('POST')
                => $this->authForgotPasswordLimiter,
            $path === '/v1/auth/reset-password' && $request->isMethod('POST')
                => $this->authResetPasswordLimiter,
            default => null,
        };
        if ($factory === null) {
            return;
        }

        $limiter = $factory->create($request->getClientIp() ?? 'unknown');
        $reservation = $limiter->consume(1);
        if (!$reservation->isAccepted()) {
            $retryAfter = (int) ceil($reservation->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException(
                max(1, $retryAfter),
                sprintf('Too many requests — retry in %ds.', max(1, $retryAfter)),
            );
        }
    }
}
