<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Newsletter subscriber counts for the staff backend.
 *
 * GET /v1/reports/newsletter-subscriber-counts
 *   → { counts: { "<newsletter-iri>": { active, pending, revoked } } }
 *
 * One grouped query over newsletter_subscriptions, keyed by newsletter IRI —
 * same shape as {@see ProjectReportsController::openTaskCounts}. "active" =
 * confirmed and not revoked (an effective recipient); "pending" = opted in but
 * awaiting double-opt-in confirmation; "revoked" = withdrawn (kept for audit).
 * Mandatory newsletters have no subscription rows, so they simply don't appear.
 */
final class NewsletterReportsController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {}

    #[Route(
        path: '/v1/reports/newsletter-subscriber-counts',
        name: 'api_report_newsletter_subscriber_counts',
        methods: ['GET'],
    )]
    public function subscriberCounts(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $workspace = $this->resolveWorkspace($request, $user)
            ?? throw new BadRequestHttpException('Workspace could not be determined.');

        $sql = 'SELECT newsletter_id,
                    SUM(CASE WHEN revoked_at IS NULL AND confirmed_at IS NOT NULL THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN revoked_at IS NULL AND confirmed_at IS NULL THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN revoked_at IS NOT NULL THEN 1 ELSE 0 END) AS revoked
                FROM newsletter_subscriptions
                WHERE workspace_id = :ws
                GROUP BY newsletter_id';

        $counts = [];
        foreach (
            $this->db->fetchAllAssociative($sql, ['ws' => $workspace->getId()?->toBinary()], ['ws' => ParameterType::BINARY]) as $r
        ) {
            $counts['/v1/newsletters/' . Uuid::fromBinary($r['newsletter_id'])->toRfc4122()] = [
                'active' => (int) $r['active'],
                'pending' => (int) $r['pending'],
                'revoked' => (int) $r['revoked'],
            ];
        }

        return new JsonResponse(['counts' => $counts]);
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }

    private function resolveWorkspace(Request $request, User $user): ?Workspace
    {
        $hdr = $request->headers->get('X-Workspace-Id') ?? $request->query->get('workspace');
        if (\is_string($hdr) && $hdr !== '') {
            try {
                $ws = $this->em->find(Workspace::class, Uuid::fromString($hdr));
            } catch (\InvalidArgumentException) {
                return null;
            }
            // Never trust a client-supplied workspace id: only return it when the
            // caller is actually a member (else cross-tenant report leak).
            if ($ws === null || $this->em->getRepository(WorkspaceMember::class)
                    ->findOneBy(['workspace' => $ws, 'user' => $user]) === null) {
                return null;
            }

            return $ws;
        }
        $membership = $this->em->getRepository(WorkspaceMember::class)->findOneBy(['user' => $user]);

        return $membership?->getWorkspace();
    }
}
