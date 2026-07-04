<?php

declare(strict_types=1);

namespace App\Service\Priority;

use App\Entity\Workspace;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Assembles the per-ticket inputs from the DB and runs {@see PriorityScorer}.
 * Single source of truth for the priority score: used both by the on-demand
 * reports endpoint (ProjectReportsController::priorityScores) and the
 * materializing command (worktide:priority:recompute).
 */
final class PriorityScoreCalculator
{
    public function __construct(
        private readonly Connection $db,
        private readonly PriorityScorer $priorityScorer,
    ) {}

    /**
     * Compute scores for every (optionally project-scoped) task in the workspace.
     *
     * @param string|null $projectBin binary UUID of a project to scope to, or null for the whole workspace
     *
     * @return array<string, array{score: int, blocked: bool, parts: list<array{label: string, contribution: int}>}>
     *         keyed by task UUID (RFC 4122)
     */
    public function computeForWorkspace(Workspace $workspace, ?string $projectBin = null): array
    {
        $wsParam = ['ws' => $workspace->getId()?->toBinary()];
        $wsType = ['ws' => ParameterType::BINARY];

        $sql = 'SELECT t.id AS id, t.priority AS priority, t.due_on AS dueOn, t.created_at AS createdAt,
                       t.estimated_minutes AS est, t.tracker_id AS trackerId,
                       s.is_completed AS completed, p.is_retainer AS retainer, p.customer_id AS customerId,
                       cu.revenue_cents AS revenueCents
                FROM tasks t
                JOIN task_statuses s ON s.id = t.status_id
                LEFT JOIN projects p ON p.id = t.project_id
                LEFT JOIN customers cu ON cu.id = p.customer_id
                WHERE t.workspace_id = :ws AND t.deleted_at IS NULL';
        $params = $wsParam;
        $types = $wsType;
        if ($projectBin !== null) {
            $sql .= ' AND t.project_id = :project';
            $params['project'] = $projectBin;
            $types['project'] = ParameterType::BINARY;
        }
        $rows = $this->db->fetchAllAssociative($sql, $params, $types);
        if ($rows === []) {
            return [];
        }

        // "Blocks" leverage: open successors per predecessor task.
        $blocks = [];
        $blockSql = 'SELECT d.predecessor_id AS pid, COUNT(*) AS c
                     FROM task_dependencies d
                     JOIN tasks st ON st.id = d.successor_id AND st.deleted_at IS NULL
                     JOIN task_statuses ss ON ss.id = st.status_id
                     WHERE d.workspace_id = :ws AND ss.is_completed = 0
                     GROUP BY d.predecessor_id';
        foreach ($this->db->fetchAllAssociative($blockSql, $wsParam, $wsType) as $r) {
            $blocks[Uuid::fromBinary($r['pid'])->toRfc4122()] = (int) $r['c'];
        }
        // "Is blocked": task has an open predecessor.
        $blocked = [];
        $blockedSql = 'SELECT DISTINCT d.successor_id AS sid
                       FROM task_dependencies d
                       JOIN tasks pt ON pt.id = d.predecessor_id AND pt.deleted_at IS NULL
                       JOIN task_statuses ps ON ps.id = pt.status_id
                       WHERE d.workspace_id = :ws AND ps.is_completed = 0';
        foreach ($this->db->fetchAllAssociative($blockedSql, $wsParam, $wsType) as $r) {
            $blocked[Uuid::fromBinary($r['sid'])->toRfc4122()] = true;
        }

        // Revenue distribution across the workspace's customers → percentile
        // ranking for the customer-value sub-score (falls back to a retainer
        // proxy for customers without synced revenue).
        $revenues = [];
        foreach ($this->db->fetchFirstColumn('SELECT revenue_cents FROM customers WHERE workspace_id = :ws AND revenue_cents > 0', $wsParam, $wsType) as $v) {
            $revenues[] = (int) $v;
        }
        sort($revenues);

        $settings = $workspace->getSettings() ?? [];
        $scoring = \is_array($settings['priorityScoring'] ?? null) ? $settings['priorityScoring'] : [];
        $weights = \is_array($scoring['weights'] ?? null) ? $scoring['weights'] : [];
        $trackerWeights = \is_array($scoring['trackerWeights'] ?? null) ? $scoring['trackerWeights'] : [];

        $tickets = [];
        foreach ($rows as $r) {
            $id = Uuid::fromBinary($r['id'])->toRfc4122();
            $trackerIri = $r['trackerId'] !== null ? '/v1/trackers/' . Uuid::fromBinary($r['trackerId'])->toRfc4122() : null;
            $tw = $trackerIri !== null && is_numeric($trackerWeights[$trackerIri] ?? null)
                ? (float) $trackerWeights[$trackerIri]
                : 1.0;
            $tickets[] = [
                'id' => $id,
                'priority' => $r['priority'] !== null ? (string) $r['priority'] : null,
                'dueOn' => $r['dueOn'] !== null ? new \DateTimeImmutable($r['dueOn']) : null,
                'createdAt' => new \DateTimeImmutable($r['createdAt']),
                'estimatedMinutes' => $r['est'] !== null ? (int) $r['est'] : null,
                'isOpen' => !(bool) $r['completed'],
                'customerScore' => $this->customerScore(
                    $r['revenueCents'] !== null ? (int) $r['revenueCents'] : null,
                    (bool) $r['retainer'],
                    $r['customerId'] !== null,
                    $revenues,
                ),
                'blocksOpenCount' => $blocks[$id] ?? 0,
                'demandCount' => 0,
                'isBlocked' => isset($blocked[$id]),
                'trackerWeight' => $tw,
            ];
        }

        return $this->priorityScorer->score($tickets, $weights, new \DateTimeImmutable());
    }

    /**
     * Customer-value sub-score (0–100): revenue percentile across the workspace
     * (+10 for retainers). Falls back to a retainer/has-customer proxy for
     * customers whose revenue hasn't been synced from lexoffice yet.
     *
     * @param list<int> $sortedRevenues ascending
     */
    private function customerScore(?int $revenueCents, bool $retainer, bool $hasCustomer, array $sortedRevenues): int
    {
        if ($revenueCents !== null && $revenueCents > 0 && $sortedRevenues !== []) {
            $n = \count($sortedRevenues);
            $le = 0;
            foreach ($sortedRevenues as $v) {
                if ($v <= $revenueCents) {
                    ++$le;
                } else {
                    break; // ascending
                }
            }

            return min(100, (int) round(($le / $n) * 100) + ($retainer ? 10 : 0));
        }

        return $retainer ? 70 : ($hasCustomer ? 45 : 30);
    }
}
