<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Task;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\PlanScheduleMessage;
use App\Service\Ai\SchedulePlanningAssistant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Runs the LLM work planner for one staff member off the request thread and
 * writes the plan onto the tasks as time slots: the model's per-day order is
 * materialised into sequential startOn/scheduledEnd slots (workday starts 09:00,
 * each ticket takes its estimatedMinutes, default 30), so the Team-Planner and
 * the dashboard "next tickets" widget both reflect it. Mirrors
 * {@see EstimateTaskHandler}: reload by id, drop unrecoverably when gone.
 */
#[AsMessageHandler]
final class PlanScheduleHandler
{
    private const WORKDAY_START_HOUR = 9;
    private const DEFAULT_ESTIMATE = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SchedulePlanningAssistant $assistant,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(PlanScheduleMessage $message): void
    {
        $staff = $this->em->find(User::class, $message->getUserId());
        $workspace = $this->em->find(Workspace::class, $message->getWorkspaceId());
        if ($staff === null || $workspace === null) {
            throw new UnrecoverableMessageHandlingException('Staff/workspace gone; dropping schedule plan.');
        }

        $tasks = $this->assistant->openAssignedTasks($staff, $workspace);
        if ($tasks === []) {
            return;
        }
        $byId = [];
        foreach ($tasks as $task) {
            $byId[$task->getId()?->toRfc4122()] = $task;
        }

        $free = $this->assistant->freeCapacity($staff);
        $plan = $this->assistant->plan($staff, $workspace, $tasks, $free);

        // Materialise: pack each day's tickets into sequential slots from 09:00.
        $dayCursor = [];
        foreach ($plan as $row) {
            $task = $byId[$row['taskId']] ?? null;
            if (!$task instanceof Task) {
                continue;
            }
            $day = $row['day'];
            $start = ($dayCursor[$day] ?? null)
                ?? (new \DateTimeImmutable($day))->setTime(self::WORKDAY_START_HOUR, 0);
            $minutes = $task->getEstimatedMinutes() ?? self::DEFAULT_ESTIMATE;
            $end = $start->modify("+{$minutes} minutes");

            $task->setStartOn($start);
            $task->setScheduledEnd($end);
            $dayCursor[$day] = $end;
        }

        $this->em->flush();

        $this->logger->info('AI schedule planned.', [
            'userId' => $message->getUserId()->toRfc4122(),
            'workspaceId' => $message->getWorkspaceId()->toRfc4122(),
            'tickets' => \count($plan),
        ]);
    }
}
