<?php

declare(strict_types=1);

namespace App\Service\Reports;

/**
 * Computes cycle time per completed task and the distribution percentiles.
 *
 * Cycle time here is "time from when work started to done", not lead time
 * (created → done). The start of work is the earliest recorded status-change
 * event for the task — i.e. the moment it first left the status it was created
 * in ({@see \App\EventSubscriber\DomainEventEmitterSubscriber} records each
 * status change as `payload.status = {from, to}`, the same source the CFD
 * replay uses). The end is the task's `closed_on` timestamp, which is
 * authoritative for completion.
 *
 * Heuristic + caveat: with no status "category" (backlog / in-progress / done)
 * on TaskStatus, "work started" is approximated by the first transition out of
 * the creation status. Tasks with no recorded status change (e.g. bulk-imported
 * already-closed tasks) fall back to `created_at`, so their cycle time collapses
 * toward lead time — the endpoint docblock mirrors this.
 *
 * Pure decision logic over plain arrays — no Doctrine, no clock — so it is
 * unit-testable without a database.
 */
final class CycleTimeCalculator
{
    /**
     * @param list<array{id: string, identifier: ?string, createdAt: \DateTimeImmutable, closedOn: \DateTimeImmutable}> $tasks
     * @param list<array{taskId: string, occurredAt: \DateTimeImmutable}>                                              $events status-change events (any subset; earliest per task wins)
     *
     * @return array{
     *   points: list<array{taskId: string, identifier: ?string, closedOn: string, hours: float, days: float}>,
     *   percentiles: array{p50: float, p85: float, p95: float}|null,
     *   averageHours: float|null,
     *   count: int
     * }
     */
    public function compute(array $tasks, array $events): array
    {
        // Earliest status-change per task = the "work started" moment.
        $firstMove = [];
        foreach ($events as $e) {
            $id = $e['taskId'];
            if (!isset($firstMove[$id]) || $e['occurredAt'] < $firstMove[$id]) {
                $firstMove[$id] = $e['occurredAt'];
            }
        }

        $points = [];
        $hours = [];
        foreach ($tasks as $task) {
            $start = $firstMove[$task['id']] ?? $task['createdAt'];
            // Guard against clock skew / out-of-range events.
            if ($start > $task['closedOn']) {
                $start = $task['createdAt'];
            }
            $seconds = $task['closedOn']->getTimestamp() - $start->getTimestamp();
            if ($seconds < 0) {
                $seconds = 0;
            }
            $h = round($seconds / 3600, 1);
            $points[] = [
                'taskId' => $task['id'],
                'identifier' => $task['identifier'],
                'closedOn' => $task['closedOn']->format('Y-m-d'),
                'hours' => $h,
                'days' => round($h / 24, 2),
            ];
            $hours[] = $h;
        }

        $count = \count($hours);
        if ($count === 0) {
            return ['points' => [], 'percentiles' => null, 'averageHours' => null, 'count' => 0];
        }

        sort($hours);

        return [
            'points' => $points,
            'percentiles' => [
                'p50' => $this->percentile($hours, 50),
                'p85' => $this->percentile($hours, 85),
                'p95' => $this->percentile($hours, 95),
            ],
            'averageHours' => round(array_sum($hours) / $count, 1),
            'count' => $count,
        ];
    }

    /**
     * Nearest-rank percentile over an ascending-sorted list of hours.
     *
     * @param list<float> $sorted non-empty, ascending
     */
    private function percentile(array $sorted, int $p): float
    {
        $n = \count($sorted);
        $rank = (int) ceil(($p / 100) * $n) - 1;
        $rank = max(0, min($n - 1, $rank));

        return $sorted[$rank];
    }
}
