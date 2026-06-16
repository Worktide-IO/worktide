<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events as LexikEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Per-workspace override of the access-token TTL.
 *
 * Lexik computes `exp = now + token_ttl` (3600s by default) before
 * firing JWTCreatedEvent. This listener walks every workspace the user
 * is a member of, collects each one's `settings.sessionTtl.access`, and
 * — if any are stricter than the Lexik default — rewrites `exp` to the
 * shortest of them.
 *
 * Rationale: workspaces a user belongs to may have different security
 * postures. Picking the MINIMUM is the safe-by-default choice: a user
 * in a hardened workspace shouldn't get the more lenient TTL just
 * because they also belong to a relaxed one.
 *
 * Refresh-token TTL stays workspace-agnostic (gesdinet wires it as a
 * service constructor arg, harder to override at runtime). The settings
 * UI exposes it read-only with that caveat.
 */
final class JwtWorkspaceTtlSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly int $lexikDefaultTtl = 3600,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LexikEvents::JWT_CREATED => 'onJwtCreated',
            JWTCreatedEvent::class => 'onJwtCreated',
        ];
    }

    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $strictestTtl = $this->resolveMinTtl($user);
        if ($strictestTtl === null) {
            return;
        }

        $payload = $event->getData();
        // `iat` is added by Lexik a moment earlier; if it's missing for
        // some reason we fall back to current time so exp never lands
        // in the past.
        $iat = is_int($payload['iat'] ?? null) ? $payload['iat'] : time();
        $payload['exp'] = $iat + $strictestTtl;
        $event->setData($payload);
    }

    private function resolveMinTtl(User $user): ?int
    {
        $rows = $this->em->createQueryBuilder()
            ->from('App\Entity\WorkspaceMember', 'm')
            ->join('m.workspace', 'w')
            ->select('w.settings AS settings')
            ->andWhere('m.user = :u')
            ->setParameter('u', $user->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();

        $best = null;
        foreach ($rows as $row) {
            $settings = $row['settings'];
            if (!is_array($settings)) {
                continue;
            }
            $ttl = $settings['sessionTtl']['access'] ?? null;
            if (!is_int($ttl) || $ttl <= 0) {
                continue;
            }
            // Only narrower-than-default values are interesting — a
            // workspace cannot widen Lexik's default (would invert the
            // security boundary).
            if ($ttl >= $this->lexikDefaultTtl) {
                continue;
            }
            $best = $best === null ? $ttl : min($best, $ttl);
        }
        return $best;
    }
}
