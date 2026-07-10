<?php

declare(strict_types=1);

namespace App\Service\Booking;

use App\Entity\Booking;
use App\Entity\MeetingType;
use App\Repository\BookingRepository;
use App\Repository\CalendarBusyBlockRepository;

/**
 * Computes bookable slots for a {@see MeetingType}.
 *
 * Availability windows are weekly rules expressed in the host timezone; we
 * compute in that local zone and return UTC instants (the store-UTC /
 * compute-in-local idiom from RunTaskSchedulesCommand). A candidate slot
 * [start, start+duration) is offered when it sits inside a window, is at least
 * `minNoticeMinutes` out, at most `maxAdvanceDays` out, and — once expanded by
 * the meeting's before/after buffers — overlaps no confirmed booking.
 */
final class BookingSlotService
{
    /** Hard cap so a wide range / tiny duration can't produce an unbounded list. */
    private const MAX_SLOTS = 500;

    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly CalendarBusyBlockRepository $busyBlocks,
    ) {}

    /**
     * Available slot starts (UTC), ascending, within [from, to).
     *
     * @return list<\DateTimeImmutable>
     */
    public function availableSlots(MeetingType $type, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $tz = new \DateTimeZone($type->getTimezone());
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);

        $earliest = $now->modify(sprintf('+%d minutes', $type->getMinNoticeMinutes()));
        $latest = $now->modify(sprintf('+%d days', $type->getMaxAdvanceDays()));

        $rangeStart = $from < $now ? $now : $from;
        $rangeEnd = $to > $latest ? $latest : $to;
        if ($rangeEnd <= $rangeStart) {
            return [];
        }

        // Group availability windows by ISO weekday (1=Mon..7=Sun).
        $windowsByWeekday = [];
        foreach ($type->getAvailability() as $w) {
            $weekday = (int) ($w['weekday'] ?? 0);
            if ($weekday < 1 || $weekday > 7 || !$this->isTime($w['start'] ?? null) || !$this->isTime($w['end'] ?? null)) {
                continue;
            }
            $windowsByWeekday[$weekday][] = [$w['start'], $w['end']];
        }
        if ($windowsByWeekday === []) {
            return [];
        }

        // Busy intervals with a day of slack either side: confirmed bookings +
        // the host's imported external-calendar blocks (free/busy sync).
        $slackFrom = $rangeStart->modify('-1 day');
        $slackTo = $rangeEnd->modify('+1 day');
        $busy = [];
        foreach ($this->bookings->findConfirmedBetween($type, $slackFrom, $slackTo) as $b) {
            $busy[] = [$b->getStartAt(), $b->getEndAt()];
        }
        $host = $type->getHost();
        if ($host !== null) {
            foreach ($this->busyBlocks->findForOwnerBetween($host, $slackFrom, $slackTo) as $bb) {
                $busy[] = [$bb->getStartAt(), $bb->getEndAt()];
            }
        }

        $duration = $type->getDurationMinutes();
        $bufBefore = $type->getBufferBeforeMinutes();
        $bufAfter = $type->getBufferAfterMinutes();

        $slots = [];
        // Iterate calendar days in the host tz.
        $day = new \DateTimeImmutable($rangeStart->setTimezone($tz)->format('Y-m-d 00:00:00'), $tz);
        $guard = $type->getMaxAdvanceDays() + 2;
        while ($guard-- > 0 && $day->setTimezone($utc) < $rangeEnd) {
            $weekday = (int) $day->format('N');
            foreach ($windowsByWeekday[$weekday] ?? [] as [$startHm, $endHm]) {
                [$sh, $sm] = array_map('intval', explode(':', $startHm));
                [$eh, $em] = array_map('intval', explode(':', $endHm));
                $winStart = $day->setTime($sh, $sm);
                $winEnd = $day->setTime($eh, $em);

                $slot = $winStart;
                while ($slot->modify(sprintf('+%d minutes', $duration)) <= $winEnd) {
                    $sUtc = $slot->setTimezone($utc);
                    $eUtc = $sUtc->modify(sprintf('+%d minutes', $duration));
                    if (
                        $sUtc >= $earliest
                        && $sUtc >= $rangeStart
                        && $sUtc < $rangeEnd
                        && !$this->isBlocked($sUtc, $eUtc, $busy, $bufBefore, $bufAfter)
                    ) {
                        $slots[] = $sUtc;
                        if (\count($slots) >= self::MAX_SLOTS) {
                            return $slots;
                        }
                    }
                    $slot = $slot->modify(sprintf('+%d minutes', $duration));
                }
            }
            $day = $day->modify('+1 day');
        }

        return $slots;
    }

    /**
     * Is `$startUtc` a genuinely offerable slot right now? Used at submit time to
     * reject a stale / forged / just-taken slot before creating the booking.
     */
    public function isBookable(MeetingType $type, \DateTimeImmutable $startUtc): bool
    {
        $utc = new \DateTimeZone('UTC');
        $dayStart = new \DateTimeImmutable($startUtc->setTimezone($utc)->format('Y-m-d 00:00:00'), $utc);
        $dayEnd = $dayStart->modify('+2 days');
        foreach ($this->availableSlots($type, $dayStart, $dayEnd) as $slot) {
            if ($slot->getTimestamp() === $startUtc->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $busy
     */
    private function isBlocked(\DateTimeImmutable $start, \DateTimeImmutable $end, array $busy, int $bufBefore, int $bufAfter): bool
    {
        $expStart = $start->modify(sprintf('-%d minutes', $bufBefore));
        $expEnd = $end->modify(sprintf('+%d minutes', $bufAfter));
        foreach ($busy as [$bs, $be]) {
            if ($expStart < $be && $expEnd > $bs) {
                return true;
            }
        }

        return false;
    }

    private function isTime(mixed $value): bool
    {
        return \is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
