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
    private const NOW = '2026-07-05 12:00:00';

    private PortalSlaCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new PortalSlaCalculator();
    }

    public function testResponseAndResolutionLegsForOpenTicket(): void
    {
        // normal defaults: response 8h, resolution 48h. Created 2h ago, no reply.
        $sla = $this->calc->describe($this->task(TaskPriority::Normal, '-2 hours'), [], null, $this->now());

        self::assertFalse($sla['paused']);
        self::assertSame('due', $sla['response']['status']);
        self::assertSame('in 6 Std.', $sla['response']['label']); // 8h target, 2h elapsed
        self::assertSame('due', $sla['resolution']['status']);
        self::assertSame('in 2 Tagen', $sla['resolution']['label']); // 48h target
    }

    public function testResponseMetOnceAgencyReplied(): void
    {
        $task = $this->task(TaskPriority::Normal, '-2 hours');
        $sla = $this->calc->describe($task, [], $this->now()->modify('-1 hour'), $this->now());

        self::assertSame('met', $sla['response']['status']); // replied within 8h
        self::assertSame('due', $sla['resolution']['status']); // still open
    }

    public function testResolutionMissedWhenClosedLate(): void
    {
        // resolution 48h; created 60h ago, closed 1h ago → past deadline.
        $task = $this->task(TaskPriority::Normal, '-60 hours', completed: true, closedMod: '-1 hour');
        $sla = $this->calc->describe($task, [], $this->now()->modify('-59 hours'), $this->now());

        self::assertSame('missed', $sla['resolution']['status']);
        self::assertSame('met', $sla['response']['status']); // replied 1h after creation, within 8h
    }

    public function testPausedWhileWaitingOnCustomer(): void
    {
        $task = $this->task(TaskPriority::Normal, '-2 hours', waiting: true);
        $sla = $this->calc->describe($task, [], null, $this->now());

        self::assertTrue($sla['paused']);
        self::assertSame('paused', $sla['response']['status']);
        self::assertSame('paused', $sla['resolution']['status']);
        self::assertSame('pausiert', $sla['resolution']['label']);
    }

    public function testStructuredOverridePerPriority(): void
    {
        $policy = ['high' => ['response' => 1, 'resolution' => 2]];
        $task = $this->task(TaskPriority::High, '-30 minutes');
        $sla = $this->calc->describe($task, $policy, null, $this->now());

        self::assertSame('in 30 Min.', $sla['response']['label']); // 1h target, 30min elapsed
        self::assertSame('in 2 Std.', $sla['resolution']['label']); // 2h target, 30min elapsed
    }

    public function testBareNumberOverrideIsResolutionOnly(): void
    {
        // A flat number override applies to resolution; response keeps its default.
        $sla = $this->calc->describe($this->task(TaskPriority::Normal, '-1 hour'), ['normal' => 4], null, $this->now());

        self::assertSame('in 3 Std.', $sla['resolution']['label']); // 4h override
        self::assertSame('in 7 Std.', $sla['response']['label']); // 8h default kept
    }

    public function testZeroDisablesALeg(): void
    {
        $policy = ['normal' => ['response' => 0, 'resolution' => 0]];
        $sla = $this->calc->describe($this->task(TaskPriority::Normal, '-1 hour'), $policy, null, $this->now());

        self::assertSame('none', $sla['response']['status']);
        self::assertSame('none', $sla['resolution']['status']);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function task(TaskPriority $priority, string $createdMod, bool $completed = false, ?string $closedMod = null, bool $waiting = false): Task
    {
        $status = (new TaskStatus())->setIsCompleted($completed)->setIsWaitingForCustomer($waiting);
        $task = (new Task())->setPriority($priority)->setStatus($status);

        (new \ReflectionProperty(Task::class, 'createdAt'))->setValue($task, $this->now()->modify($createdMod));
        if ($closedMod !== null) {
            $task->setClosedOn($this->now()->modify($closedMod));
        }

        return $task;
    }
}
