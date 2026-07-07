<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\Project;
use App\Entity\Task;
use App\Repository\CustomerSystemRepository;
use App\Repository\DomainEventLogRepository;
use App\Repository\SystemIncidentRepository;
use App\Repository\TaskRepository;
use App\Repository\TimeEntryRepository;
use App\Service\Portal\PortalAccessResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Customer-portal dashboard — the post-login landing (wireframe screen 1).
 *
 * Every section is backed by a REAL data source, scoped to the customer's
 * visible projects/tickets:
 *   - open-ticket counts (from the visible tickets),
 *   - a retainer-budget tile (tracked minutes this month vs. the retainer
 *     projects' budgetMinutes — see {@see budget()} for the honest caveat),
 *   - per-project progress (completed vs. total visible tasks),
 *   - a systems active/total + open-incident summary (monitoring feature only),
 *   - blocked tickets (open predecessor via TaskDependency),
 *   - a curated activity feed (visible-ticket DomainEvents, actor redacted).
 *
 * The wireframe's per-contact Capability×Role gating (hide budget from some
 * contacts) is still deferred; today the budget tile shows whenever the
 * customer has a retainer project with a budget.
 */
final class PortalDashboardController
{
    private const HIGH_PRIORITIES = ['high', 'urgent'];

    /** DomainEvent name → customer-facing German label. Anything else is dropped. */
    private const ACTIVITY_LABELS = [
        'task.created' => 'Ticket erstellt',
        'task.updated' => 'Ticket aktualisiert',
    ];

    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly TaskRepository $tasks,
        private readonly CustomerSystemRepository $systems,
        private readonly TimeEntryRepository $timeEntries,
        private readonly SystemIncidentRepository $incidents,
        private readonly DomainEventLogRepository $events,
    ) {}

    #[Route(
        path: '/v1/portal/dashboard',
        name: 'api_portal_dashboard',
        methods: ['GET'],
    )]
    public function __invoke(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('dashboard');

        $projects = $this->portal->allowedProjects();
        $tickets = $this->tasks->findVisiblePortalTickets($projects);

        $open = 0;
        $highPriority = 0;
        /** @var array<string, array{total: int, completed: int}> $perProject */
        $perProject = [];

        foreach ($tickets as $task) {
            $completed = $task->getStatus()->isCompleted();
            $pid = $task->getProject()?->getId()?->toRfc4122();
            if ($pid !== null) {
                $perProject[$pid]['total'] = ($perProject[$pid]['total'] ?? 0) + 1;
                $perProject[$pid]['completed'] = ($perProject[$pid]['completed'] ?? 0) + ($completed ? 1 : 0);
            }
            if (!$completed) {
                $open++;
                if (\in_array($task->getPriority()->value, self::HIGH_PRIORITIES, true)) {
                    $highPriority++;
                }
            }
        }

        $projectsDto = array_map(function (Project $project) use ($perProject): array {
            $pid = $project->getId()?->toRfc4122();
            $counts = $perProject[$pid] ?? ['total' => 0, 'completed' => 0];
            $total = $counts['total'];
            $progressPct = $total > 0 ? (int) round(($counts['completed'] / $total) * 100) : 0;

            return [
                'id' => $pid,
                'name' => $project->getName(),
                'progressPct' => $progressPct,
                'openTasks' => $total - $counts['completed'],
            ];
        }, $projects);

        $blockers = array_map(static fn (Task $t): array => [
            'id' => $t->getId()?->toRfc4122(),
            'identifier' => $t->getIdentifier(),
            'title' => $t->getTitle(),
            'projectName' => $t->getProject()?->getName(),
        ], $this->tasks->findBlockedPortalTickets($projects));

        return new JsonResponse([
            'customerName' => $this->portal->customer()->getName(),
            'openTickets' => ['total' => $open, 'highPriority' => $highPriority],
            'budget' => $this->budget($projects),
            'systems' => $this->systemsSummary(),
            'projects' => $projectsDto,
            'blockers' => $blockers,
            'activity' => $this->activity($tickets),
        ]);
    }

    /**
     * Retainer-budget tile: tracked minutes THIS MONTH over the summed
     * budgetMinutes of the customer's retainer projects. Null when the customer
     * has no retainer project with a budget (tile hidden client-side).
     *
     * HONEST CAVEAT: the schema has no dedicated monthly hour quota. We read a
     * retainer project's `budgetMinutes` as its monthly allowance — the natural
     * meaning of a retainer budget — and compare it against this month's tracked
     * time. A dedicated monthly-quota field is a future refinement.
     *
     * @param list<Project> $projects
     * @return array{consumedMinutes: int, budgetMinutes: int, pct: int}|null
     */
    private function budget(array $projects): ?array
    {
        $retainers = array_values(array_filter(
            $projects,
            static fn (Project $p): bool => $p->isRetainer() && $p->getBudgetMinutes() !== null && $p->getBudgetMinutes() > 0,
        ));
        if ($retainers === []) {
            return null;
        }

        $budgetMinutes = array_sum(array_map(static fn (Project $p): int => (int) $p->getBudgetMinutes(), $retainers));
        $monthStart = new \DateTimeImmutable('first day of this month 00:00:00');
        $consumed = $this->timeEntries->sumMinutesForProjectsSince($retainers, $monthStart);

        return [
            'consumedMinutes' => $consumed,
            'budgetMinutes' => $budgetMinutes,
            'pct' => (int) round($consumed / $budgetMinutes * 100),
        ];
    }

    /**
     * Active/total systems + open-incident count — only when monitoring is on,
     * otherwise null (the tile is hidden client-side).
     *
     * @return array{active: int, total: int, openIncidents: int}|null
     */
    private function systemsSummary(): ?array
    {
        if (($this->portal->features()['monitoring'] ?? false) !== true) {
            return null;
        }
        $systems = $this->systems->findVisiblePortalSystems($this->portal->customer());
        $active = 0;
        foreach ($systems as $system) {
            if ($system->isActive()) {
                $active++;
            }
        }
        $openIncidents = 0;
        foreach ($this->incidents->findRecentForSystems($systems) as $incident) {
            if ($incident->isOpen()) {
                $openIncidents++;
            }
        }
        return ['active' => $active, 'total' => \count($systems), 'openIncidents' => $openIncidents];
    }

    /**
     * Curated activity feed. Scoped to VISIBLE ticket ids only; drops any event
     * whose name isn't whitelisted; the actor is reduced to "Sie" (the current
     * portal user) or "Agentur" — never staff PII; the raw payload is discarded.
     *
     * @param list<Task> $tickets
     * @return list<array<string, mixed>>
     */
    private function activity(array $tickets): array
    {
        if ($tickets === []) {
            return [];
        }

        /** @var array<string, Task> $byId */
        $byId = [];
        $ids = [];
        foreach ($tickets as $t) {
            $id = $t->getId();
            if ($id !== null) {
                $byId[$id->toRfc4122()] = $t;
                $ids[] = $id;
            }
        }

        $selfUserId = $this->portal->contact()->getLinkedUser()?->getId()?->toRfc4122();

        $out = [];
        foreach ($this->events->findRecentForAggregate('Task', $ids) as $event) {
            $label = self::ACTIVITY_LABELS[$event->getName()] ?? null;
            if ($label === null) {
                continue;
            }
            $task = $byId[$event->getAggregateId()?->toRfc4122() ?? ''] ?? null;
            $actorId = $event->getActor()?->getId()?->toRfc4122();

            $out[] = [
                'id' => $event->getId()?->toRfc4122(),
                'label' => $label,
                'actor' => $actorId !== null && $actorId === $selfUserId ? 'Sie' : 'Agentur',
                'ticketIdentifier' => $task?->getIdentifier(),
                'ticketTitle' => $task?->getTitle(),
                'occurredAt' => $event->getOccurredAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $out;
    }
}
