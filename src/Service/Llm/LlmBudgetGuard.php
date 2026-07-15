<?php

declare(strict_types=1);

namespace App\Service\Llm;

use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Per-workspace monthly AI spend cap. The budget lives in the workspace settings
 * (`settings.ai.monthlyBudgetMicros`, integer micro-USD; 0/absent = unlimited);
 * spend is the month-to-date SUM over {@see \App\Entity\LlmUsageLog}. Checked
 * inside the providers before every call, so no spend happens once the cap is
 * hit — {@see LlmBudgetExceededException} is raised instead.
 */
final class LlmBudgetGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function budgetMicros(Workspace $workspace): int
    {
        return (int) ($workspace->getSettings()['ai']['monthlyBudgetMicros'] ?? 0);
    }

    /** Month-to-date spend (micro-USD) for the workspace, from the calendar month start. */
    public function monthSpentMicros(Workspace $workspace): int
    {
        $since = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0)->format('Y-m-d H:i:s');

        return (int) $this->em->getConnection()->executeQuery(
            'SELECT COALESCE(SUM(cost_micros), 0) FROM llm_usage_logs WHERE workspace_id = :ws AND created_at >= :since',
            ['ws' => $workspace->getId()?->toBinary(), 'since' => $since],
        )->fetchOne();
    }

    /**
     * Raise {@see LlmBudgetExceededException} when the workspace is over its
     * monthly cap. No-op for an unattributed call (null workspace) or when no
     * budget is set (0 = unlimited).
     */
    public function assertWithinBudget(?Workspace $workspace): void
    {
        if ($workspace === null) {
            return;
        }
        $budget = $this->budgetMicros($workspace);
        if ($budget <= 0) {
            return;
        }
        if ($this->monthSpentMicros($workspace) >= $budget) {
            throw new LlmBudgetExceededException('Monthly AI budget reached for this workspace.');
        }
    }
}
