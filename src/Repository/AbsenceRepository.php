<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Absence;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Absence>
 */
class AbsenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Absence::class);
    }

    /**
     * A user's (non-deleted) absences in `$workspace` that overlap [from, to] —
     * used by the booking slot engine to blank out days the host is away.
     *
     * @return list<Absence>
     */
    public function findForUserBetween(User $user, Workspace $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->andWhere('a.workspace = :ws')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere('a.startsOn <= :to')
            ->andWhere('a.endsOn >= :from')
            ->setParameter('user', $user)
            ->setParameter('ws', $workspace)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }

    /**
     * Ongoing / upcoming limited-availability absences (availabilityPercent > 0)
     * for a set of users — surfaced in the customer portal for staff involved in
     * the customer's tickets/projects. The medical `type` is intentionally NOT
     * exposed by callers; only the availability window + percentage.
     *
     * @param list<User> $users
     * @return list<Absence>
     */
    public function findLimitedAvailabilityForUsers(array $users, Workspace $workspace, \DateTimeImmutable $onOrAfter): array
    {
        if ($users === []) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->andWhere('a.user IN (:users)')
            ->andWhere('a.workspace = :ws')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere('a.availabilityPercent > 0')
            ->andWhere('a.endsOn >= :onOrAfter')
            ->setParameter('users', $users)
            ->setParameter('ws', $workspace)
            ->setParameter('onOrAfter', $onOrAfter)
            ->orderBy('a.startsOn', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
