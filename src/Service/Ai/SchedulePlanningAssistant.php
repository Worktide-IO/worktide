<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Absence;
use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Task;
use App\Entity\User;
use App\Entity\UserCapacity;
use App\Entity\Workspace;
use App\Service\Llm\AiUsageContext;
use App\Service\Llm\LlmProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * LLM-based work planner: orders a staff member's open, assigned tickets by
 * priority and distributes them across their free working time over the next
 * {@see self::HORIZON_DAYS} days. The model gets the tickets (priority score,
 * estimate, deadline) + the free minutes per day (UserCapacity − absences) and
 * returns an ordered day-by-day plan; the handler materialises exact time slots.
 *
 * Suggestions only here; {@see \App\MessageHandler\PlanScheduleHandler} applies
 * the plan. Bounded to {@see self::MAX_TICKETS} tickets / {@see self::HORIZON_DAYS}
 * days per run to cap tokens/cost.
 */
final class SchedulePlanningAssistant
{
    public const HORIZON_DAYS = 14;
    public const MAX_TICKETS = 40;
    private const DEFAULT_ESTIMATE = 30;
    private const DEFAULT_WEEKDAY_MINUTES = 480;

    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly EntityManagerInterface $em,
        private readonly AiUsageContext $usageContext,
    ) {}

    public function isAvailable(): bool
    {
        return $this->llm->isConfigured();
    }

    public function getModel(): string
    {
        return $this->llm->getModel();
    }

    /**
     * @return list<Task> open, non-deleted tasks assigned to the user in the
     *                    workspace, highest priority first, capped.
     */
    public function openAssignedTasks(User $staff, Workspace $workspace): array
    {
        /** @var list<Task> $tasks */
        $tasks = $this->em->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->join('t.status', 's')
            ->innerJoin('t.assignedPrincipals', 'ap', 'WITH', 'ap.principalType = :ptype AND ap.principalId = :uid')
            ->andWhere('t.workspace = :ws')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('s.isCompleted = false')
            ->setParameter('ptype', AssigneePrincipalType::User)
            ->setParameter('uid', $staff->getId(), UuidType::NAME)
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
            ->orderBy('t.priorityScore', 'DESC')
            ->addOrderBy('t.dueOn', 'ASC')
            ->setMaxResults(self::MAX_TICKETS)
            ->getQuery()
            ->getResult();

        return $tasks;
    }

    /**
     * Free working minutes per calendar day over the horizon, keyed YYYY-MM-DD:
     * the weekday capacity, zeroed on days fully covered by an absence.
     *
     * @return array<string, int>
     */
    public function freeCapacity(User $staff): array
    {
        $capacity = $this->em->getRepository(UserCapacity::class)->findOneBy(['user' => $staff]);
        $weekday = static function (int $dow) use ($capacity): int {
            if ($capacity === null) {
                return $dow >= 6 ? 0 : self::DEFAULT_WEEKDAY_MINUTES; // 6=Sat, 7=Sun
            }
            return match ($dow) {
                1 => $capacity->getMonMinutes(), 2 => $capacity->getTueMinutes(),
                3 => $capacity->getWedMinutes(), 4 => $capacity->getThuMinutes(),
                5 => $capacity->getFriMinutes(), 6 => $capacity->getSatMinutes(),
                default => $capacity->getSunMinutes(),
            };
        };

        $today = new \DateTimeImmutable('today');
        $horizonEnd = $today->modify('+' . self::HORIZON_DAYS . ' days');

        /** @var list<Absence> $absences */
        $absences = $this->em->getRepository(Absence::class)->createQueryBuilder('a')
            ->andWhere('a.user = :u')
            ->andWhere('a.endsOn >= :start AND a.startsOn < :end')
            ->setParameter('u', $staff)
            ->setParameter('start', $today)
            ->setParameter('end', $horizonEnd)
            ->getQuery()->getResult();

        $free = [];
        for ($i = 0; $i < self::HORIZON_DAYS; $i++) {
            $day = $today->modify("+{$i} days");
            $minutes = $weekday((int) $day->format('N'));
            foreach ($absences as $absence) {
                if ($day >= $absence->getStartsOn()->setTime(0, 0) && $day <= $absence->getEndsOn()->setTime(0, 0)) {
                    $minutes = 0;
                    break;
                }
            }
            $free[$day->format('Y-m-d')] = $minutes;
        }

        return $free;
    }

    /**
     * Ask the model to order the tickets and assign each a day from the free-
     * capacity window.
     *
     * @param list<Task> $tasks
     * @param array<string, int> $freeCapacity
     * @return list<array{taskId: string, day: string}> in work order; only known task ids + days
     */
    public function plan(User $staff, Workspace $workspace, array $tasks, array $freeCapacity): array
    {
        if ($tasks === []) {
            return [];
        }
        $this->usageContext->set('schedule', $workspace);

        $byId = [];
        foreach ($tasks as $task) {
            $id = $task->getId()?->toRfc4122();
            if ($id !== null) {
                $byId[$id] = true;
            }
        }
        $validDays = array_keys($freeCapacity);

        $raw = $this->llm->completeJson($this->systemPrompt(), $this->buildContext($tasks, $freeCapacity), 3000);

        $plan = [];
        $seen = [];
        foreach ((array) ($raw['plan'] ?? []) as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $taskId = \is_string($row['taskId'] ?? null) ? $row['taskId'] : null;
            $day = \is_string($row['day'] ?? null) ? $row['day'] : null;
            if ($taskId === null || !isset($byId[$taskId]) || isset($seen[$taskId])) {
                continue;
            }
            // Snap an unknown/out-of-window day to the first available day.
            if ($day === null || !\in_array($day, $validDays, true)) {
                $day = $validDays[0] ?? (new \DateTimeImmutable('today'))->format('Y-m-d');
            }
            $plan[] = ['taskId' => $taskId, 'day' => $day];
            $seen[$taskId] = true;
        }

        // Append any tickets the model dropped, in the input (priority) order, on day 1.
        foreach ($byId as $id => $_) {
            if (!isset($seen[$id])) {
                $plan[] = ['taskId' => $id, 'day' => $validDays[0] ?? (new \DateTimeImmutable('today'))->format('Y-m-d')];
            }
        }

        return $plan;
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
        You are a work-planning assistant. Given a staff member's open tickets and
        their free working minutes per day over the next two weeks, produce a plan:
        the order to work the tickets in, and which day each should be worked.

        Rules:
        - Higher priorityScore and nearer deadlines come first.
        - Fill each day up to its free minutes before moving to the next day; a
          ticket's estimatedMinutes is how long it takes.
        - Never schedule work on a day with 0 free minutes.
        - Respect deadlines (dueOn) where possible — a ticket due sooner should not
          be pushed past its due date behind lower-priority work.

        Respond as a JSON object: { "plan": [ { "taskId": "<id>", "day": "YYYY-MM-DD" }, ... ] }
        in the exact order the tickets should be worked. Use ONLY the given task ids
        and ONLY days from the provided free-capacity list. Include every ticket.
        PROMPT;
    }

    /**
     * @param list<Task> $tasks
     * @param array<string, int> $freeCapacity
     */
    private function buildContext(array $tasks, array $freeCapacity): string
    {
        $lines = ['FREE WORKING MINUTES PER DAY:'];
        foreach ($freeCapacity as $day => $minutes) {
            $lines[] = sprintf('- %s: %d min', $day, $minutes);
        }
        $lines[] = '';
        $lines[] = 'OPEN TICKETS (highest priority first):';
        foreach ($tasks as $task) {
            $lines[] = sprintf(
                '- taskId=%s | prioScore=%s | priority=%s | estimate=%dmin | due=%s | %s',
                $task->getId()?->toRfc4122(),
                $task->getPriorityScore() ?? '—',
                $task->getPriority()->value,
                $task->getEstimatedMinutes() ?? self::DEFAULT_ESTIMATE,
                $task->getDueOn()?->format('Y-m-d') ?? 'none',
                mb_substr($task->getTitle(), 0, 120),
            );
        }

        return implode("\n", $lines);
    }
}
