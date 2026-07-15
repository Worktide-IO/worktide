<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin AI cost/usage read-model for the KI-Kosten dashboard, aggregated over
 * {@see \App\Entity\LlmUsageLog}:
 *
 *   GET /v1/ai-usage/summary?days=30   (X-Workspace-Id honoured)
 *
 * Returns totals + breakdowns by feature, by model, and a per-day time series —
 * all scoped to the caller's workspace and restricted to workspace Owner/Admin
 * (spend is sensitive). `costMicros` is integer micro-USD (USD × 1e6); the SPA
 * divides for display.
 */
final class AiUsageSummaryController
{
    use ResolvesWorkspaceMembership;

    private const MAX_DAYS = 365;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(path: '/v1/ai-usage/summary', name: 'api_ai_usage_summary', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }
        $workspace = $this->resolveWorkspace($request, $user);
        if ($workspace === null) {
            throw new AccessDeniedHttpException('No workspace context.');
        }

        // Cost is admin-only: require Owner/Admin membership.
        $member = $this->em->getRepository(WorkspaceMember::class)->findOneBy(['workspace' => $workspace, 'user' => $user]);
        if ($member === null || !\in_array($member->getRole(), [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true)) {
            throw new AccessDeniedHttpException('AI cost data is restricted to workspace admins.');
        }

        $days = max(1, min(self::MAX_DAYS, (int) $request->query->get('days', '30')));
        $since = (new \DateTimeImmutable())->modify(sprintf('-%d days', $days))->format('Y-m-d H:i:s');
        $wsId = $workspace->getId()?->toBinary();

        $conn = $this->em->getConnection();
        $where = 'WHERE workspace_id = :ws AND created_at >= :since';
        $params = ['ws' => $wsId, 'since' => $since];

        $totals = $conn->executeQuery(
            "SELECT COUNT(*) calls, COALESCE(SUM(cost_micros),0) cost, COALESCE(SUM(input_tokens),0) in_t, COALESCE(SUM(output_tokens),0) out_t
             FROM llm_usage_logs {$where}",
            $params,
        )->fetchAssociative() ?: [];

        $byFeature = $conn->executeQuery(
            "SELECT COALESCE(feature, '(unattributed)') label, COALESCE(SUM(cost_micros),0) cost, COUNT(*) calls
             FROM llm_usage_logs {$where} GROUP BY label ORDER BY cost DESC",
            $params,
        )->fetchAllAssociative();

        $byModel = $conn->executeQuery(
            "SELECT model label, COALESCE(SUM(cost_micros),0) cost, COUNT(*) calls
             FROM llm_usage_logs {$where} GROUP BY model ORDER BY cost DESC",
            $params,
        )->fetchAllAssociative();

        $byDay = $conn->executeQuery(
            "SELECT DATE(created_at) day, COALESCE(SUM(cost_micros),0) cost, COUNT(*) calls
             FROM llm_usage_logs {$where} GROUP BY day ORDER BY day ASC",
            $params,
        )->fetchAllAssociative();

        return new JsonResponse([
            'periodDays' => $days,
            'currency' => 'USD',
            'totalCostMicros' => (int) ($totals['cost'] ?? 0),
            'callCount' => (int) ($totals['calls'] ?? 0),
            'totalInputTokens' => (int) ($totals['in_t'] ?? 0),
            'totalOutputTokens' => (int) ($totals['out_t'] ?? 0),
            'byFeature' => array_map($this->row(...), $byFeature),
            'byModel' => array_map($this->row(...), $byModel),
            'byDay' => array_map(
                static fn (array $r): array => [
                    'day' => (string) $r['day'],
                    'costMicros' => (int) $r['cost'],
                    'calls' => (int) $r['calls'],
                ],
                $byDay,
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $r
     * @return array{label: string, costMicros: int, calls: int}
     */
    private function row(array $r): array
    {
        return [
            'label' => (string) $r['label'],
            'costMicros' => (int) $r['cost'],
            'calls' => (int) $r['calls'],
        ];
    }
}
