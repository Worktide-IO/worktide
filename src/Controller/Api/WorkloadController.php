<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\UserCapacity;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
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
 * Per-user-per-day workload comparison:
 *   capacityMinutes (from UserCapacity, by weekday)
 *   - absenceMinutes (Absence + WorkspaceAbsence overlay)
 *   = availableMinutes
 *   vs. trackedMinutes (sum of TimeEntries that day)
 *   → utilization% = tracked / available
 *
 *   GET /v1/reports/workload
 *     ?from=ISO (required)
 *     &to=ISO   (required, exclusive)
 *     &userIds=uuid,uuid,...   (optional CSV; defaults to workspace members)
 *
 * Returns a daily matrix per user. Caller's workspace is resolved from
 * X-Workspace-Id or first membership.
 */
final class WorkloadController
{
    private const MAX_RANGE_DAYS = 92;
    private const MINUTES_PER_HALF_DAY = 240;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/reports/workload',
        name: 'api_report_workload',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $from = $this->parseRequired($request, 'from')->setTime(0, 0);
        $to = $this->parseRequired($request, 'to')->setTime(0, 0);
        if ($to <= $from) {
            throw new BadRequestHttpException('to must be after from.');
        }
        if ($from->diff($to)->days > self::MAX_RANGE_DAYS) {
            throw new BadRequestHttpException('Date range capped at ' . self::MAX_RANGE_DAYS . ' days.');
        }

        $workspace = $this->resolveWorkspace($request, $user);
        if ($workspace === null) {
            throw new AccessDeniedHttpException('No workspace context.');
        }

        $userIds = $this->extractUserIds($request);
        $users = $this->loadUsers($workspace, $userIds);
        if ($users === []) {
            return new JsonResponse(['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'users' => []]);
        }

        $capacities = $this->loadCapacities($users);
        $trackedByUserDay = $this->loadTrackedMinutes($workspace, $users, $from, $to);
        $absencesByUserDay = $this->loadAbsences($workspace, $users, $from, $to);
        $workspaceOff = $this->loadWorkspaceAbsences($workspace, $from, $to);

        $rows = [];
        foreach ($users as $u) {
            $uid = $u->getId()?->toRfc4122() ?? '';
            $cap = $capacities[$uid] ?? null;
            $days = [];
            $cursor = $from;
            while ($cursor < $to) {
                $key = $cursor->format('Y-m-d');
                $weekdayCapacity = $cap ? $this->capacityForWeekday($cap, (int) $cursor->format('N')) : 0;
                $absence = $absencesByUserDay[$uid][$key] ?? 0;
                if (isset($workspaceOff[$key])) {
                    $absence = max($absence, $weekdayCapacity); // whole day off
                }
                $available = max(0, $weekdayCapacity - $absence);
                $tracked = $trackedByUserDay[$uid][$key] ?? 0;
                $days[] = [
                    'date' => $key,
                    'capacityMinutes' => $weekdayCapacity,
                    'absenceMinutes' => $absence,
                    'availableMinutes' => $available,
                    'trackedMinutes' => $tracked,
                    'utilization' => $available > 0 ? round($tracked / $available, 2) : null,
                ];
                $cursor = $cursor->modify('+1 day');
            }
            $rows[] = [
                'userId' => $uid,
                'fullName' => $u->getFullName(),
                'days' => $days,
            ];
        }

        return new JsonResponse([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'users' => $rows,
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
        $hdr = $request->headers->get('X-Workspace-Id');
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

    /** @return list<string> */
    private function extractUserIds(Request $request): array
    {
        $raw = $request->query->get('userIds');
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    /**
     * @param list<string> $userIds
     * @return list<User>
     */
    private function loadUsers(Workspace $workspace, array $userIds): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->innerJoin(WorkspaceMember::class, 'wm', 'WITH', 'wm.user = u AND wm.workspace = :ws')
            ->setParameter('ws', $workspace->getId(), UuidType::NAME);
        if ($userIds !== []) {
            $uuids = array_map(static fn (string $s) => Uuid::fromString($s), $userIds);
            $qb->andWhere('u.id IN (:ids)')->setParameter('ids', $uuids);
        }
        return $qb->getQuery()->getResult();
    }

    /**
     * @param list<User> $users
     * @return array<string, UserCapacity>
     */
    private function loadCapacities(array $users): array
    {
        $caps = $this->em->getRepository(UserCapacity::class)->findBy(['user' => $users]);
        $out = [];
        foreach ($caps as $cap) {
            $id = $cap->getUser()->getId()?->toRfc4122() ?? '';
            $out[$id] = $cap;
        }
        return $out;
    }

    private function capacityForWeekday(UserCapacity $cap, int $isoWeekday): int
    {
        return match ($isoWeekday) {
            1 => $cap->getMonMinutes(),
            2 => $cap->getTueMinutes(),
            3 => $cap->getWedMinutes(),
            4 => $cap->getThuMinutes(),
            5 => $cap->getFriMinutes(),
            6 => $cap->getSatMinutes(),
            7 => $cap->getSunMinutes(),
            default => 0,
        };
    }

    /**
     * @param list<User> $users
     * @return array<string, array<string, int>>  user-uuid → date Y-m-d → minutes
     */
    private function loadTrackedMinutes(Workspace $workspace, array $users, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(te.user) AS user_id')
            ->addSelect('SUBSTRING(te.startsAt, 1, 10) AS day')
            ->addSelect('SUM(te.durationMinutes) AS minutes')
            ->from('App\Entity\TimeEntry', 'te')
            ->where('te.workspace = :ws')
            ->andWhere('te.user IN (:users)')
            ->andWhere('te.startsAt >= :from AND te.startsAt < :to')
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
            ->setParameter('users', $users)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('user_id, day')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $r) {
            $userId = $this->binToUuidString($r['user_id']);
            if ($userId === null) {
                continue;
            }
            $out[$userId][$r['day']] = (int) $r['minutes'];
        }
        return $out;
    }

    private function binToUuidString(mixed $raw): ?string
    {
        if ($raw === null) return null;
        if ($raw instanceof Uuid) return $raw->toRfc4122();
        if (\is_string($raw)) {
            if (\strlen($raw) === 16) {
                $hex = bin2hex($raw);
                return sprintf(
                    '%s-%s-%s-%s-%s',
                    substr($hex, 0, 8), substr($hex, 8, 4),
                    substr($hex, 12, 4), substr($hex, 16, 4),
                    substr($hex, 20, 12),
                );
            }
            if (\strlen($raw) === 36) return $raw;
        }
        return null;
    }

    /**
     * @param list<User> $users
     * @return array<string, array<string, int>>
     */
    private function loadAbsences(Workspace $workspace, array $users, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<\App\Entity\Absence> $rows */
        $rows = $this->em->getRepository(\App\Entity\Absence::class)->createQueryBuilder('a')
            ->where('a.workspace = :ws')
            ->andWhere('a.user IN (:users)')
            ->andWhere('a.startsOn < :to AND a.endsOn >= :from')
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
            ->setParameter('users', $users)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $absence) {
            $uid = $absence->getUser()->getId()?->toRfc4122() ?? '';
            $cur = max($absence->getStartsOn(), $from);
            $end = min($absence->getEndsOn(), $to->modify('-1 day'));
            while ($cur <= $end) {
                $key = $cur->format('Y-m-d');
                $half = (
                    ($absence->isHalfDayOnStart() && $cur->format('Y-m-d') === $absence->getStartsOn()->format('Y-m-d'))
                    || ($absence->isHalfDayOnEnd() && $cur->format('Y-m-d') === $absence->getEndsOn()->format('Y-m-d'))
                );
                $out[$uid][$key] = ($out[$uid][$key] ?? 0) + ($half ? self::MINUTES_PER_HALF_DAY : 480);
                $cur = $cur->modify('+1 day');
            }
        }
        return $out;
    }

    /** @return array<string, true> */
    private function loadWorkspaceAbsences(Workspace $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<\App\Entity\WorkspaceAbsence> $rows */
        $rows = $this->em->getRepository(\App\Entity\WorkspaceAbsence::class)->createQueryBuilder('wa')
            ->where('wa.workspace = :ws')
            ->andWhere('wa.startsOn < :to AND wa.endsOn >= :from')
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $abs) {
            $cur = max($abs->getStartsOn(), $from);
            $end = min($abs->getEndsOn(), $to->modify('-1 day'));
            while ($cur <= $end) {
                $out[$cur->format('Y-m-d')] = true;
                $cur = $cur->modify('+1 day');
            }
        }
        return $out;
    }
}
