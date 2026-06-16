<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events as LexikEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Stamps the freshly-issued refresh token with request metadata so the
 * "Aktive Sitzungen" view in /settings/profile → Sicherheit has
 * something useful to render.
 *
 * Lexik fires AuthenticationSuccessEvent both on initial /v1/auth/login
 * AND on /v1/auth/refresh (gesdinet calls Lexik's TokenManager which
 * re-dispatches it). That means we can stay on a single hook for both
 * cases.
 *
 * The metadata we capture:
 *   - userId         — denormalised FK so the sessions endpoint stays a
 *                      one-shot SELECT instead of a join on email.
 *   - createdAt      — moment of issuance.
 *   - lastSeenAt     — same as createdAt at issuance; the refresh-loop
 *                      keeps it current at refresh-token-TTL granularity
 *                      (no per-request write — too hot a path for
 *                      reasonable load).
 *   - userAgent      — truncated to 255 chars.
 *   - ipAddress      — Symfony's getClientIp() honours trusted proxies.
 *
 * Listener priority is intentionally negative so gesdinet's own
 * post-creation listeners get to persist the row first; we then patch
 * the metadata into the latest-issued refresh-token row for the user.
 */
final class AuthSessionMetadataSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requests,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Gesdinet's refresh-token bundle subscribes to the *string*
            // event name (Events::AUTHENTICATION_SUCCESS), not the event
            // class. Symfony treats those as distinct dispatch keys, so
            // we have to subscribe to both:
            //   - by string name to share the dispatch slot with gesdinet
            //     and inherit its ordering semantics
            //   - by class so static analysers + future refactors find us
            // Negative priority puts us after gesdinet's listener — by
            // then $event->getData() carries the new refresh-token value.
            LexikEvents::AUTHENTICATION_SUCCESS => ['onAuthenticationSuccess', -10],
            AuthenticationSuccessEvent::class => ['onAuthenticationSuccess', -10],
        ];
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return;
        }

        // The new refresh-token value lives in the response data array
        // gesdinet just appended — that's the most reliable handle.
        $data = $event->getData();
        $refreshTokenValue = is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : null;
        if ($refreshTokenValue === null) {
            return;
        }

        $rt = $this->em->getRepository(RefreshToken::class)
            ->findOneBy(['refreshToken' => $refreshTokenValue]);
        if ($rt === null) {
            return;
        }

        $now = new \DateTimeImmutable();
        if ($rt->getCreatedAt() === null) {
            $rt->setCreatedAt($now);
        }
        $rt->setLastSeenAt($now);
        $rt->setUserId($user->getId());
        $rt->setUserAgent($request->headers->get('User-Agent'));
        $rt->setIpAddress($request->getClientIp());

        $this->em->flush();
    }
}
