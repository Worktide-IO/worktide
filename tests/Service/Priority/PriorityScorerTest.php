<?php

declare(strict_types=1);

namespace App\Tests\Service\Priority;

use App\Service\Priority\PriorityScorer;
use PHPUnit\Framework\TestCase;

final class PriorityScorerTest extends TestCase
{
    private PriorityScorer $svc;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->svc = new PriorityScorer();
        $this->now = new \DateTimeImmutable('2026-07-04 12:00:00');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function ticket(array $overrides = []): array
    {
        return array_merge([
            'id' => 't1',
            'priority' => 'urgent',
            'dueOn' => $this->now->modify('+6 days'),
            'createdAt' => $this->now->modify('-30 days'),
            'estimatedMinutes' => 480, // 1 day → effortBoost 1.1
            'isOpen' => true,
            'customerScore' => 70,
            'blocksOpenCount' => 1,
            'demandCount' => 0,
            'isBlocked' => false,
            'trackerWeight' => 1.0,
        ], $overrides);
    }

    public function testWeightedScoreAndBreakdown(): void
    {
        // CoD = (100*30 + 70*25 + 75*20 + 60*10 + 0*8 + 45*7)/100 = 71.65
        // score = round(71.65 * 1.1 effort) = 79
        $result = $this->svc->score([$this->ticket()], [], $this->now);

        self::assertSame(79, $result['t1']['score']);
        self::assertFalse($result['t1']['blocked']);
        // Parts sorted by contribution desc; priority (30) leads, demand (0) dropped.
        self::assertSame('Priorität', $result['t1']['parts'][0]['label']);
        self::assertSame(30, $result['t1']['parts'][0]['contribution']);
        self::assertCount(5, $result['t1']['parts']);
    }

    public function testBlockedDampen(): void
    {
        $result = $this->svc->score([$this->ticket(['isBlocked' => true])], [], $this->now);
        // 71.65 * 1.1 * 0.9 = 70.9 → 71
        self::assertSame(71, $result['t1']['score']);
        self::assertTrue($result['t1']['blocked']);
    }

    public function testOverdueBeatsFutureDue(): void
    {
        $overdue = $this->svc->score([$this->ticket(['id' => 'o', 'dueOn' => $this->now->modify('-1 day')])], [], $this->now);
        $future = $this->svc->score([$this->ticket(['id' => 'f', 'dueOn' => $this->now->modify('+40 days')])], [], $this->now);
        self::assertGreaterThan($future['f']['score'], $overdue['o']['score']);
    }

    public function testWeightsAreConfigurable(): void
    {
        // Zero out everything but customer → CoD == customerScore, effort 1.1.
        $weights = ['priority' => 0, 'customer' => 100, 'timeCrit' => 0, 'blocker' => 0, 'demand' => 0, 'aging' => 0];
        $result = $this->svc->score([$this->ticket(['customerScore' => 50])], $weights, $this->now);
        self::assertSame(55, $result['t1']['score']); // round(50 * 1.1)
    }

    public function testMidRangeEffortNotPenalised(): void
    {
        // 3-day estimate (>2, <=5) must keep boost 1.0, not fall to the <=10 → 0.9 bucket.
        $threeDay = $this->svc->score([$this->ticket(['id' => 'a', 'estimatedMinutes' => 3 * 480])], [], $this->now);
        $tenDay = $this->svc->score([$this->ticket(['id' => 'b', 'estimatedMinutes' => 8 * 480])], [], $this->now);
        self::assertGreaterThan($tenDay['b']['score'], $threeDay['a']['score']);
    }

    public function testEmpty(): void
    {
        self::assertSame([], $this->svc->score([], [], $this->now));
    }
}
