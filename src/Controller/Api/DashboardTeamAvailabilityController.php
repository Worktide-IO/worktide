<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Entity\Absence;
use App\Entity\User;
use App\Entity\UserCapacity;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dashboard read-model for "Mitarbeiter-Verfügbarkeit" (team availability widget).
 *
 * Shows team members of the current workspace who have limited availability —
 * absences (from ANY of their workspaces) + reduced UserCapacity. The key
 * feature is **cross-workspace aggregation**: if Alice is a member of workspaces
 * A and B, and records a vacation in workspace B, workspace A's dashboard also
 * shows that Alice is unavailable during that period.
 *
 * Tenant-Isolation: only availability metadata (who, when, how much, why)
 * crosses the workspace boundary — never workspace-specific content like tasks,
 * projects, or documents.
 *
 *   GET /v1/dashboard/team-availability
 *     &workspace=<uuid>   (defaults to caller's first membership; header honoured)
 *     &days=<int>         (look-ahead window, default 30, max 90)
 *
 * Response:
 *   {
 *     "members": [
 *       {
 *         "user": { "id", "firstName", "lastName" },
 *         "absences": [
 *           { "startsOn", "endsOn", "type", "availabilityPercent",
 *             "description", "sourceWorkspace": { "id", "name" } }
 *         ],
 *         "capacityMinutes": { "mon": 480, "tue": 480, ..., "sun": 0 }
 *       }
 *     ]
 *   }
 */
final class DashboardTeamAvailabilityController
{
    use ResolvesWorkspaceMembership;

    private const CAP = 200;
    private const MAX_DAYS = 90;
    private const DEFAULT_DAYS = 30;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(path: '/v1/dashboard/team-availability', name: 'api_dashboard_team_availability', methods: ['GET'])]
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

        $days = (int) ($request->query->get('days') ?? self::DEFAULT_DAYS);
        $days = max(1, min($days, self::MAX_DAYS));

        $now = new \DateTimeImmutable('now');
        $windowEnd = $now->modify("+{$days} days");

        // 1) All active members of the current workspace.
        /** @var list<WorkspaceMember> $memberships */
        $memberships = $this->em->createQueryBuilder()
            ->select('wm', 'u')
            ->from(WorkspaceMember::class, 'wm')
            ->join('wm.user', 'u')
            ->andWhere('wm.workspace = :ws')
            ->andWhere('wm.isActive = true')
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
            ->getQuery()
            ->getResult();

        if ($memberships === []) {
            return new JsonResponse(['members' => []]);
        }

        /** @var list<User> $memberUsers */
        $memberUsers = [];
        /** @var array<string, string> $userWorkspaceNames  user-uuid → workspace name (for sourceWorkspace) */
        $userWorkspaceNames = [];
        foreach ($memberships as $wm) {
            $u = $wm->getUser();
            $memberUsers[] = $u;
            $uid = $u->getId()?->toRfc4122() ?? '';
            // Store the current workspace name as a fallback (for members without cross-workspace absences).
            if (!isset($userWorkspaceNames[$uid])) {
                $userWorkspaceNames[$uid] = $workspace->getName();
            }
        }

        // 2) Cross-workspace absences: find absences for these users across ALL
        //    their workspaces (not just the current one). This is the key
        //    cross-workspace aggregation — an absence recorded in workspace B
        //    is relevant in workspace A too.
        /** @var list<Absence> $absences */
        $absences = $this->em->createQueryBuilder()
            ->select('a', 'u', 'ws')
            ->from(Absence::class, 'a')
            ->join('a.user', 'u')
            ->join('a.workspace', 'ws')
            ->andWhere('a.user IN (:users)')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere('a.startsOn <= :windowEnd')
            ->andWhere('a.endsOn >= :now')
            ->setParameter('users', $memberUsers)
            ->setParameter('now', $now)
            ->setParameter('windowEnd', $windowEnd)
            ->orderBy('a.startsOn', 'ASC')
            ->getQuery()
            ->getResult();

        // Group absences by user-uuid.
        /** @var array<string, list<Absence>> $absencesByUser */
        $absencesByUser = [];
        foreach ($absences as $absence) {
            $uid = $absence->getUser()->getId()?->toRfc4122() ?? '';
            $absencesByUser[$uid][] = $absence;
        }

        // 3) UserCapacity (global, per-user — no cross-workspace issue).
        $capacityMap = [];
        if ($memberUsers !== []) {
            /** @var list<UserCapacity> $capacities */
            $capacities = $this->em->createQueryBuilder()
                ->select('uc')
                ->from(UserCapacity::class, 'uc')
                ->andWhere('uc.user IN (:users)')
                ->setParameter('users', $memberUsers)
                ->getQuery()
                ->getResult();

            foreach ($capacities as $uc) {
                $capacityMap[$uc->getUser()->getId()?->toRfc4122() ?? ''] = $uc;
            }
        }

        // 4) Assemble response — only members with at least one absence or
        //    non-default capacity are included (the widget shows "limited
        //    availability" members). Users with full 480-min weekdays and
        //    no absences are omitted to keep the widget focused.
        $members = [];
        foreach ($memberUsers as $u) {
            $uid = $u->getId()?->toRfc4122() ?? '';
            $userAbsences = $absencesByUser[$uid] ?? [];
            $cap = $capacityMap[$uid] ?? null;

            // Skip members with no absences and full standard capacity.
            $hasAbsences = $userAbsences !== [];
            $hasReducedCapacity = $cap !== null && $cap->getWeeklyMinutes() < 2400; // < 5×480
            if (!$hasAbsences && !$hasReducedCapacity) {
                continue;
            }

            $absenceData = array_map(static fn (Absence $a): array => [
                'startsOn' => $a->getStartsOn()->format('Y-m-d'),
                'endsOn' => $a->getEndsOn()->format('Y-m-d'),
                'type' => $a->getType(),
                'availabilityPercent' => $a->getAvailabilityPercent(),
                'description' => $a->getDescription(),
                'sourceWorkspace' => [
                    'id' => $a->getWorkspace()->getId()?->toRfc4122() ?? '',
                    'name' => $a->getWorkspace()->getName(),
                ],
            ], $userAbsences);

            $members[] = [
                'user' => [
                    'id' => $uid,
                    'firstName' => $u->getFirstName(),
                    'lastName' => $u->getLastName(),
                ],
                'absences' => $absenceData,
                'capacityMinutes' => $cap !== null ? [
                    'mon' => $cap->getMonMinutes(),
                    'tue' => $cap->getTueMinutes(),
                    'wed' => $cap->getWedMinutes(),
                    'thu' => $cap->getThuMinutes(),
                    'fri' => $cap->getFriMinutes(),
                    'sat' => $cap->getSatMinutes(),
                    'sun' => $cap->getSunMinutes(),
                ] : null,
            ];
        }

        // Cap the result set.
        $capped = \count($members) > self::CAP;
        if ($capped) {
            $members = \array_slice($members, 0, self::CAP);
        }

        return new JsonResponse([
            'members' => $members,
            'capped' => $capped,
        ]);
    }
}
