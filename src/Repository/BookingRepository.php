<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\MeetingType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booking>
 */
class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /**
     * Confirmed bookings for a meeting type that overlap the [from, to) window —
     * the busy intervals the slot engine subtracts. UTC bounds.
     *
     * @return list<Booking>
     */
    public function findConfirmedBetween(MeetingType $type, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // Stored datetimes are naive in the app default tz; align the bound
        // params so the wall-clock string comparison matches (see Booking::setStartAt).
        $tz = new \DateTimeZone(date_default_timezone_get());
        $from = $from->setTimezone($tz);
        $to = $to->setTimezone($tz);

        return $this->createQueryBuilder('b')
            ->andWhere('b.meetingType = :type')
            ->andWhere('b.status = :status')
            ->andWhere('b.startAt < :to')
            ->andWhere('b.endAt > :from')
            ->setParameter('type', $type)
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('b.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Does a confirmed booking overlap [start, end)? Used to reject a
     * double-booking at submit time.
     */
    public function hasConfirmedOverlap(MeetingType $type, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $start = $start->setTimezone($tz);
        $end = $end->setTimezone($tz);

        return null !== $this->createQueryBuilder('b')
            ->select('1')
            ->andWhere('b.meetingType = :type')
            ->andWhere('b.status = :status')
            ->andWhere('b.startAt < :end')
            ->andWhere('b.endAt > :start')
            ->setParameter('type', $type)
            ->setParameter('status', Booking::STATUS_CONFIRMED)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByCancelToken(string $token): ?Booking
    {
        return $this->findOneBy(['cancelToken' => $token]);
    }
}
