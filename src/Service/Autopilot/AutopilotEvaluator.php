<?php

declare(strict_types=1);

namespace App\Service\Autopilot;

use App\Entity\Autopilot;
use App\Entity\DomainEventLog;
use App\Repository\AutopilotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Walks every enabled Autopilot, evaluates each rule against current project
 * state, and emits a domain event ("autopilot.<kind>.triggered") when a rule
 * matches. Idempotent within a tick: stamps lastEvaluatedAt + lastTriggeredAt
 * so a follow-up "did we already alert today" check is trivial.
 *
 * Called from RunAutopilotsCommand (cron); also callable from API endpoints
 * for on-demand evaluation.
 */
final class AutopilotEvaluator
{
    public function __construct(
        private readonly AutopilotRepository $autopilots,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{evaluated: int, triggered: int, fired: list<array{autopilotId: string, projectKey: string, kind: string, payload: array<string, mixed>}>}
     */
    public function evaluateAll(): array
    {
        /** @var list<Autopilot> $all */
        $all = $this->autopilots->findBy(['isEnabled' => true]);
        $evaluated = 0;
        $triggered = 0;
        $fired = [];

        foreach ($all as $autopilot) {
            $evaluated++;
            $autopilot->markEvaluated();

            foreach ($autopilot->getRules() as $rule) {
                if (($rule['isEnabled'] ?? true) === false) {
                    continue;
                }
                $kind = $rule['kind'] ?? null;
                if (!\is_string($kind)) {
                    continue;
                }
                $config = $rule['config'] ?? [];
                \assert(\is_array($config));

                $result = match ($kind) {
                    'budget_threshold' => $this->evaluateBudgetThreshold($autopilot, $config),
                    'overdue_tasks'    => $this->evaluateOverdueTasks($autopilot, $config),
                    'due_soon'         => $this->evaluateDueSoon($autopilot, $config),
                    default            => null,
                };
                if ($result === null) {
                    continue;
                }
                $autopilot->markTriggered();
                $triggered++;
                $fired[] = [
                    'autopilotId' => $autopilot->getId()?->toRfc4122() ?? '',
                    'projectKey' => $autopilot->getProject()->getKey(),
                    'kind' => $kind,
                    'payload' => $result,
                ];
                $this->writeEventLog($autopilot, $kind, $result);
            }
        }

        $this->em->flush();
        return ['evaluated' => $evaluated, 'triggered' => $triggered, 'fired' => $fired];
    }

    /** @param array<string, mixed> $config @return array<string, mixed>|null */
    private function evaluateBudgetThreshold(Autopilot $autopilot, array $config): ?array
    {
        $project = $autopilot->getProject();
        $budget = $project->getBudgetMinutes();
        if ($budget === null || $budget <= 0) {
            return null;
        }
        $percent = (float) ($config['percent'] ?? 80);

        $tracked = (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(te.durationMinutes), 0)')
            ->from('App\Entity\TimeEntry', 'te')
            ->where('te.project = :p')
            ->setParameter('p', $project)
            ->getQuery()
            ->getSingleScalarResult();

        $consumed = $budget > 0 ? ($tracked / $budget) * 100.0 : 0.0;
        if ($consumed < $percent) {
            return null;
        }
        return [
            'projectId' => $project->getId()?->toRfc4122(),
            'projectKey' => $project->getKey(),
            'budgetMinutes' => $budget,
            'trackedMinutes' => $tracked,
            'consumedPercent' => round($consumed, 1),
            'thresholdPercent' => $percent,
        ];
    }

    /** @param array<string, mixed> $config @return array<string, mixed>|null */
    private function evaluateOverdueTasks(Autopilot $autopilot, array $config): ?array
    {
        $project = $autopilot->getProject();
        $grace = (int) ($config['gracePeriodDays'] ?? 0);
        $cutoff = (new \DateTimeImmutable())->modify('-' . $grace . ' days');

        $overdue = (int) $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from('App\Entity\Task', 't')
            ->innerJoin('t.status', 's')
            ->where('t.project = :p')
            ->andWhere('t.dueOn IS NOT NULL AND t.dueOn < :cutoff')
            ->andWhere('s.isCompleted = false')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('p', $project)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();

        if ($overdue === 0) {
            return null;
        }
        return [
            'projectId' => $project->getId()?->toRfc4122(),
            'projectKey' => $project->getKey(),
            'overdueCount' => $overdue,
            'gracePeriodDays' => $grace,
        ];
    }

    /** @param array<string, mixed> $config @return array<string, mixed>|null */
    private function evaluateDueSoon(Autopilot $autopilot, array $config): ?array
    {
        $project = $autopilot->getProject();
        $within = (int) ($config['withinDays'] ?? 3);
        $now = new \DateTimeImmutable();
        $horizon = $now->modify('+' . $within . ' days');

        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from('App\Entity\Task', 't')
            ->innerJoin('t.status', 's')
            ->where('t.project = :p')
            ->andWhere('t.dueOn IS NOT NULL AND t.dueOn BETWEEN :now AND :horizon')
            ->andWhere('s.isCompleted = false')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('p', $project)
            ->setParameter('now', $now)
            ->setParameter('horizon', $horizon)
            ->getQuery()
            ->getSingleScalarResult();

        if ($count === 0) {
            return null;
        }
        return [
            'projectId' => $project->getId()?->toRfc4122(),
            'projectKey' => $project->getKey(),
            'dueSoonCount' => $count,
            'withinDays' => $within,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function writeEventLog(Autopilot $autopilot, string $kind, array $payload): void
    {
        $this->em->persist(new DomainEventLog(
            name: 'autopilot.' . $kind . '.triggered',
            aggregateType: 'Autopilot',
            aggregateId: $autopilot->getId(),
            workspace: $autopilot->getWorkspace(),
            actor: null,
            payload: $payload,
        ));
    }
}
