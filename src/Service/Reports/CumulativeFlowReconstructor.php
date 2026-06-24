<?php

declare(strict_types=1);

namespace App\Service\Reports;

/**
 * Reconstructs a Cumulative Flow Diagram series — the per-day count of tasks
 * in each status — by replaying status-change history.
 *
 * Current task state only tells us the status *now*. To know the status on a
 * past day we replay the `status` change events ({@see \App\EventSubscriber\
 * DomainEventEmitterSubscriber} records each Doctrine update as
 * `payload.status = {from, to}`): the earliest event's `from` is the status a
 * task was created in, every later event's `to` is the status from that moment
 * on. A task with no recorded status change simply held its current status for
 * its whole life.
 *
 * Pure decision logic over plain arrays — no Doctrine, no clock — so the
 * controller stays thin and the replay is unit-testable without a database.
 *
 * Caveats (data-era, mirrored in the endpoint docblock): tasks deleted before
 * "now" are not represented, and status changes that predate the event log are
 * invisible (such a task shows its earliest *known* status).
 */
final class CumulativeFlowReconstructor
{
    /**
     * @param list<array{id: string, createdAt: \DateTimeImmutable, currentStatusId: string}> $tasks
     * @param list<array{taskId: string, from: string, to: string, occurredAt: \DateTimeImmutable}> $events
     *
     * @return list<array{date: string, counts: array<string, int>}>
     */
    public function build(array $tasks, array $events, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // Group status changes per task, oldest first.
        $eventsByTask = [];
        foreach ($events as $e) {
            $eventsByTask[$e['taskId']][] = $e;
        }
        foreach ($eventsByTask as &$list) {
            usort($list, static fn (array $a, array $b): int => $a['occurredAt'] <=> $b['occurredAt']);
        }
        unset($list);

        // Build each task's status timeline as ordered [start, statusId] segments.
        $timelines = [];
        foreach ($tasks as $task) {
            $taskEvents = $eventsByTask[$task['id']] ?? [];
            if ($taskEvents === []) {
                $segments = [[$task['createdAt'], $task['currentStatusId']]];
            } else {
                // First event's `from` is the status the task started in.
                $segments = [[$task['createdAt'], $taskEvents[0]['from']]];
                foreach ($taskEvents as $e) {
                    $segments[] = [$e['occurredAt'], $e['to']];
                }
            }
            $timelines[] = ['createdAt' => $task['createdAt'], 'segments' => $segments];
        }

        $series = [];
        $cursor = $from->setTime(0, 0);
        $end = $to->setTime(23, 59, 59);
        while ($cursor <= $end) {
            $cutoff = $cursor->setTime(23, 59, 59);
            $counts = [];
            foreach ($timelines as $tl) {
                if ($tl['createdAt'] > $cutoff) {
                    continue; // not created yet on this day
                }
                $statusId = $this->statusAt($tl['segments'], $cutoff);
                $counts[$statusId] = ($counts[$statusId] ?? 0) + 1;
            }
            $series[] = [
                'date' => $cursor->format('Y-m-d'),
                'counts' => $counts,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $series;
    }

    /**
     * The status of the latest segment that had started by $cutoff. The first
     * segment starts at createdAt, which the caller guarantees is <= $cutoff,
     * so a match always exists.
     *
     * @param list<array{0: \DateTimeImmutable, 1: string}> $segments
     */
    private function statusAt(array $segments, \DateTimeImmutable $cutoff): string
    {
        $statusId = $segments[0][1];
        foreach ($segments as [$start, $id]) {
            if ($start <= $cutoff) {
                $statusId = $id;
            } else {
                break;
            }
        }
        return $statusId;
    }
}
