<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\Absence;
use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\AbsenceRepository;
use App\Service\Portal\PortalAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Shows a portal customer the *limited availability* of the agency staff they
 * actually work with — the people assigned to their visible tickets and the
 * owners ("Verantwortliche") of their projects. So a client knows up front that
 * "Anna is only 50 % available this week" without having to chase a reply.
 *
 * Privacy: the absence `type` (sick / child-sickness / …) is NEVER exposed —
 * only the person's name, the availability percentage, and the date window.
 * Full absences (0 %) are also not listed; this is strictly the "reduced but
 * reachable" signal.
 */
final class PortalStaffAvailabilityController
{
    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly EntityManagerInterface $em,
        private readonly AbsenceRepository $absences,
    ) {}

    #[Route(path: '/v1/portal/staff-availability', name: 'api_portal_staff_availability', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->portal->assertPortalEnabled();

        $staff = $this->relevantStaff();
        if ($staff === []) {
            return new JsonResponse(['staff' => []]);
        }

        $today = new \DateTimeImmutable('today');
        $rows = $this->absences->findLimitedAvailabilityForUsers(array_values($staff), $this->portal->workspace(), $today);

        $out = array_map(static fn (Absence $a): array => [
            'staffName' => $a->getUser()->getFullName(),
            'availabilityPercent' => $a->getAvailabilityPercent(),
            'startsOn' => $a->getStartsOn()->format('Y-m-d'),
            'endsOn' => $a->getEndsOn()->format('Y-m-d'),
        ], $rows);

        return new JsonResponse(['staff' => $out]);
    }

    /**
     * The staff users the customer is connected to: owners of their allowed
     * projects + the user-principals assigned to the non-hidden tickets in those
     * projects. Keyed by user-uuid to dedupe.
     *
     * @return array<string, User>
     */
    private function relevantStaff(): array
    {
        $projects = $this->portal->allowedProjects();
        if ($projects === []) {
            return [];
        }

        /** @var array<string, User> $staff */
        $staff = [];
        foreach ($projects as $project) {
            $owner = $project->getOwner();
            $ownerId = $owner?->getId()?->toRfc4122();
            if ($owner !== null && $ownerId !== null) {
                $staff[$ownerId] = $owner;
            }
        }

        // User-principals assigned to the customer's visible tickets.
        /** @var list<array{principalId: \Symfony\Component\Uid\Uuid}> $assignees */
        $assignees = $this->em->createQueryBuilder()
            ->select('DISTINCT ap.principalId AS principalId')
            ->from(Task::class, 't')
            ->join('t.assignedPrincipals', 'ap')
            ->andWhere('t.project IN (:projects)')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('t.isHiddenForConnectUsers = false')
            ->andWhere('ap.principalType = :ptype')
            ->setParameter('projects', $projects)
            ->setParameter('ptype', AssigneePrincipalType::User)
            ->getQuery()->getResult();

        $missingIds = [];
        foreach ($assignees as $row) {
            $uid = $row['principalId']->toRfc4122();
            if (!isset($staff[$uid])) {
                $missingIds[$uid] = $row['principalId'];
            }
        }
        if ($missingIds !== []) {
            /** @var list<User> $users */
            $users = $this->em->getRepository(User::class)->createQueryBuilder('u')
                ->where('u.id IN (:ids)')
                ->setParameter('ids', array_values($missingIds))
                ->getQuery()->getResult();
            foreach ($users as $user) {
                $id = $user->getId()?->toRfc4122();
                if ($id !== null) {
                    $staff[$id] = $user;
                }
            }
        }

        return $staff;
    }
}
