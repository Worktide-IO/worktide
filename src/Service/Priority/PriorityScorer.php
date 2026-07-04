<?php

declare(strict_types=1);

namespace App\Service\Priority;

/**
 * Computes an explainable priority score (0–100) per ticket — a WSJF-lite
 * "cost of delay ÷ effort". The score is a separate, internal signal that
 * complements (never overwrites) the human-set priority.
 *
 *   CoD   = Σ(subScore × weight) / Σ(weight)          // 0–100
 *   score = clamp(0..100, CoD × effortBoost × trackerWeight × blockedDampen)
 *
 * Pure decision logic over plain arrays — no Doctrine, no clock injected — so
 * it is unit-testable without a database. The caller assembles the hard inputs
 * (customerScore, blocksOpenCount, isBlocked, trackerWeight) from the DB; this
 * class only does the arithmetic and produces the component breakdown.
 */
final class PriorityScorer
{
    /** Default component weights (tunable per workspace). */
    public const DEFAULT_WEIGHTS = [
        'priority' => 30,
        'customer' => 25,
        'timeCrit' => 20,
        'blocker' => 10,
        'demand' => 8,
        'aging' => 7,
    ];

    private const DAY = 86_400;

    /**
     * @param list<array{
     *   id: string,
     *   priority: ?string,
     *   dueOn: ?\DateTimeImmutable,
     *   createdAt: \DateTimeImmutable,
     *   estimatedMinutes: ?int,
     *   isOpen: bool,
     *   customerScore: int,
     *   blocksOpenCount: int,
     *   demandCount: int,
     *   isBlocked: bool,
     *   trackerWeight: float
     * }> $tickets
     * @param array<string, int|float> $weights merged over DEFAULT_WEIGHTS
     *
     * @return array<string, array{score: int, blocked: bool, parts: list<array{label: string, contribution: int}>}>
     */
    public function score(array $tickets, array $weights, \DateTimeImmutable $now): array
    {
        $w = array_merge(self::DEFAULT_WEIGHTS, $weights);
        $sumW = array_sum($w) ?: 1;

        $out = [];
        foreach ($tickets as $t) {
            $subs = [
                'priority' => ['Priorität', $this->priorityScore($t['priority'])],
                'customer' => ['Kundenwert', $this->clampScore($t['customerScore'])],
                'timeCrit' => ['Fälligkeit', $this->timeCritScore($t['dueOn'], $now)],
                'blocker' => [
                    $t['blocksOpenCount'] > 0 ? "Blockiert {$t['blocksOpenCount']} Tickets" : 'Blocker-Hebel',
                    $this->blockerScore($t['blocksOpenCount']),
                ],
                'demand' => ['Nachfrage', $this->demandScore($t['demandCount'])],
                'aging' => ['Alter', $t['isOpen'] ? $this->agingScore($t['createdAt'], $now) : 0],
            ];

            $cod = 0.0;
            $parts = [];
            foreach ($subs as $key => [$label, $sub]) {
                $contribution = ($sub * (float) $w[$key]) / $sumW;
                $cod += $contribution;
                if ($contribution >= 1.0) {
                    $parts[] = ['label' => $label, 'contribution' => (int) round($contribution)];
                }
            }
            usort($parts, static fn (array $a, array $b): int => $b['contribution'] <=> $a['contribution']);

            $effortBoost = $this->effortBoost($t['estimatedMinutes']);
            $blockedDampen = $t['isBlocked'] ? 0.9 : 1.0;
            $score = (int) round($cod * $effortBoost * $t['trackerWeight'] * $blockedDampen);

            $out[$t['id']] = [
                'score' => max(0, min(100, $score)),
                'blocked' => $t['isBlocked'],
                'parts' => $parts,
            ];
        }

        return $out;
    }

    private function priorityScore(?string $priority): int
    {
        return match ($priority) {
            'urgent' => 100,
            'high' => 70,
            'low' => 15,
            default => 40, // normal / unset
        };
    }

    private function timeCritScore(?\DateTimeImmutable $dueOn, \DateTimeImmutable $now): int
    {
        if ($dueOn === null) {
            return 25;
        }
        $days = ($dueOn->getTimestamp() - $now->getTimestamp()) / self::DAY;
        if ($days < 0) {
            return 100; // overdue
        }

        return match (true) {
            $days <= 3 => 90,
            $days <= 7 => 75,
            $days <= 14 => 55,
            $days <= 30 => 35,
            default => 15,
        };
    }

    private function blockerScore(int $blocksOpenCount): int
    {
        return match (true) {
            $blocksOpenCount <= 0 => 0,
            $blocksOpenCount === 1 => 60,
            $blocksOpenCount === 2 => 80,
            default => 100,
        };
    }

    private function demandScore(int $count): int
    {
        return min(100, max(0, $count) * 25);
    }

    private function agingScore(\DateTimeImmutable $createdAt, \DateTimeImmutable $now): int
    {
        $days = ($now->getTimestamp() - $createdAt->getTimestamp()) / self::DAY;

        return match (true) {
            $days >= 90 => 100,
            $days >= 45 => 70,
            $days >= 21 => 45,
            $days >= 7 => 20,
            default => 0,
        };
    }

    private function effortBoost(?int $estimatedMinutes): float
    {
        if ($estimatedMinutes === null || $estimatedMinutes <= 0) {
            return 1.0;
        }
        $days = $estimatedMinutes / 480; // 8h workday

        return match (true) {
            $days <= 0.5 => 1.25,
            $days <= 2 => 1.1,
            $days <= 5 => 1.0,
            $days <= 10 => 0.9,
            default => 1.0,
        };
    }

    private function clampScore(int $s): int
    {
        return max(0, min(100, $s));
    }
}
