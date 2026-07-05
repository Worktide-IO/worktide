<?php

declare(strict_types=1);

namespace App\Service\Portal;

use App\Entity\Task;

/**
 * Derives a per-ticket SLA cell for the portal (wireframe screen 2) with TWO
 * targets — first-response and resolution — each keyed on the ticket's priority.
 *
 * There is still no structured SLA CONTRACT entity; the policy is layered config:
 * built-in {@see DEFAULTS} → workspace `settings.portal.sla` → per-customer
 * `Customer.slaPolicy`, each a `{priority: {response, resolution}}` hours map
 * (a bare number is read as resolution-only, for back-compat; 0 = no SLA for
 * that leg). The caller merges customer over workspace and passes the result.
 *
 * Per leg the status comes from real timestamps:
 *   - achieved within target → "met" ("erfüllt"); after → "missed" ("überschritten"),
 *   - open, deadline ahead   → "due" ("in 4 Std."); past → "overdue" ("überschritten"),
 *   - ticket waiting on the customer → "paused" ("pausiert") — the clock is not
 *     counted against the agency while the ball is in the customer's court,
 *   - no target for this priority → "none" ("—").
 *
 * PAUSE is current-state only (status flagged {@see TaskStatus::isWaitingForCustomer}):
 * we don't subtract historical waiting from the deadline (that needs status
 * history) — a documented simplification.
 */
final class PortalSlaCalculator
{
    /** Built-in response + resolution targets per priority, in hours. */
    private const DEFAULTS = [
        'urgent' => ['response' => 1, 'resolution' => 4],
        'high' => ['response' => 2, 'resolution' => 8],
        'normal' => ['response' => 8, 'resolution' => 48],
        'low' => ['response' => 24, 'resolution' => 120],
    ];

    /**
     * @param array<string, mixed> $policy merged (customer over workspace) SLA override
     *
     * @return array{
     *     paused: bool,
     *     response: array{status: string, label: string, dueAt: ?string},
     *     resolution: array{status: string, label: string, dueAt: ?string}
     * }
     */
    public function describe(
        Task $task,
        array $policy,
        ?\DateTimeImmutable $firstAgencyReplyAt,
        \DateTimeImmutable $now,
    ): array {
        $priority = $task->getPriority()->value;
        $target = $this->targetFor($priority, $policy);
        $createdAt = $task->getCreatedAt();
        $status = $task->getStatus();
        $completed = $status->isCompleted();
        $paused = !$completed && $status->isWaitingForCustomer();

        return [
            'paused' => $paused,
            // Response leg is "achieved" once the agency has first replied.
            'response' => $this->leg($createdAt, $target['response'], $firstAgencyReplyAt, $paused, $now),
            // Resolution leg is "achieved" once the ticket is completed.
            'resolution' => $this->leg(
                $createdAt,
                $target['resolution'],
                $completed ? ($task->getClosedOn() ?? $task->getUpdatedAt()) : null,
                $paused,
                $now,
            ),
        ];
    }

    /**
     * @return array{status: string, label: string, dueAt: ?string}
     */
    private function leg(?\DateTimeImmutable $createdAt, ?int $hours, ?\DateTimeImmutable $doneAt, bool $paused, \DateTimeImmutable $now): array
    {
        if ($hours === null || $createdAt === null) {
            return ['status' => 'none', 'label' => '—', 'dueAt' => null];
        }

        $dueAt = $createdAt->modify(sprintf('+%d hours', $hours));
        $dueIso = $dueAt->format(\DateTimeInterface::ATOM);

        if ($doneAt !== null) {
            $met = $doneAt <= $dueAt;

            return ['status' => $met ? 'met' : 'missed', 'label' => $met ? 'erfüllt' : 'überschritten', 'dueAt' => $dueIso];
        }

        if ($paused) {
            return ['status' => 'paused', 'label' => 'pausiert', 'dueAt' => $dueIso];
        }

        if ($now >= $dueAt) {
            return ['status' => 'overdue', 'label' => 'überschritten', 'dueAt' => $dueIso];
        }

        return ['status' => 'due', 'label' => $this->remaining($now, $dueAt), 'dueAt' => $dueIso];
    }

    /**
     * Effective {response, resolution} hours for a priority: the override entry
     * (structured, or a bare number = resolution) overlaid on the built-in
     * default; 0/invalid → null (no SLA for that leg).
     *
     * @param array<string, mixed> $policy
     *
     * @return array{response: ?int, resolution: ?int}
     */
    private function targetFor(string $priority, array $policy): array
    {
        $default = self::DEFAULTS[$priority] ?? ['response' => null, 'resolution' => null];
        $override = $policy[$priority] ?? null;

        $response = $default['response'] ?? null;
        $resolution = $default['resolution'] ?? null;

        if (is_numeric($override)) {
            $resolution = (int) $override; // back-compat: a bare number is the resolution target
        } elseif (\is_array($override)) {
            if (\array_key_exists('response', $override) && is_numeric($override['response'])) {
                $response = (int) $override['response'];
            }
            if (\array_key_exists('resolution', $override) && is_numeric($override['resolution'])) {
                $resolution = (int) $override['resolution'];
            }
        }

        return [
            'response' => ($response !== null && $response > 0) ? $response : null,
            'resolution' => ($resolution !== null && $resolution > 0) ? $resolution : null,
        ];
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
