<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Entity\ProjectStatusUpdate;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dashboard read-model for the "Status-Updates" widget — the most recent
 * project status-updates across the workspace.
 *
 * Was: fetch the WHOLE project_status_updates collection (+ all projects)
 * pagination:off and slice to 12 client-side. This returns just the newest
 * {@see self::LIMIT} rows, project + author inlined.
 *
 *   GET /v1/dashboard/recent-status-updates
 *     &workspace=<uuid>   (defaults to the caller's first membership;
 *                          X-Workspace-Id header honoured too)
 *
 * Response:
 *   { "updates": [ { "@id","id","health","title"|null,"summary"|null,"createdAt",
 *                    "project": {"@id","id","name"},
 *                    "author": {"id","name"}|null } ] }
 */
final class DashboardRecentStatusUpdatesController
{
    use ResolvesWorkspaceMembership;

    /** A dashboard glance — the newest handful, not the whole history. */
    private const LIMIT = 12;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(path: '/v1/dashboard/recent-status-updates', name: 'api_dashboard_recent_status_updates', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }
        $workspace = $this->resolveWorkspace($request, $user);
        if ($workspace === null) {
            throw new AccessDeniedHttpException('No workspace context.');
        }

        $updates = $this->em->createQueryBuilder()
            ->select('u', 'p', 'a')
            ->from(ProjectStatusUpdate::class, 'u')
            ->join('u.project', 'p')
            ->leftJoin('u.createdByUser', 'a')
            ->andWhere('u.workspace = :ws')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
            ->orderBy('u.createdAt', 'DESC')
            ->setMaxResults(self::LIMIT)
            ->getQuery()
            ->getResult();

        return new JsonResponse(['updates' => array_map($this->serialise(...), $updates)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialise(ProjectStatusUpdate $u): array
    {
        $project = $u->getProject();
        $author = $u->getCreatedByUser();

        return [
            '@id' => '/v1/project_status_updates/' . $u->getId()->toRfc4122(),
            'id' => $u->getId()->toRfc4122(),
            'health' => $u->getHealth()->value,
            'title' => $u->getTitle(),
            'summary' => $u->getSummary(),
            'createdAt' => $u->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'project' => [
                '@id' => '/v1/projects/' . $project->getId()->toRfc4122(),
                'id' => $project->getId()->toRfc4122(),
                'name' => $project->getName(),
            ],
            'author' => $author === null ? null : [
                'id' => $author->getId()->toRfc4122(),
                'name' => $author->getFullName(),
            ],
        ];
    }
}
