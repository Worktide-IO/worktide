<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Project;
use App\Entity\ProjectVersion;
use App\Entity\Sprint;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Security\Voter\WorktidePermission;
use App\Service\Reports\CumulativeFlowReconstructor;
use App\Service\Reports\CycleTimeCalculator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Project-level analytics endpoints used by the Reports SPA tabs.
 *
 *   GET /v1/reports/burndown?project=<uuid>&from=ISO&to=ISO
 *   GET /v1/reports/burndown?version=<uuid>&from=ISO&to=ISO
 *      → Daily series of "open task count" between from/to.
 *
 *   GET /v1/reports/created-vs-resolved?from=ISO&to=ISO[&project=<uuid>][&bucket=day|week]
 *      → Two series (created, resolved) per bucket — drives the
 *        "is the team keeping up?" line chart.
 *
 *   GET /v1/reports/cycle-time?from=ISO&to=ISO[&project=<uuid>]
 *      → Per-task cycle time (first status change → closed_on) for tasks
 *        closed in the range: scatter `points`, distribution `percentiles`
 *        (p50/p85/p95 hours), `averageHours` and `count`. See
 *        {@see CycleTimeCalculator} for the "work started" heuristic.
 *
 * All endpoints respect workspace scoping (the standard
 * WorkspaceScopeExtension can't cover raw DBAL queries, so each
 * resolves the workspace explicitly via X-Workspace-Id or membership).
 * Project / version filters are voter-checked before the SQL fires —
 * a leaked URL never exposes another tenant's data.
 *
 * Date range is capped at 365 days to keep aggregation cheap.
 */
final class ProjectReportsController
{
    private const MAX_RANGE_DAYS = 365;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly CumulativeFlowReconstructor $cumulativeFlow,
        private readonly CycleTimeCalculator $cycleTimeCalc,
    ) {}

    #[Route(
        path: '/v1/reports/burndown',
        name: 'api_report_burndown',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function burndown(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $workspace = $this->resolveWorkspace($request, $user)
            ?? throw new BadRequestHttpException('Workspace could not be determined.');

        $from = $this->parseRequiredDate($request, 'from');
        $to = $this->parseRequiredDate($request, 'to');
        $this->assertRange($from, $to);

        $projectId = $request->query->get('project');
        $versionId = $request->query->get('version');
        $sprintId = $request->query->get('sprint');
        if (!$projectId && !$versionId && !$sprintId) {
            throw new BadRequestHttpException('Either `project`, `version` or `sprint` query parameter is required.');
        }

        // Voter-check the scoping entity so a tenant boundary leak is
        // impossible regardless of the workspace header value.
        $projectBin = null;
        $versionBin = null;
        $sprintBin = null;
        if ($projectId) {
            $project = $this->loadEntity(Project::class, $projectId);
            if ($project->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()
                || !$this->security->isGranted(WorktidePermission::VIEW, $project)) {
                throw new AccessDeniedHttpException();
            }
            $projectBin = $project->getId()?->toBinary();
        }
        if ($versionId) {
            $version = $this->loadEntity(ProjectVersion::class, $versionId);
            if ($version->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()
                || !$this->security->isGranted(WorktidePermission::VIEW, $version)) {
                throw new AccessDeniedHttpException();
            }
            $versionBin = $version->getId()?->toBinary();
        }
        if ($sprintId) {
            $sprint = $this->loadEntity(Sprint::class, $sprintId);
            if ($sprint->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()
                || !$this->security->isGranted(WorktidePermission::VIEW, $sprint)) {
                throw new AccessDeniedHttpException();
            }
            $sprintBin = $sprint->getId()?->toBinary();
        }

        // Pull every Task that's in the scope, with createdAt + closedOn,
        // so the burndown is computed in-PHP. Burndowns are bounded by
        // hundreds-of-tasks scale; doing the date-walk in PHP is simpler
        // than a recursive CTE and equally fast at this size.
        $sql = 'SELECT t.created_at AS createdAt, t.closed_on AS closedOn
                FROM tasks t
                WHERE t.workspace_id = :ws
                  AND t.deleted_at IS NULL
                  AND t.created_at < :to';
        $params = ['ws' => $workspace->getId()?->toBinary(), 'to' => $to->format('Y-m-d 23:59:59')];
        $types = ['ws' => ParameterType::BINARY, 'to' => ParameterType::STRING];
        if ($projectBin !== null) {
            $sql .= ' AND t.project_id = :project';
            $params['project'] = $projectBin;
            $types['project'] = ParameterType::BINARY;
        }
        if ($versionBin !== null) {
            $sql .= ' AND t.fixed_version_id = :version';
            $params['version'] = $versionBin;
            $types['version'] = ParameterType::BINARY;
        }
        if ($sprintBin !== null) {
            $sql .= ' AND t.sprint_id = :sprint';
            $params['sprint'] = $sprintBin;
            $types['sprint'] = ParameterType::BINARY;
        }
        $rows = $this->db->fetchAllAssociative($sql, $params, $types);

        $series = [];
        $cursor = $from->setTime(0, 0);
        $endCursor = $to->setTime(23, 59, 59);
        while ($cursor <= $endCursor) {
            $cutoff = $cursor->setTime(23, 59, 59);
            $open = 0;
            foreach ($rows as $r) {
                $createdAt = new \DateTimeImmutable($r['createdAt']);
                if ($createdAt > $cutoff) {
                    continue;
                }
                $closedOn = $r['closedOn'] ? new \DateTimeImmutable($r['closedOn']) : null;
                if ($closedOn === null || $closedOn > $cutoff) {
                    $open++;
                }
            }
            $series[] = [
                'date' => $cursor->format('Y-m-d'),
                'open' => $open,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return new JsonResponse([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'project' => $projectId,
            'version' => $versionId,
            'sprint' => $sprintId,
            'totalTasks' => count($rows),
            'series' => $series,
        ]);
    }

    /**
     * Cumulative Flow Diagram: per-day count of tasks in each status.
     *
     * Current task rows only reveal the status *now*; the historical bands are
     * reconstructed by replaying `status` change events (recorded by
     * {@see \App\EventSubscriber\DomainEventEmitterSubscriber} as
     * `payload.status = {from, to}`) in {@see CumulativeFlowReconstructor}.
     *
     * Caveats: tasks deleted before now are absent (same as burndown), and a
     * status change that predates the event log is invisible — such a task
     * shows its earliest known status. Statuses removed from the workspace are
     * not rendered as bands.
     */
    #[Route(
        path: '/v1/reports/cumulative-flow',
        name: 'api_report_cumulative_flow',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function cumulativeFlow(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $workspace = $this->resolveWorkspace($request, $user)
            ?? throw new BadRequestHttpException('Workspace could not be determined.');

        $from = $this->parseRequiredDate($request, 'from');
        $to = $this->parseRequiredDate($request, 'to');
        $this->assertRange($from, $to);

        $projectId = $request->query->get('project');
        $versionId = $request->query->get('version');
        if (!$projectId && !$versionId) {
            throw new BadRequestHttpException('Either `project` or `version` query parameter is required.');
        }

        // Voter-check the scoping entity — identical guard to burndown.
        $projectBin = null;
        $versionBin = null;
        if ($projectId) {
            $project = $this->loadEntity(Project::class, $projectId);
            if ($project->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()
                || !$this->security->isGranted(WorktidePermission::VIEW, $project)) {
                throw new AccessDeniedHttpException();
            }
            $projectBin = $project->getId()?->toBinary();
        }
        if ($versionId) {
            $version = $this->loadEntity(ProjectVersion::class, $versionId);
            if ($version->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()
                || !$this->security->isGranted(WorktidePermission::VIEW, $version)) {
                throw new AccessDeniedHttpException();
            }
            $versionBin = $version->getId()?->toBinary();
        }

        // In-scope tasks with their current status, for the replay.
        $sql = 'SELECT t.id AS id, t.created_at AS createdAt, t.status_id AS statusId
                FROM tasks t
                WHERE t.workspace_id = :ws
                  AND t.deleted_at IS NULL
                  AND t.created_at < :to';
        $params = ['ws' => $workspace->getId()?->toBinary(), 'to' => $to->format('Y-m-d 23:59:59')];
        $types = ['ws' => ParameterType::BINARY, 'to' => ParameterType::STRING];
        if ($projectBin !== null) {
            $sql .= ' AND t.project_id = :project';
            $params['project'] = $projectBin;
            $types['project'] = ParameterType::BINARY;
        }
        if ($versionBin !== null) {
            $sql .= ' AND t.fixed_version_id = :version';
            $params['version'] = $versionBin;
            $types['version'] = ParameterType::BINARY;
        }
        $tasks = [];
        foreach ($this->db->fetchAllAssociative($sql, $params, $types) as $r) {
            $tasks[] = [
                'id' => Uuid::fromBinary($r['id'])->toRfc4122(),
                'createdAt' => new \DateTimeImmutable($r['createdAt']),
                'currentStatusId' => Uuid::fromBinary($r['statusId'])->toRfc4122(),
            ];
        }

        // Status-change events for this workspace's tasks. The flat-string
        // `status` of a task.created snapshot is skipped via `$.status.to`,
        // leaving only the {from,to} changesets. The reconstructor ignores
        // events for tasks outside the project/version scope.
        $events = [];
        if ($tasks !== []) {
            $eventSql = "SELECT aggregate_id AS taskId, occurred_at AS occurredAt, payload
                         FROM domain_events
                         WHERE aggregate_type = 'Task'
                           AND workspace_id = :ws
                           AND JSON_EXTRACT(payload, '$.status.to') IS NOT NULL";
            foreach ($this->db->fetchAllAssociative($eventSql, ['ws' => $workspace->getId()?->toBinary()], ['ws' => ParameterType::BINARY]) as $r) {
                $payload = json_decode((string) $r['payload'], true);
                $status = \is_array($payload) ? ($payload['status'] ?? null) : null;
                if (!\is_array($status) || !\is_string($status['from'] ?? null) || !\is_string($status['to'] ?? null)) {
                    continue;
                }
                $events[] = [
                    'taskId' => Uuid::fromBinary($r['taskId'])->toRfc4122(),
                    'from' => $status['from'],
                    'to' => $status['to'],
                    'occurredAt' => new \DateTimeImmutable($r['occurredAt']),
                ];
            }
        }

        // Band metadata — workspace statuses ordered for stacking (completed
        // last so the frontend can render them at the bottom).
        $statuses = [];
        $statusEntities = $this->em->getRepository(TaskStatus::class)
            ->findBy(['workspace' => $workspace], ['position' => 'ASC']);
        foreach ($statusEntities as $s) {
            $statuses[] = [
                'id' => $s->getId()?->toRfc4122(),
                'name' => $s->getName(),
                'color' => $s->getColor(),
                'position' => $s->getPosition(),
                'isCompleted' => $s->isCompleted(),
            ];
        }

        return new JsonResponse([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'project' => $projectId,
            'version' => $versionId,
            'statuses' => $statuses,
            'series' => $this->cumulativeFlow->build($tasks, $events, $from, $to),
        ]);
    }

    /**
     * Velocity per sprint for a project: how much work each sprint committed
     * vs completed. Size is {@see Task::$estimatedMinutes} (returned as
     * minutes; the SPA shows hours); a task counts as completed once it is
     * closed (`closed_on` set). Task counts are reported alongside so a team
     * tracking by count rather than estimate still gets a signal.
     */
    #[Route(
        path: '/v1/reports/velocity',
        name: 'api_report_velocity',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function velocity(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $workspace = $this->resolveWorkspace($request, $user)
            ?? throw new BadRequestHttpException('Workspace could not be determined.');

        $projectId = $request->query->get('project');
        if (!$projectId) {
            throw new BadRequestHttpException('`project` query parameter is required.');
        }
        $project = $this->loadEntity(Project::class, $projectId);
        if ($project->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()
            || !$this->security->isGranted(WorktidePermission::VIEW, $project)) {
            throw new AccessDeniedHttpException();
        }

        // One pass: per sprint, committed = all assigned tasks, completed =
        // the closed subset. Sprints with no tasks still appear (LEFT JOIN).
        $sql = 'SELECT s.id AS id, s.name AS name, s.start_date AS startDate,
                       s.end_date AS endDate, s.state AS state,
                       COUNT(t.id) AS committedCount,
                       COALESCE(SUM(t.estimated_minutes), 0) AS committedMinutes,
                       COALESCE(SUM(CASE WHEN t.closed_on IS NOT NULL THEN 1 ELSE 0 END), 0) AS completedCount,
                       COALESCE(SUM(CASE WHEN t.closed_on IS NOT NULL THEN t.estimated_minutes ELSE 0 END), 0) AS completedMinutes
                FROM sprints s
                LEFT JOIN tasks t ON t.sprint_id = s.id AND t.deleted_at IS NULL
                WHERE s.project_id = :project AND s.deleted_at IS NULL
                GROUP BY s.id
                ORDER BY (s.start_date IS NULL), s.start_date ASC, s.position ASC';
        $rows = $this->db->fetchAllAssociative(
            $sql,
            ['project' => $project->getId()?->toBinary()],
            ['project' => ParameterType::BINARY],
        );

        $sprints = array_map(static fn (array $r): array => [
            'id' => Uuid::fromBinary($r['id'])->toRfc4122(),
            'name' => $r['name'],
            'startDate' => $r['startDate'],
            'endDate' => $r['endDate'],
            'state' => $r['state'],
            'committedCount' => (int) $r['committedCount'],
            'committedMinutes' => (int) $r['committedMinutes'],
            'completedCount' => (int) $r['completedCount'],
            'completedMinutes' => (int) $r['completedMinutes'],
        ], $rows);

        return new JsonResponse(['project' => $projectId, 'sprints' => $sprints]);
    }

    #[Route(
        path: '/v1/reports/created-vs-resolved',
        name: 'api_report_created_vs_resolved',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function createdVsResolved(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $workspace = $this->resolveWorkspace($request, $user)
            ?? throw new BadRequestHttpException('Workspace could not be determined.');

        $from = $this->parseRequiredDate($request, 'from');
        $to = $this->parseRequiredDate($request, 'to');
        $this->assertRange($from, $to);

        $bucket = $request->query->get('bucket', 'day');
        if (!\in_array($bucket, ['day', 'week'], true)) {
            throw new BadRequestHttpException('bucket must be `day` or `week`.');
        }

        $projectBin = null;
        $projectId = $request->query->get('project');
        if ($projectId) {
            $project = $this->loadEntity(Project::class, $projectId);
            if ($project->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()
                || !$this->security->isGranted(WorktidePermission::VIEW, $project)) {
                throw new AccessDeniedHttpException();
            }
            $projectBin = $project->getId()?->toBinary();
        }

        $bucketExprCreated = $bucket === 'day'
            ? "DATE(t.created_at)"
            : "DATE_SUB(DATE(t.created_at), INTERVAL WEEKDAY(t.created_at) DAY)";
        $bucketExprClosed = $bucket === 'day'
            ? "DATE(t.closed_on)"
            : "DATE_SUB(DATE(t.closed_on), INTERVAL WEEKDAY(t.closed_on) DAY)";

        $params = [
            'ws' => $workspace->getId()?->toBinary(),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d 23:59:59'),
        ];
        $types = [
            'ws' => ParameterType::BINARY,
            'from' => ParameterType::STRING,
            'to' => ParameterType::STRING,
        ];
        $projectClause = '';
        if ($projectBin !== null) {
            $projectClause = ' AND t.project_id = :project';
            $params['project'] = $projectBin;
            $types['project'] = ParameterType::BINARY;
        }

        $sql = "
            SELECT bucket, SUM(created) AS created, SUM(resolved) AS resolved
            FROM (
                SELECT $bucketExprCreated AS bucket, 1 AS created, 0 AS resolved
                FROM tasks t
                WHERE t.workspace_id = :ws
                  AND t.deleted_at IS NULL
                  AND t.created_at BETWEEN :from AND :to
                  $projectClause
                UNION ALL
                SELECT $bucketExprClosed AS bucket, 0 AS created, 1 AS resolved
                FROM tasks t
                WHERE t.workspace_id = :ws
                  AND t.deleted_at IS NULL
                  AND t.closed_on BETWEEN :from AND :to
                  $projectClause
            ) AS combined
            GROUP BY bucket
            ORDER BY bucket
        ";

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);

        // Fill empty buckets so the chart doesn't have date gaps.
        $byBucket = [];
        foreach ($rows as $r) {
            $byBucket[$r['bucket']] = [
                'created' => (int) $r['created'],
                'resolved' => (int) $r['resolved'],
            ];
        }
        $series = [];
        $cursor = $from->setTime(0, 0);
        $step = $bucket === 'day' ? '+1 day' : '+1 week';
        if ($bucket === 'week') {
            // align cursor to the same Monday MySQL uses
            $cursor = $cursor->modify('-' . ((int) $cursor->format('N') - 1) . ' days');
        }
        while ($cursor <= $to) {
            $key = $cursor->format('Y-m-d');
            $series[] = [
                'bucket' => $key,
                'created' => $byBucket[$key]['created'] ?? 0,
                'resolved' => $byBucket[$key]['resolved'] ?? 0,
            ];
            $cursor = $cursor->modify($step);
        }

        return new JsonResponse([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'bucket' => $bucket,
            'project' => $projectId,
            'series' => $series,
        ]);
    }

    #[Route(
        path: '/v1/reports/cycle-time',
        name: 'api_report_cycle_time',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function cycleTime(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $workspace = $this->resolveWorkspace($request, $user)
            ?? throw new BadRequestHttpException('Workspace could not be determined.');

        $from = $this->parseRequiredDate($request, 'from');
        $to = $this->parseRequiredDate($request, 'to');
        $this->assertRange($from, $to);

        $projectBin = null;
        $projectId = $request->query->get('project');
        if ($projectId) {
            $project = $this->loadEntity(Project::class, $projectId);
            if ($project->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()
                || !$this->security->isGranted(WorktidePermission::VIEW, $project)) {
                throw new AccessDeniedHttpException();
            }
            $projectBin = $project->getId()?->toBinary();
        }

        // Completed tasks in range. Cycle time = first status change ("work
        // started") to closed_on; see CycleTimeCalculator for the fallback when
        // a task has no recorded status change (bulk-imported already-closed).
        $sql = 'SELECT t.id AS id, t.identifier AS identifier, t.created_at AS createdAt, t.closed_on AS closedOn
                FROM tasks t
                WHERE t.workspace_id = :ws
                  AND t.deleted_at IS NULL
                  AND t.closed_on IS NOT NULL
                  AND t.closed_on BETWEEN :from AND :to';
        $params = [
            'ws' => $workspace->getId()?->toBinary(),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d 23:59:59'),
        ];
        $types = [
            'ws' => ParameterType::BINARY,
            'from' => ParameterType::STRING,
            'to' => ParameterType::STRING,
        ];
        if ($projectBin !== null) {
            $sql .= ' AND t.project_id = :project';
            $params['project'] = $projectBin;
            $types['project'] = ParameterType::BINARY;
        }

        $tasks = [];
        foreach ($this->db->fetchAllAssociative($sql, $params, $types) as $r) {
            $tasks[] = [
                'id' => Uuid::fromBinary($r['id'])->toRfc4122(),
                'identifier' => $r['identifier'] !== null ? (string) $r['identifier'] : null,
                'createdAt' => new \DateTimeImmutable($r['createdAt']),
                'closedOn' => new \DateTimeImmutable($r['closedOn']),
            ];
        }

        // Earliest status-change per task marks "work started" (same event
        // source the CFD replay uses; non-status updates are filtered out).
        $events = [];
        if ($tasks !== []) {
            $eventSql = "SELECT aggregate_id AS taskId, occurred_at AS occurredAt
                         FROM domain_events
                         WHERE aggregate_type = 'Task'
                           AND workspace_id = :ws
                           AND JSON_EXTRACT(payload, '$.status.to') IS NOT NULL";
            foreach ($this->db->fetchAllAssociative($eventSql, ['ws' => $workspace->getId()?->toBinary()], ['ws' => ParameterType::BINARY]) as $r) {
                $events[] = [
                    'taskId' => Uuid::fromBinary($r['taskId'])->toRfc4122(),
                    'occurredAt' => new \DateTimeImmutable($r['occurredAt']),
                ];
            }
        }

        $result = $this->cycleTimeCalc->compute($tasks, $events);

        return new JsonResponse([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'project' => $projectId,
            'count' => $result['count'],
            'averageHours' => $result['averageHours'],
            'percentiles' => $result['percentiles'],
            'points' => $result['points'],
        ]);
    }

    /**
     * Open-task counts per project in one grouped query — replaces the SPA
     * fetching every open task just to tally them client-side. Keyed by
     * project IRI so the caller can look up by `task.project`.
     */
    #[Route(
        path: '/v1/reports/open-task-counts',
        name: 'api_report_open_task_counts',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function openTaskCounts(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $workspace = $this->resolveWorkspace($request, $user)
            ?? throw new BadRequestHttpException('Workspace could not be determined.');

        $sql = 'SELECT project_id, COUNT(*) AS c
                FROM tasks
                WHERE workspace_id = :ws
                  AND deleted_at IS NULL
                  AND closed_on IS NULL
                  AND project_id IS NOT NULL
                GROUP BY project_id';
        $counts = [];
        foreach (
            $this->db->fetchAllAssociative($sql, ['ws' => $workspace->getId()?->toBinary()], ['ws' => ParameterType::BINARY]) as $r
        ) {
            $counts['/v1/projects/' . Uuid::fromBinary($r['project_id'])->toRfc4122()] = (int) $r['c'];
        }

        return new JsonResponse(['counts' => $counts]);
    }

    // --- helpers --------------------------------------------------------

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }
        return $user;
    }

    private function parseRequiredDate(Request $request, string $key): \DateTimeImmutable
    {
        $raw = $request->query->get($key);
        if (!\is_string($raw) || $raw === '') {
            throw new BadRequestHttpException(sprintf('Query parameter %s is required.', $key));
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new BadRequestHttpException(sprintf('%s must be a valid ISO 8601 date.', $key));
        }
    }

    private function assertRange(\DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        if ($from > $to) {
            throw new BadRequestHttpException('`from` must be before `to`.');
        }
        $days = (int) $from->diff($to)->days;
        if ($days > self::MAX_RANGE_DAYS) {
            throw new BadRequestHttpException(sprintf('Date range too large; max %d days.', self::MAX_RANGE_DAYS));
        }
    }

    private function resolveWorkspace(Request $request, User $user): ?Workspace
    {
        $hdr = $request->headers->get('X-Workspace-Id') ?? $request->query->get('workspace');
        if (\is_string($hdr) && $hdr !== '') {
            try {
                return $this->em->find(Workspace::class, Uuid::fromString($hdr));
            } catch (\InvalidArgumentException) {
                return null;
            }
        }
        $membership = $this->em->getRepository(WorkspaceMember::class)->findOneBy(['user' => $user]);
        return $membership?->getWorkspace();
    }

    /**
     * @template T of object
     * @param class-string<T> $cls
     * @return T
     */
    private function loadEntity(string $cls, string $id): object
    {
        try {
            $entity = $this->em->find($cls, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException(sprintf('%s id is not a valid UUID.', $cls));
        }
        if ($entity === null) {
            throw new NotFoundHttpException(sprintf('%s %s not found.', $cls, $id));
        }
        return $entity;
    }
}
