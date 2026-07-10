<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\ProjectStatus;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Dashboard read-model for "The Wall" (workspace-wide project standup view).
 *
 * The page used to fetch FIVE whole collections with pagination:off —
 * project_statuses, projects, task_statuses, EVERY task (just to count
 * open/total per project), and project_members — then assemble lanes +
 * per-project counts + members client-side. That is O(workspace-size) network,
 * dominated by the all-tasks fetch.
 *
 * This returns the assembled model in four bounded queries: the open lanes, the
 * non-archived projects (customer inlined), per-project task counts via a single
 * GROUP BY (no task rows shipped), and the member user-IRIs per project. Search
 * + lane grouping stay client-side (cheap over the bounded project set).
 *
 *   GET /v1/dashboard/wall
 *     &workspace=<uuid>   (defaults to caller's first membership; header honoured)
 *
 * Response:
 *   {
 *     "lanes":    [ { "@id","id","name","color" } ],   // open, ordered by position
 *     "projects": [ { "@id","id","name","key","description"|null,"color","updatedAt",
 *                     "status",                         // ProjectStatus IRI (lane key)
 *                     "customer": {"id","name"}|null,
 *                     "totalTasks","openTasks",
 *                     "memberIris": ["/v1/users/…"] } ]
 *   }
 */
final class DashboardWallController
{
    use ResolvesWorkspaceMembership;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(path: '/v1/dashboard/wall', name: 'api_dashboard_wall', methods: ['GET'])]
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
        $wsId = $workspace->getId();

        // 1) Open lanes (non-completed, non-archived project statuses).
        $lanes = $this->em->createQueryBuilder()
            ->select('s')
            ->from(ProjectStatus::class, 's')
            ->andWhere('s.workspace = :ws')
            ->andWhere('s.isCompleted = false')
            ->andWhere('s.isArchived = false')
            ->setParameter('ws', $wsId, UuidType::NAME)
            ->orderBy('s.position', 'ASC')
            ->getQuery()
            ->getResult();

        // 2) Non-archived projects, customer join-fetched.
        $projects = $this->em->createQueryBuilder()
            ->select('p', 'c')
            ->from(Project::class, 'p')
            ->leftJoin('p.customer', 'c')
            ->andWhere('p.workspace = :ws')
            ->andWhere('p.isArchived = false')
            ->setParameter('ws', $wsId, UuidType::NAME)
            ->orderBy('p.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        // 3) Per-project task counts — one GROUP BY instead of shipping every task.
        $countRows = $this->em->createQueryBuilder()
            ->select('IDENTITY(t.project) AS pid')
            ->addSelect('COUNT(t.id) AS total')
            ->addSelect('SUM(CASE WHEN ts.isCompleted = false THEN 1 ELSE 0 END) AS openCount')
            ->from(Task::class, 't')
            ->join('t.status', 'ts')
            ->andWhere('t.workspace = :ws')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('t.project IS NOT NULL')
            ->setParameter('ws', $wsId, UuidType::NAME)
            ->groupBy('pid')
            ->getQuery()
            ->getArrayResult();

        /** @var array<string, array{total:int, open:int}> $counts */
        $counts = [];
        foreach ($countRows as $r) {
            $counts[$this->uuidStr($r['pid'])] = ['total' => (int) $r['total'], 'open' => (int) $r['openCount']];
        }

        // 4) Member user-IRIs per (non-archived) project.
        $memberRows = $this->em->createQueryBuilder()
            ->select('IDENTITY(m.project) AS pid', 'IDENTITY(m.user) AS uid')
            ->from(ProjectMember::class, 'm')
            ->join('m.project', 'p')
            ->andWhere('p.workspace = :ws')
            ->andWhere('p.isArchived = false')
            ->setParameter('ws', $wsId, UuidType::NAME)
            ->getQuery()
            ->getArrayResult();

        /** @var array<string, list<string>> $membersByProject */
        $membersByProject = [];
        foreach ($memberRows as $r) {
            $membersByProject[$this->uuidStr($r['pid'])][] = '/v1/users/' . $this->uuidStr($r['uid']);
        }

        $laneOut = array_map(static fn (ProjectStatus $s): array => [
            '@id' => '/v1/project_statuses/' . $s->getId()->toRfc4122(),
            'id' => $s->getId()->toRfc4122(),
            'name' => $s->getName(),
            'color' => $s->getColor(),
        ], $lanes);

        $projectOut = array_map(function (Project $p) use ($counts, $membersByProject): array {
            $pid = $p->getId()->toRfc4122();
            $customer = $p->getCustomer();
            $c = $counts[$pid] ?? ['total' => 0, 'open' => 0];

            return [
                '@id' => '/v1/projects/' . $pid,
                'id' => $pid,
                'name' => $p->getName(),
                'key' => $p->getKey(),
                'description' => $p->getDescription(),
                'color' => $p->getColor(),
                'updatedAt' => $p->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                'status' => '/v1/project_statuses/' . $p->getStatus()->getId()->toRfc4122(),
                'customer' => $customer === null ? null : [
                    'id' => $customer->getId()->toRfc4122(),
                    'name' => $customer->getName(),
                ],
                'totalTasks' => $c['total'],
                'openTasks' => $c['open'],
                'memberIris' => $membersByProject[$pid] ?? [],
            ];
        }, $projects);

        return new JsonResponse(['lanes' => $laneOut, 'projects' => $projectOut]);
    }

    /**
     * Normalise an IDENTITY() result — Doctrine hands back a UUID FK as raw
     * 16-byte binary (array-result), sometimes a canonical string, sometimes a
     * Uuid — to canonical RFC-4122 dashes form.
     */
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
