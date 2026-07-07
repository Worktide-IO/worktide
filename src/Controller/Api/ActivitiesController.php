<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\DomainEventLog;
use App\Entity\Project;
use App\Entity\Task;
use App\Repository\DomainEventLogRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Security\Voter\WorktidePermission;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Activity feed for a project or a task.
 *
 * Derived from the immutable DomainEventLog — the same data the Webhook
 * subsystem will deliver outbound later (Phase B10). Each row is one event
 * with name (e.g. "task.assigned"), payload (changeset), actor, occurredAt.
 *
 * Pagination: ?limit=N&until=<iso8601> (returns events with occurredAt <
 * until, descending). Default limit 50, max 200.
 */
final class ActivitiesController
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly Security $security,
        private readonly DomainEventLogRepository $events,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
    ) {}

    #[Route(
        path: '/v1/projects/{id}/activities',
        name: 'api_project_activities',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['GET'],
    )]
    public function projectActivities(string $id, Request $request): JsonResponse
    {
        $project = $this->projects->find(Uuid::fromString($id));
        if (!$project instanceof Project) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $project)) {
            throw new AccessDeniedHttpException();
        }
        return $this->feed('Project', $project->getId(), $request);
    }

    #[Route(
        path: '/v1/tasks/{id}/activities',
        name: 'api_task_activities',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['GET'],
    )]
    public function taskActivities(string $id, Request $request): JsonResponse
    {
        $task = $this->tasks->find(Uuid::fromString($id));
        if (!$task instanceof Task) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $task)) {
            throw new AccessDeniedHttpException();
        }
        return $this->feed('Task', $task->getId(), $request);
    }

    private function feed(string $aggregateType, Uuid $aggregateId, Request $request): JsonResponse
    {
        $limit = min(self::MAX_LIMIT, max(1, (int) $request->query->get('limit', (string) self::DEFAULT_LIMIT)));
        $untilRaw = $request->query->get('until');
        $until = null;
        if (\is_string($untilRaw) && $untilRaw !== '') {
            try {
                $until = new \DateTimeImmutable($untilRaw);
            } catch (\Exception) {
                $until = null;
            }
        }

        $qb = $this->events->createQueryBuilder('e')
            ->andWhere('e.aggregateType = :type')
            ->andWhere('e.aggregateId = :id')
            ->setParameter('type', $aggregateType)
            ->setParameter('id', $aggregateId, UuidType::NAME)
            ->orderBy('e.occurredAt', 'DESC')
            ->setMaxResults($limit);

        if ($until !== null) {
            $qb->andWhere('e.occurredAt < :until')->setParameter('until', $until);
        }

        /** @var list<DomainEventLog> $rows */
        $rows = $qb->getQuery()->getResult();

        $out = [];
        foreach ($rows as $e) {
            $out[] = [
                'id' => $e->getId()?->toRfc4122(),
                'name' => $e->getName(),
                'aggregateType' => $e->getAggregateType(),
                'aggregateId' => $e->getAggregateId()?->toRfc4122(),
                'actor' => $e->getActor() ? [
                    'id' => $e->getActor()->getId()?->toRfc4122(),
                    'email' => $e->getActor()->getEmail(),
                    'fullName' => $e->getActor()->getFullName(),
                ] : null,
                'payload' => $e->getPayload(),
                'occurredAt' => $e->getOccurredAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse([
            'items' => $out,
            'count' => count($out),
            'limit' => $limit,
        ]);
    }
}
