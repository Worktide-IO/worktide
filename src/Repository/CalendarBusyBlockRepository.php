<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarBusyBlock;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarBusyBlock>
 */
class CalendarBusyBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarBusyBlock::class);
    }

    /**
     * Busy intervals for a host overlapping [from, to). Naive-default-tz params
     * to match the stored wall-clock (see CalendarBusyBlock::setStartAt).
     *
     * @return list<CalendarBusyBlock>
     */
    public function findForOwnerBetween(User $owner, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $tz = new \DateTimeZone(date_default_timezone_get());

        return $this->createQueryBuilder('b')
            ->andWhere('b.owner = :owner')
            ->andWhere('b.startAt < :to')
            ->andWhere('b.endAt > :from')
            ->setParameter('owner', $owner)
            ->setParameter('from', $from->setTimezone($tz))
            ->setParameter('to', $to->setTimezone($tz))
            ->getQuery()
            ->getResult();
    }

    /** Drop all of a host's blocks from one source — the pre-step of a re-sync. */
    public function deleteForOwnerSource(User $owner, string $source): void
    {
        $this->createQueryBuilder('b')
            ->delete()
            ->andWhere('b.owner = :owner')
            ->andWhere('b.source = :source')
            ->setParameter('owner', $owner)
            ->setParameter('source', $source)
            ->getQuery()
            ->execute();
    }
}
