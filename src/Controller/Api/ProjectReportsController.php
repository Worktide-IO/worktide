<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Project;
use App\Entity\ProjectVersion;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Security\Voter\WorktidePermission;
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
 *   GET /v1/reports/cycle-time?from=ISO&to=ISO[&project=<uuid>][&bucket=week]
 *      → Per-bucket average cycle time in hours (closedOn - createdAt)
 *        plus a count of tasks closed in that bucket.
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
        if (!$projectId && !$versionId) {
            throw new BadRequestHttpException('Either `project` or `version` query parameter is required.');
        }

        // Voter-check the scoping entity so a tenant boundary leak is
        // impossible regardless of the workspace header value.
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
            'totalTasks' => count($rows),
            'series' => $series,
        ]);
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

        $bucket = $request->query->get('bucket', 'week');
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

        $bucketExpr = $bucket === 'day'
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

        // Cycle time = (closed_on - created_at) in hours, averaged per
        // bucket. TIMESTAMPDIFF gives integer hours which is fine for
        // the chart (sub-hour precision rarely matters here).
        $sql = "
            SELECT $bucketExpr AS bucket,
                   COUNT(*) AS resolved,
                   AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.closed_on)) AS avgHours,
                   MIN(TIMESTAMPDIFF(HOUR, t.created_at, t.closed_on)) AS minHours,
                   MAX(TIMESTAMPDIFF(HOUR, t.created_at, t.closed_on)) AS maxHours
            FROM tasks t
            WHERE t.workspace_id = :ws
              AND t.deleted_at IS NULL
              AND t.closed_on IS NOT NULL
              AND t.closed_on BETWEEN :from AND :to
              $projectClause
            GROUP BY bucket
            ORDER BY bucket
        ";

        $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        $series = array_map(fn ($r) => [
            'bucket' => $r['bucket'],
            'resolved' => (int) $r['resolved'],
            'avgHours' => round((float) $r['avgHours'], 1),
            'minHours' => (int) $r['minHours'],
            'maxHours' => (int) $r['maxHours'],
        ], $rows);

        return new JsonResponse([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'bucket' => $bucket,
            'project' => $projectId,
            'series' => $series,
        ]);
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
