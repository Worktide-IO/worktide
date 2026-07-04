<?php

declare(strict_types=1);

namespace App\Service\Portal;

use App\Entity\Task;

/**
 * Derives a per-ticket SLA cell for the portal ticket list (wireframe screen 2).
 *
 * There is no structured per-customer SLA contract in the schema (the "sla"
 * AgreementType is only a document). So the SLA is a DERIVED default response
 * target keyed on the ticket's priority, overridable per workspace via
 * `settings.portal.sla` ({priority: hours}); a priority mapped to 0/null has no
 * SLA. The status is computed from real timestamps:
 *   - open, deadline in the future → "due" ("in 4 Std." / "in 2 Tagen"),
 *   - open, past the deadline       → "overdue" ("überschritten"),
 *   - completed within the deadline → "met" ("erfüllt"),
 *   - completed after the deadline  → "missed" ("überschritten"),
 *   - no SLA for this priority      → "none" ("—").
 */
final class PortalSlaCalculator
{
    /** Default response target per priority, in hours. */
    private const DEFAULT_HOURS = [
        'urgent' => 2,
        'high' => 4,
        'normal' => 24,
        'low' => 72,
    ];

    /**
     * @param array<string, mixed> $override workspace SLA policy ({priority: hours})
     * @return array{status: string, label: string, dueAt: ?string}
     */
    public function describe(Task $task, array $override, \DateTimeImmutable $now): array
    {
        $priority = $task->getPriority()->value;
        $hours = $this->hoursFor($priority, $override);
        $createdAt = $task->getCreatedAt();

        if ($hours === null || $createdAt === null) {
            return ['status' => 'none', 'label' => '—', 'dueAt' => null];
        }

        $dueAt = $createdAt->modify(sprintf('+%d hours', $hours));
        $dueIso = $dueAt->format(\DateTimeInterface::ATOM);

        if ($task->getStatus()->isCompleted()) {
            $doneAt = $task->getClosedOn() ?? $task->getUpdatedAt() ?? $now;
            $met = $doneAt <= $dueAt;

            return ['status' => $met ? 'met' : 'missed', 'label' => $met ? 'erfüllt' : 'überschritten', 'dueAt' => $dueIso];
        }

        if ($now >= $dueAt) {
            return ['status' => 'overdue', 'label' => 'überschritten', 'dueAt' => $dueIso];
        }

        return ['status' => 'due', 'label' => $this->remaining($now, $dueAt), 'dueAt' => $dueIso];
    }

    /** @param array<string, mixed> $override */
    private function hoursFor(string $priority, array $override): ?int
    {
        $raw = $override[$priority] ?? self::DEFAULT_HOURS[$priority] ?? null;
        if (!is_numeric($raw)) {
            return null;
        }
        $hours = (int) $raw;

        return $hours > 0 ? $hours : null;
    }

    private function remaining(\DateTimeImmutable $now, \DateTimeImmutable $dueAt): string
    {
        $secs = $dueAt->getTimestamp() - $now->getTimestamp();
        if ($secs < 3600) {
            return sprintf('in %d Min.', max(1, (int) round($secs / 60)));
        }
        if ($secs < 86400) {
            return sprintf('in %d Std.', (int) round($secs / 3600));
        }
        $days = (int) round($secs / 86400);

        return $days === 1 ? 'in 1 Tag' : sprintf('in %d Tagen', $days);
    }
}
