<?php

declare(strict_types=1);

namespace App\Tests\Service\Reports;

use App\Service\Reports\CycleTimeCalculator;
use PHPUnit\Framework\TestCase;

final class CycleTimeCalculatorTest extends TestCase
{
    private CycleTimeCalculator $svc;

    protected function setUp(): void
    {
        $this->svc = new CycleTimeCalculator();
    }

    private function at(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s);
    }

    public function testEmpty(): void
    {
        $result = $this->svc->compute([], []);
        self::assertSame(0, $result['count']);
        self::assertNull($result['percentiles']);
        self::assertNull($result['averageHours']);
        self::assertSame([], $result['points']);
    }

    public function testCycleTimeIsFirstStatusChangeToClosed(): void
    {
        $tasks = [[
            'id' => 't1',
            'identifier' => 'X-1',
            'createdAt' => $this->at('2026-01-01 00:00'),
            'closedOn' => $this->at('2026-01-04 00:00'),
        ]];
        // Work started 2026-01-03 (first status change), not at creation.
        $events = [
            ['taskId' => 't1', 'occurredAt' => $this->at('2026-01-03 00:00')],
            ['taskId' => 't1', 'occurredAt' => $this->at('2026-01-03 12:00')],
        ];

        $result = $this->svc->compute($tasks, $events);

        self::assertSame(1, $result['count']);
        self::assertSame(24.0, $result['points'][0]['hours']); // Jan 3 → Jan 4 = 24h
        self::assertSame('X-1', $result['points'][0]['identifier']);
        self::assertSame(24.0, $result['averageHours']);
    }

    public function testFallsBackToCreatedAtWithoutEvents(): void
    {
        $tasks = [[
            'id' => 't1',
            'identifier' => null,
            'createdAt' => $this->at('2026-01-01 00:00'),
            'closedOn' => $this->at('2026-01-02 00:00'),
        ]];

        $result = $this->svc->compute($tasks, []);

        self::assertSame(24.0, $result['points'][0]['hours']); // created → closed
    }

    public function testGuardsAgainstEventAfterClose(): void
    {
        $tasks = [[
            'id' => 't1',
            'identifier' => null,
            'createdAt' => $this->at('2026-01-01 00:00'),
            'closedOn' => $this->at('2026-01-02 00:00'),
        ]];
        // Bogus event after closure → fall back to created_at, not negative.
        $events = [['taskId' => 't1', 'occurredAt' => $this->at('2026-01-05 00:00')]];

        $result = $this->svc->compute($tasks, $events);

        self::assertSame(24.0, $result['points'][0]['hours']);
    }

    public function testPercentiles(): void
    {
        // Four tasks with cycle times 1h, 2h, 3h, 4h (created==started, no events).
        $tasks = [];
        foreach ([1, 2, 3, 4] as $i => $h) {
            $tasks[] = [
                'id' => "t$i",
                'identifier' => "X-$i",
                'createdAt' => $this->at('2026-01-01 00:00'),
                'closedOn' => $this->at('2026-01-01 00:00')->modify("+$h hours"),
            ];
        }

        $result = $this->svc->compute($tasks, []);

        self::assertSame(4, $result['count']);
        self::assertSame(2.5, $result['averageHours']);
        // nearest-rank on [1,2,3,4]: p50→idx1=2, p85→idx3=4, p95→idx3=4
        self::assertSame(2.0, $result['percentiles']['p50']);
        self::assertSame(4.0, $result['percentiles']['p85']);
        self::assertSame(4.0, $result['percentiles']['p95']);
    }
}
