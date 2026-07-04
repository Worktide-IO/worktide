<?php

declare(strict_types=1);

namespace App\Tests\Service\Portal;

use App\Entity\Enum\TaskPriority;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Service\Portal\PortalSlaCalculator;
use PHPUnit\Framework\TestCase;

final class PortalSlaCalculatorTest extends TestCase
{
    private const NOW = '2026-07-04 12:00:00';

    private PortalSlaCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new PortalSlaCalculator();
    }

    public function testOpenTicketShowsRemainingTime(): void
    {
        // high = 4h SLA, created 1h ago → 3h left.
        $task = $this->task(TaskPriority::High, '-1 hour', false);
        $sla = $this->calc->describe($task, [], $this->now());

        self::assertSame('due', $sla['status']);
        self::assertSame('in 3 Std.', $sla['label']);
    }

    public function testOpenTicketPastDeadlineIsOverdue(): void
    {
        // high = 4h SLA, created 5h ago → overdue.
        $task = $this->task(TaskPriority::High, '-5 hours', false);
        $sla = $this->calc->describe($task, [], $this->now());

        self::assertSame('overdue', $sla['status']);
        self::assertSame('überschritten', $sla['label']);
    }

    public function testCompletedWithinDeadlineIsMet(): void
    {
        // normal = 24h SLA, created 10h ago, closed 2h ago → met.
        $task = $this->task(TaskPriority::Normal, '-10 hours', true, '-2 hours');
        $sla = $this->calc->describe($task, [], $this->now());

        self::assertSame('met', $sla['status']);
        self::assertSame('erfüllt', $sla['label']);
    }

    public function testCompletedAfterDeadlineIsMissed(): void
    {
        // normal = 24h SLA, created 30h ago, closed 1h ago (well past due) → missed.
        $task = $this->task(TaskPriority::Normal, '-30 hours', true, '-1 hour');
        $sla = $this->calc->describe($task, [], $this->now());

        self::assertSame('missed', $sla['status']);
        self::assertSame('überschritten', $sla['label']);
    }

    public function testWorkspaceOverrideDisablesSla(): void
    {
        // Override maps high → 0 → no SLA.
        $task = $this->task(TaskPriority::High, '-1 hour', false);
        $sla = $this->calc->describe($task, ['high' => 0], $this->now());

        self::assertSame('none', $sla['status']);
        self::assertSame('—', $sla['label']);
    }

    public function testMultiDayRemainingLabel(): void
    {
        // low = 72h SLA, just created → "in 3 Tagen".
        $task = $this->task(TaskPriority::Low, 'now', false);
        $sla = $this->calc->describe($task, [], $this->now());

        self::assertSame('due', $sla['status']);
        self::assertSame('in 3 Tagen', $sla['label']);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function task(TaskPriority $priority, string $createdModifier, bool $completed, ?string $closedModifier = null): Task
    {
        $task = (new Task())
            ->setPriority($priority)
            ->setStatus((new TaskStatus())->setIsCompleted($completed));

        $createdAt = $this->now()->modify($createdModifier);
        $ref = new \ReflectionProperty(Task::class, 'createdAt');
        $ref->setValue($task, $createdAt);

        if ($closedModifier !== null) {
            $task->setClosedOn($this->now()->modify($closedModifier));
        }

        return $task;
    }
}
