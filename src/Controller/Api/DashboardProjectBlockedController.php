<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Entity\Enum\TaskDependencyType;
use App\Entity\TaskDependency;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Dashboard read-model for a project board's "blocked" highlighting.
 *
 * The board used to fetch the WHOLE task_dependencies collection
 * (pagination:off, workspace-wide) just to compute which tasks are blocked —
 * a successor is blocked while a BLOCKING-type predecessor in the same project
 * is still open. This returns that set directly, scoped to one project, so the
 * board no longer ships every dependency in the workspace.
 *
 *   GET /v1/dashboard/project-blocked?project=<uuid>
 *
 * Response: { "blocked": ["/v1/tasks/…", …] }   (distinct successor IRIs)
 *
 * Blocking types mirror TaskDependencyType::isBlocking() (the same rule the
 * board's client used) — this is now the single source of truth.
 */
final class DashboardProjectBlockedController
{
    use ResolvesWorkspaceMembership;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(path: '/v1/dashboard/project-blocked', name: 'api_dashboard_project_blocked', methods: ['GET'])]
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

        $projectParam = $request->query->get('project');
        if (!\is_string($projectParam) || $projectParam === '') {
            throw new BadRequestHttpException('Query parameter project is required.');
        }
        try {
            $projectId = Uuid::fromString($projectParam);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('project must be a UUID.');
        }

        $blockingTypes = array_values(array_filter(
            TaskDependencyType::cases(),
            static fn (TaskDependencyType $t): bool => $t->isBlocking(),
        ));

        // d.workspace = :ws keeps this tenant-scoped even though `project` is
        // client-supplied — a project in another workspace yields nothing.
        $rows = $this->em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(d.successor) AS succ')
            ->from(TaskDependency::class, 'd')
            ->join('d.predecessor', 'pred')
            ->join('pred.status', 'ps')
            ->andWhere('d.workspace = :ws')
            ->andWhere('pred.project = :project')
            ->andWhere('pred.deletedAt IS NULL')
            ->andWhere('ps.isCompleted = false')
            ->andWhere('d.type IN (:types)')
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('types', $blockingTypes)
            ->getQuery()
            ->getArrayResult();

        $blocked = [];
        foreach ($rows as $r) {
            $blocked[] = '/v1/tasks/' . $this->uuidStr($r['succ']);
        }

        return new JsonResponse(['blocked' => $blocked]);
    }

    /** Normalise an IDENTITY() UUID FK (binary / string / Uuid) to RFC-4122. */
    private function uuidStr(mixed $v): string
    {
        if ($v instanceof Uuid) {
            return $v->toRfc4122();
        }
        $s = (string) $v;
        if (strlen($s) === 16) {
            return Uuid::fromBinary($s)->toRfc4122();
        }

        return $s;
    }
}
