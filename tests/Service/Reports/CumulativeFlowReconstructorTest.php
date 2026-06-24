<?php

declare(strict_types=1);

namespace App\Tests\Service\Reports;

use App\Service\Reports\CumulativeFlowReconstructor;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the CFD status-replay logic (no DB).
 *
 * Status ids are written as short labels here ('todo', 'doing', 'done') — the
 * reconstructor treats them as opaque string keys, so the real UUIDs behave
 * identically.
 */
final class CumulativeFlowReconstructorTest extends TestCase
{
    private CumulativeFlowReconstructor $svc;

    protected function setUp(): void
    {
        $this->svc = new CumulativeFlowReconstructor();
    }

    public function testNoEventsTaskHoldsCurrentStatusEveryDay(): void
    {
        $task = ['id' => 't1', 'createdAt' => $this->at('2026-01-01 09:00'), 'currentStatusId' => 'doing'];

        $series = $this->svc->build([$task], [], $this->at('2026-01-02 00:00'), $this->at('2026-01-04 00:00'));

        self::assertCount(3, $series);
        foreach ($series as $day) {
            self::assertSame(['doing' => 1], $day['counts'], "day {$day['date']}");
        }
    }

    public function testStatusChangeFlipsBandOnTheRightDay(): void
    {
        // Created in 'todo'; moved to 'done' on Jan 3 at noon.
        $task = ['id' => 't1', 'createdAt' => $this->at('2026-01-01 09:00'), 'currentStatusId' => 'done'];
        $events = [
            ['taskId' => 't1', 'from' => 'todo', 'to' => 'done', 'occurredAt' => $this->at('2026-01-03 12:00')],
        ];

        $series = $this->svc->build([$task], $events, $this->at('2026-01-02 00:00'), $this->at('2026-01-04 00:00'));

        // Jan 2 still 'todo', Jan 3 (change happened by end of day) flips to 'done', Jan 4 stays 'done'.
        self::assertSame(['date' => '2026-01-02', 'counts' => ['todo' => 1]], $series[0]);
        self::assertSame(['date' => '2026-01-03', 'counts' => ['done' => 1]], $series[1]);
        self::assertSame(['date' => '2026-01-04', 'counts' => ['done' => 1]], $series[2]);
    }

    public function testMultipleEventsReplayInOrder(): void
    {
        // todo → doing (Jan 2) → done (Jan 4).
        $task = ['id' => 't1', 'createdAt' => $this->at('2026-01-01 09:00'), 'currentStatusId' => 'done'];
        $events = [
            // intentionally out of chronological order to prove sorting:
            ['taskId' => 't1', 'from' => 'doing', 'to' => 'done', 'occurredAt' => $this->at('2026-01-04 10:00')],
            ['taskId' => 't1', 'from' => 'todo', 'to' => 'doing', 'occurredAt' => $this->at('2026-01-02 10:00')],
        ];

        $series = $this->svc->build([$task], $events, $this->at('2026-01-01 00:00'), $this->at('2026-01-05 00:00'));

        self::assertSame(['todo' => 1], $series[0]['counts']);  // Jan 1
        self::assertSame(['doing' => 1], $series[1]['counts']); // Jan 2
        self::assertSame(['doing' => 1], $series[2]['counts']); // Jan 3
        self::assertSame(['done' => 1], $series[3]['counts']);  // Jan 4
        self::assertSame(['done' => 1], $series[4]['counts']);  // Jan 5
    }

    public function testTaskCreatedMidRangeIsAbsentBeforeCreation(): void
    {
        $task = ['id' => 't1', 'createdAt' => $this->at('2026-01-03 09:00'), 'currentStatusId' => 'todo'];

        $series = $this->svc->build([$task], [], $this->at('2026-01-01 00:00'), $this->at('2026-01-04 00:00'));

        self::assertSame([], $series[0]['counts']);            // Jan 1 — not yet created
        self::assertSame([], $series[1]['counts']);            // Jan 2 — not yet created
        self::assertSame(['todo' => 1], $series[2]['counts']); // Jan 3 — created
        self::assertSame(['todo' => 1], $series[3]['counts']); // Jan 4
    }

    public function testCountsAggregateAcrossTasks(): void
    {
        $tasks = [
            ['id' => 'a', 'createdAt' => $this->at('2026-01-01 09:00'), 'currentStatusId' => 'todo'],
            ['id' => 'b', 'createdAt' => $this->at('2026-01-01 09:00'), 'currentStatusId' => 'done'],
            ['id' => 'c', 'createdAt' => $this->at('2026-01-01 09:00'), 'currentStatusId' => 'todo'],
        ];

        $series = $this->svc->build($tasks, [], $this->at('2026-01-01 00:00'), $this->at('2026-01-01 00:00'));

        self::assertCount(1, $series);
        self::assertSame(['todo' => 2, 'done' => 1], $series[0]['counts']);
    }

    private function at(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime);
    }
}
