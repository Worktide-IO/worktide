<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TypeOfWork;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Time aggregation endpoint.
 *
 *   GET /v1/reports/time
 *     ?from=ISO              (required)
 *     &to=ISO                (required)
 *     &groupBy=user|project|task|typeOfWork   (default: user)
 *     &workspace=<uuid>      (defaults to caller's first workspace)
 *     &user=<uuid>           (filter to entries by this user)
 *     &project=<uuid>        (filter to entries in this project)
 *     &billable=true|false   (filter by billable flag)
 *
 * Returns:
 *   {
 *     "from": iso8601, "to": iso8601, "groupBy": "...",
 *     "totalMinutes": int, "billableMinutes": int, "billedMinutes": int,
 *     "groups": [
 *       { "key": uuid, "label": "...", "minutes": int,
 *         "billableMinutes": int, "billedMinutes": int }
 *     ]
 *   }
 *
 * Date range is capped at 366 days to keep aggregation cheap.
 */
final class TimeReportController
{
    private const MAX_RANGE_DAYS = 366;
    private const VALID_GROUPS = ['user', 'project', 'task', 'typeOfWork'];

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/reports/time',
        name: 'api_report_time',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $from = $this->parseRequired($request, 'from');
        $to = $this->parseRequired($request, 'to');
        if ($to <= $from) {
            throw new BadRequestHttpException('to must be after from.');
        }
        if ($from->diff($to)->days > self::MAX_RANGE_DAYS) {
            throw new BadRequestHttpException('Date range capped at ' . self::MAX_RANGE_DAYS . ' days.');
        }

        $groupBy = (string) ($request->query->get('groupBy') ?? 'user');
        if (!\in_array($groupBy, self::VALID_GROUPS, true)) {
            throw new BadRequestHttpException('groupBy must be one of: ' . implode(', ', self::VALID_GROUPS));
        }

        $workspace = $this->resolveWorkspace($request, $user);
        if ($workspace === null) {
            throw new AccessDeniedHttpException('No workspace context.');
        }

        $qb = $this->em->createQueryBuilder()
            ->from('App\Entity\TimeEntry', 'te')
            ->andWhere('te.workspace = :ws')
            ->andWhere('te.startsAt >= :from')
            ->andWhere('te.startsAt < :to')
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if (($userParam = $request->query->get('user')) !== null && \is_string($userParam) && $userParam !== '') {
            $qb->andWhere('te.user = :userF')
               ->setParameter('userF', Uuid::fromString($userParam), UuidType::NAME);
        }
        if (($projectParam = $request->query->get('project')) !== null && \is_string($projectParam) && $projectParam !== '') {
            $qb->andWhere('te.project = :projectF')
               ->setParameter('projectF', Uuid::fromString($projectParam), UuidType::NAME);
        }
        $billable = $request->query->get('billable');
        if ($billable !== null) {
            $qb->andWhere('te.isBillable = :billable')
               ->setParameter('billable', filter_var($billable, FILTER_VALIDATE_BOOL));
        }

        $groupField = match ($groupBy) {
            'user' => 'te.user',
            'project' => 'te.project',
            'task' => 'te.task',
            'typeOfWork' => 'te.typeOfWork',
        };

        // Aggregate by the chosen field
        $rows = (clone $qb)
            ->select(sprintf('IDENTITY(%s) AS key_id', $groupField))
            ->addSelect('SUM(te.durationMinutes) AS minutes')
            ->addSelect('SUM(CASE WHEN te.isBillable = true THEN te.durationMinutes ELSE 0 END) AS billableMinutes')
            ->addSelect('SUM(CASE WHEN te.isBilled   = true THEN te.durationMinutes ELSE 0 END) AS billedMinutes')
            ->groupBy('key_id')
            ->getQuery()
            ->getArrayResult();

        $totals = (clone $qb)
            ->select('SUM(te.durationMinutes) AS minutes')
            ->addSelect('SUM(CASE WHEN te.isBillable = true THEN te.durationMinutes ELSE 0 END) AS billableMinutes')
            ->addSelect('SUM(CASE WHEN te.isBilled   = true THEN te.durationMinutes ELSE 0 END) AS billedMinutes')
            ->getQuery()
            ->getSingleResult();

        // Doctrine returns IDENTITY() of a UUID FK as a raw 16-byte binary
        // string. Convert to canonical RFC-4122 dashes-form so the JSON
        // serialiser doesn't choke on non-UTF-8 bytes.
        $normalised = [];
        foreach ($rows as $i => $row) {
            $normalised[$i] = $this->binToUuidString($row['key_id']);
        }
        $labels = $this->labelsFor($groupBy, array_values(array_filter($normalised)));

        $groups = [];
        foreach ($rows as $i => $row) {
            $key = $normalised[$i];
            $label = $key === null ? '(unassigned)' : ($labels[$key] ?? $key);
            $groups[] = [
                'key' => $key,
                'label' => $label,
                'minutes' => (int) $row['minutes'],
                'billableMinutes' => (int) $row['billableMinutes'],
                'billedMinutes' => (int) $row['billedMinutes'],
            ];
        }
        usort($groups, static fn ($a, $b) => $b['minutes'] <=> $a['minutes']);

        return new JsonResponse([
            'from' => $from->format(\DateTimeInterface::ATOM),
            'to' => $to->format(\DateTimeInterface::ATOM),
            'groupBy' => $groupBy,
            'totalMinutes' => (int) ($totals['minutes'] ?? 0),
            'billableMinutes' => (int) ($totals['billableMinutes'] ?? 0),
            'billedMinutes' => (int) ($totals['billedMinutes'] ?? 0),
            'groups' => $groups,
        ]);
    }

    private function parseRequired(Request $request, string $key): \DateTimeImmutable
    {
        $raw = $request->query->get($key);
        if (!\is_string($raw) || $raw === '') {
            throw new BadRequestHttpException(sprintf('Query parameter %s is required.', $key));
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new BadRequestHttpException(sprintf('%s must be a valid ISO 8601 date.', $key));
        }
    }

    private function resolveWorkspace(Request $request, User $user): ?Workspace
    {
        $hdr = $request->headers->get('X-Workspace-Id') ?? $request->query->get('workspace');
        if (\is_string($hdr) && $hdr !== '') {
            try {
                return $this->em->find(Workspace::class, Uuid::fromString($hdr));
            } catch (\InvalidArgumentException) {
                return null;
            }
        }
        $membership = $this->em->getRepository(WorkspaceMember::class)->findOneBy(['user' => $user]);
        return $membership?->getWorkspace();
    }

    /**
     * Turn whatever Doctrine handed us (raw 16-byte binary, UUID string, or
     * Uuid object) into the canonical RFC-4122 dashes form. Returns null for
     * the "unassigned" rows.
     */
    private function binToUuidString(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if ($raw instanceof Uuid) {
            return $raw->toRfc4122();
        }
        if (\is_string($raw)) {
            if (\strlen($raw) === 16) {
                $hex = bin2hex($raw);
                return sprintf('%s-%s-%s-%s-%s',
                    substr($hex, 0, 8),
                    substr($hex, 8, 4),
                    substr($hex, 12, 4),
                    substr($hex, 16, 4),
                    substr($hex, 20, 12),
                );
            }
            if (\strlen($raw) === 36) {
                return $raw;
            }
        }
        return null;
    }

    /**
     * @param list<string> $ids
     * @return array<string, string>
     */
    private function labelsFor(string $groupBy, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $class = match ($groupBy) {
            'user' => User::class,
            'project' => Project::class,
            'task' => Task::class,
            'typeOfWork' => TypeOfWork::class,
        };
        $uuids = array_map(static fn (string $s) => Uuid::fromString($s), $ids);

        $entities = $this->em->createQueryBuilder()
            ->select('e')
            ->from($class, 'e')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $uuids)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($entities as $entity) {
            $id = $entity->getId()?->toRfc4122() ?? '';
            $out[$id] = match (true) {
                $entity instanceof User => $entity->getFullName(),
                $entity instanceof Project => sprintf('%s — %s', $entity->getKey(), $entity->getName()),
                $entity instanceof Task => sprintf('%s — %s', $entity->getIdentifier(), $entity->getTitle()),
                $entity instanceof TypeOfWork => $entity->getName(),
                default => $id,
            };
        }
        return $out;
    }
}
