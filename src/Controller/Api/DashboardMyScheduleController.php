<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dashboard read-model for the "Meine Planung" widget — the caller's next open
 * assigned tickets, planned tickets first in scheduled order (startOn asc), then
 * the rest by priority. Reflects the AI work plan (see SchedulePlanController).
 *
 *   GET /v1/dashboard/my-schedule?limit=7   (X-Workspace-Id honoured)
 */
final class DashboardMyScheduleController
{
    use ResolvesWorkspaceMembership;

    private const MAX_LIMIT = 25;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(path: '/v1/dashboard/my-schedule', name: 'api_dashboard_my_schedule', methods: ['GET'])]
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

        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', '7')));

        /** @var list<Task> $tasks */
        $tasks = $this->em->createQueryBuilder()
            ->select('t', 'p')
            // "Planned" = scheduled from today onward; stale/imported past
            // start dates (or none) sort after, by priority.
            ->addSelect('CASE WHEN t.startOn IS NULL OR t.startOn < :todayStart THEN 1 ELSE 0 END AS HIDDEN unplanned')
            ->from(Task::class, 't')
            ->join('t.status', 's')
            ->leftJoin('t.project', 'p')
            ->innerJoin('t.assignedPrincipals', 'ap', 'WITH', 'ap.principalType = :ptype AND ap.principalId = :uid')
            ->andWhere('t.workspace = :ws')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('s.isCompleted = false')
            ->setParameter('ptype', AssigneePrincipalType::User)
            ->setParameter('uid', $user->getId(), UuidType::NAME)
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
            ->setParameter('todayStart', new \DateTimeImmutable('today'))
            ->orderBy('unplanned', 'ASC')
            ->addOrderBy('t.startOn', 'ASC')
            ->addOrderBy('t.priorityScore', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return new JsonResponse(['tickets' => array_map($this->dto(...), $tasks)]);
    }

    /** @return array<string, mixed> */
    private function dto(Task $t): array
    {
        $project = $t->getProject();

        return [
            'id' => $t->getId()?->toRfc4122(),
            'identifier' => $t->getIdentifier(),
            'title' => $t->getTitle(),
            'priority' => $t->getPriority()->value,
            'priorityScore' => $t->getPriorityScore(),
            'estimatedMinutes' => $t->getEstimatedMinutes(),
            'requiredDiscipline' => $t->getRequiredDiscipline()?->value,
            'dueOn' => $t->getDueOn()?->format('Y-m-d'),
            'startOn' => $t->getStartOn()?->format(\DateTimeInterface::ATOM),
            'scheduledEnd' => $t->getScheduledEnd()?->format(\DateTimeInterface::ATOM),
            'project' => $project === null ? null : [
                'id' => $project->getId()?->toRfc4122(),
                'name' => $project->getName(),
            ],
        ];
    }
}
