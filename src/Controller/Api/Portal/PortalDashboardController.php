<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Repository\CustomerSystemRepository;
use App\Repository\TaskRepository;
use App\Service\Portal\PortalAccessResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Customer-portal dashboard — the post-login landing (wireframe screen 1).
 *
 * Aggregates ONLY data the portal already exposes truthfully:
 *   - open-ticket counts (from the visible tickets),
 *   - per-project progress (computed from completed vs. total visible tasks),
 *   - a systems active/total summary (only when the monitoring feature is on).
 *
 * DELIBERATELY OMITTED (no honest data source yet): the budget/retainer tile
 * (needs time-tracking aggregation + the deferred Capability×Role visibility),
 * blockers, and the activity feed. Added in later phases when backed.
 */
final class PortalDashboardController
{
    private const HIGH_PRIORITIES = ['high', 'urgent'];

    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly TaskRepository $tasks,
        private readonly CustomerSystemRepository $systems,
    ) {}

    #[Route(
        path: '/v1/portal/dashboard',
        name: 'api_portal_dashboard',
        host: 'api.worktide.ddev.site',
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

        $projectsDto = array_map(function ($project) use ($perProject): array {
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

        return new JsonResponse([
            'openTickets' => ['total' => $open, 'highPriority' => $highPriority],
            'systems' => $this->systemsSummary(),
            'projects' => $projectsDto,
        ]);
    }

    /**
     * Active/total systems — only when the monitoring feature is on for this
     * workspace, otherwise null (the tile is hidden client-side).
     *
     * @return array{active: int, total: int}|null
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
        return ['active' => $active, 'total' => \count($systems)];
    }
}
