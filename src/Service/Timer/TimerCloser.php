<?php

declare(strict_types=1);

namespace App\Service\Timer;

use App\Entity\ActiveTimer;
use App\Entity\TimeEntry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finalises a running {@see ActiveTimer} into a {@see TimeEntry} and removes
 * the timer row. Shared by the stopwatch endpoints ({@see \App\Controller\Api\ActiveTimerController})
 * and the scheduled auto-stop sweep ({@see \App\Command\AutoStopTimersCommand})
 * so both produce identical entries.
 *
 * The caller owns the surrounding flush — the auto-stop command batches many
 * timers into one flush, and the controller flushes once per request.
 */
final class TimerCloser
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function close(ActiveTimer $timer, \DateTimeImmutable $endsAt): TimeEntry
    {
        $duration = (int) round($timer->elapsedSeconds($endsAt) / 60);
        $entry = (new TimeEntry())
            ->setUser($timer->getUser())
            ->setWorkspace($timer->getWorkspace())
            ->setProject($timer->getProject())
            ->setTask($timer->getTask())
            ->setTypeOfWork($timer->getTypeOfWork())
            ->setStartsAt($timer->getStartedAt())
            ->setEndsAt($endsAt)
            ->setDurationMinutes($duration)
            ->setIsBillable($timer->isBillable())
            ->setNote($timer->getDescription());
        $this->em->persist($entry);
        $this->em->remove($timer);
        return $entry;
    }
}
