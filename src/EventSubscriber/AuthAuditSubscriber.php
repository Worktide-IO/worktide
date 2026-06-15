<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\DomainEventLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Record authentication-related events into the DomainEventLog so the
 * Activity feed (and any future SIEM-style export) can see them.
 *
 * Events emitted (all aggregateType = "Auth"):
 *   - auth.login.succeeded   payload: { email, ip, userAgent }
 *   - auth.login.failed      payload: { username, ip, userAgent, reason,
 *                                       throttled: bool }
 *   - auth.logout            payload: { ip, userAgent }
 *
 * `auth.password.changed` lives in the MeProfileController because it
 * doesn't go through the security firewall.
 *
 * The actor on success is the authenticated user; on failure it's NULL
 * by design (we don't know who tried — username is just what they typed,
 * not necessarily a real account). Logout has the still-current user.
 */
final class AuthAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }
        $this->log('auth.login.succeeded', $user, [
            'email' => $user->getEmail(),
            ...$this->requestContext(),
        ]);
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $exception = $event->getException();
        $throttled = $exception instanceof TooManyLoginAttemptsAuthenticationException
            || $exception->getPrevious() instanceof TooManyLoginAttemptsAuthenticationException;

        $username = null;
        $request = $event->getRequest();
        $body = json_decode($request->getContent() ?: '{}', true);
        if (is_array($body) && isset($body['email']) && is_string($body['email'])) {
            $username = mb_substr($body['email'], 0, 254);
        }
        $this->log('auth.login.failed', null, [
            'username' => $username,
            'reason' => $exception::class,
            'throttled' => $throttled,
            ...$this->requestContext(),
        ]);
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }
        $this->log('auth.logout', $user, $this->requestContext());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function log(string $name, ?User $actor, array $payload): void
    {
        $this->em->persist(new DomainEventLog(
            name: $name,
            aggregateType: 'Auth',
            aggregateId: null,
            workspace: null,
            actor: $actor,
            payload: $payload,
        ));
        $this->em->flush();
    }

    /**
     * @return array{ip: string, userAgent: string}
     */
    private function requestContext(): array
    {
        $request = $this->requestStack->getMainRequest();
        return [
            'ip' => $request?->getClientIp() ?? 'unknown',
            'userAgent' => mb_substr($request?->headers->get('User-Agent', '—') ?? '—', 0, 255),
        ];
    }
}
