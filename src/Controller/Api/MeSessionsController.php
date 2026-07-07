<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Aktive Sitzungen" endpoints for the current user. Backed by the
 * refresh-tokens table — each non-expired refresh-token represents one
 * logical session (one device/browser combination).
 *
 *   GET    /v1/me/sessions               — list valid sessions
 *   DELETE /v1/me/sessions/{id}           — revoke one session
 *   POST   /v1/me/sessions/revoke-others — revoke every session except
 *                                          the one tied to the current
 *                                          request's JWT (best-effort:
 *                                          we match by IP+UA fingerprint
 *                                          since the JWT doesn't carry
 *                                          the refresh-token id)
 *
 * Revocation just deletes the refresh-token row. The already-issued
 * access token (1h max-life) keeps working until it expires — that is
 * deliberate to keep the auth path stateless. If "instant kill" is ever
 * required, shorten Lexik's token_ttl or wire a server-side denylist.
 */
final class MeSessionsController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/me/sessions',
        name: 'api_me_sessions_list',
        methods: ['GET'],
    )]
    public function list(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $now = new \DateTime();

        $qb = $this->em->getRepository(RefreshToken::class)->createQueryBuilder('r');
        $rows = $qb
            ->andWhere('(r.userId = :uid OR r.username = :email)')
            ->andWhere('r.valid >= :now')
            ->setParameter('uid', $user->getId(), 'uuid')
            ->setParameter('email', $user->getEmail())
            ->setParameter('now', $now)
            ->orderBy('r.lastSeenAt', 'DESC')
            ->getQuery()
            ->getResult();

        $currentFingerprint = $this->fingerprint($request);
        $payload = [];
        foreach ($rows as $rt) {
            /** @var RefreshToken $rt */
            $payload[] = [
                'id' => $rt->getId(),
                'userAgent' => $rt->getUserAgent(),
                'ipAddress' => $rt->getIpAddress(),
                'createdAt' => $rt->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'lastSeenAt' => $rt->getLastSeenAt()?->format(\DateTimeInterface::ATOM),
                'validUntil' => $rt->getValid()?->format(\DateTimeInterface::ATOM),
                'isCurrent' => $this->fingerprintFor($rt) === $currentFingerprint,
            ];
        }

        return new JsonResponse(['sessions' => $payload]);
    }

    #[Route(
        path: '/v1/me/sessions/{id}',
        name: 'api_me_sessions_revoke',
        requirements: ['id' => '\d+'],
        methods: ['DELETE'],
    )]
    public function revoke(int $id): JsonResponse
    {
        $user = $this->requireUser();
        $rt = $this->em->getRepository(RefreshToken::class)->find($id);
        if ($rt === null || !$this->belongsTo($rt, $user)) {
            throw new NotFoundHttpException();
        }
        $this->em->remove($rt);
        $this->em->flush();
        return new JsonResponse(['revoked' => 1]);
    }

    #[Route(
        path: '/v1/me/sessions/revoke-others',
        name: 'api_me_sessions_revoke_others',
        methods: ['POST'],
    )]
    public function revokeOthers(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $currentFingerprint = $this->fingerprint($request);

        $rows = $this->em->getRepository(RefreshToken::class)->createQueryBuilder('r')
            ->andWhere('(r.userId = :uid OR r.username = :email)')
            ->setParameter('uid', $user->getId(), 'uuid')
            ->setParameter('email', $user->getEmail())
            ->getQuery()
            ->getResult();

        $revoked = 0;
        foreach ($rows as $rt) {
            /** @var RefreshToken $rt */
            if ($this->fingerprintFor($rt) === $currentFingerprint) {
                continue;
            }
            $this->em->remove($rt);
            $revoked++;
        }
        $this->em->flush();
        return new JsonResponse(['revoked' => $revoked]);
    }

    private function requireUser(): User
    {
        $u = $this->security->getUser();
        if (!$u instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }
        return $u;
    }

    private function belongsTo(RefreshToken $rt, User $user): bool
    {
        if ($rt->getUserId()?->equals($user->getId())) {
            return true;
        }
        // Legacy rows (pre-metadata) only have the username/email column.
        return $rt->getUsername() === $user->getEmail();
    }

    /**
     * Cheap UA+IP fingerprint to identify "this session" without leaking
     * the refresh-token value back to the client. Close enough for
     * "is this row mine?" UI hints; not a security boundary.
     */
    private function fingerprint(Request $request): string
    {
        return sha1(($request->headers->get('User-Agent') ?? '') . '|' . ($request->getClientIp() ?? ''));
    }

    private function fingerprintFor(RefreshToken $rt): string
    {
        return sha1(($rt->getUserAgent() ?? '') . '|' . ($rt->getIpAddress() ?? ''));
    }
}
