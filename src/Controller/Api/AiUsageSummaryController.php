<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Service\Llm\LlmBudgetGuard;
use App\Service\Llm\LlmRouter;
use App\Service\Llm\LlmTier;
use App\Service\Llm\ModelCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
        private readonly LlmBudgetGuard $budget,
        private readonly LlmRouter $router,
        private readonly ModelCatalog $catalog,
    ) {}

    #[Route(path: '/v1/ai-usage/summary', name: 'api_ai_usage_summary', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $workspace = $this->requireAdminWorkspace($request);

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
            'monthlyBudgetMicros' => $this->budget->budgetMicros($workspace),
            'monthSpentMicros' => $this->budget->monthSpentMicros($workspace),
            'routing' => $this->routingState($workspace),
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
     * Set the workspace's monthly AI budget (USD; 0 = unlimited). Owner/Admin only.
     *
     *   PUT /v1/ai-usage/budget   { "monthlyUsd": 50 }
     */
    #[Route(path: '/v1/ai-usage/budget', name: 'api_ai_usage_budget', methods: ['PUT'])]
    public function setBudget(Request $request): JsonResponse
    {
        $workspace = $this->requireAdminWorkspace($request);

        $body = json_decode($request->getContent(), true);
        $usd = \is_array($body) ? ($body['monthlyUsd'] ?? null) : null;
        if (!is_numeric($usd) || (float) $usd < 0) {
            throw new BadRequestHttpException('"monthlyUsd" must be a number >= 0.');
        }

        $micros = (int) round((float) $usd * 1_000_000);
        $settings = $workspace->getSettings() ?? [];
        $ai = \is_array($settings['ai'] ?? null) ? $settings['ai'] : [];
        $ai['monthlyBudgetMicros'] = $micros;
        $settings['ai'] = $ai;
        $workspace->setSettings($settings);
        $this->em->flush();

        return new JsonResponse(['monthlyBudgetMicros' => $micros]);
    }

    /**
     * Set the workspace's AI routing policy — the privacy lock + per-feature
     * tier overrides the {@see \App\Service\Llm\LlmRouter} reads. Owner/Admin only.
     *
     *   PUT /v1/ai-usage/routing
     *   {
     *     "forceLocal": true,
     *     "routing": { "reply": "cloud" },
     *     "models":  { "triage": "anthropic:claude-haiku-4-5" }
     *   }
     *
     * `forceLocal` forces every task-type local (data residency). `routing` maps
     * a feature to a {@see LlmTier} value; `models` pins a feature to a specific
     * {@see ModelCatalog} key. Unknown tier/key values are rejected; a null value
     * clears that feature's entry. Omitted keys are left as-is.
     */
    #[Route(path: '/v1/ai-usage/routing', name: 'api_ai_usage_routing', methods: ['PUT'])]
    public function setRouting(Request $request): JsonResponse
    {
        $workspace = $this->requireAdminWorkspace($request);

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new BadRequestHttpException('Expected a JSON object.');
        }

        $settings = $workspace->getSettings() ?? [];
        $ai = \is_array($settings['ai'] ?? null) ? $settings['ai'] : [];

        if (\array_key_exists('forceLocal', $body)) {
            $ai['forceLocal'] = (bool) $body['forceLocal'];
        }

        if (\array_key_exists('routing', $body)) {
            if (!\is_array($body['routing'])) {
                throw new BadRequestHttpException('"routing" must be an object of feature => tier.');
            }
            $routing = \is_array($ai['routing'] ?? null) ? $ai['routing'] : [];
            foreach ($body['routing'] as $feature => $tier) {
                if (!\is_string($feature) || $feature === '') {
                    throw new BadRequestHttpException('Routing keys must be non-empty feature names.');
                }
                if ($tier === null) {
                    unset($routing[$feature]);
                    continue;
                }
                if (LlmTier::tryFromLoose($tier) === null) {
                    throw new BadRequestHttpException(sprintf('Unknown tier "%s" for feature "%s".', (string) $tier, $feature));
                }
                $routing[$feature] = (string) $tier;
            }
            $ai['routing'] = $routing;
        }

        if (\array_key_exists('models', $body)) {
            if (!\is_array($body['models'])) {
                throw new BadRequestHttpException('"models" must be an object of feature => catalog key.');
            }
            $models = \is_array($ai['models'] ?? null) ? $ai['models'] : [];
            foreach ($body['models'] as $feature => $key) {
                if (!\is_string($feature) || $feature === '') {
                    throw new BadRequestHttpException('Model keys must be non-empty feature names.');
                }
                if ($key === null) {
                    unset($models[$feature]);
                    continue;
                }
                if (!\is_string($key) || $this->catalog->get($key) === null) {
                    throw new BadRequestHttpException(sprintf('Unknown model "%s" for feature "%s".', (string) $key, $feature));
                }
                $models[$feature] = $key;
            }
            $ai['models'] = $models;
        }

        $settings['ai'] = $ai;
        $workspace->setSettings($settings);
        $this->em->flush();

        return new JsonResponse($this->routingState($workspace));
    }

    /**
     * @return array{
     *     forceLocal: bool,
     *     localConfigured: bool,
     *     overrides: array<string, string>,
     *     models: array<string, string>,
     *     tiers: list<string>,
     *     features: list<array{feature: string, defaultTier: string}>,
     *     catalog: list<array{key: string, provider: string, label: string, inputPer1M: float, outputPer1M: float, residency: string, staysInEu: bool, speed: string, available: bool}>
     * }
     */
    private function routingState(Workspace $workspace): array
    {
        $ai = \is_array($workspace->getSettings()['ai'] ?? null) ? $workspace->getSettings()['ai'] : [];
        $overrides = [];
        foreach (\is_array($ai['routing'] ?? null) ? $ai['routing'] : [] as $feature => $tier) {
            if (\is_string($feature) && LlmTier::tryFromLoose($tier) !== null) {
                $overrides[$feature] = (string) $tier;
            }
        }
        $models = [];
        foreach (\is_array($ai['models'] ?? null) ? $ai['models'] : [] as $feature => $key) {
            if (\is_string($feature) && \is_string($key) && $this->catalog->get($key) !== null) {
                $models[$feature] = $key;
            }
        }

        return [
            'forceLocal' => ($ai['forceLocal'] ?? false) === true,
            'localConfigured' => $this->router->isLocalConfigured(),
            'overrides' => $overrides,
            'models' => $models,
            'tiers' => array_map(static fn (LlmTier $t): string => $t->value, LlmTier::cases()),
            'features' => array_map(
                fn (string $f): array => ['feature' => $f, 'defaultTier' => $this->router->defaultTierFor($f)->value],
                LlmRouter::KNOWN_FEATURES,
            ),
            'catalog' => array_map(
                fn ($m): array => [
                    'key' => $m->key,
                    'provider' => $m->provider,
                    'label' => $m->label,
                    'inputPer1M' => $m->inputPer1M,
                    'outputPer1M' => $m->outputPer1M,
                    'residency' => $m->residency->value,
                    'staysInEu' => $m->residency->staysInEu(),
                    'speed' => $m->speed,
                    'available' => $this->router->isProviderConfigured($m->provider),
                ],
                $this->catalog->all(),
            ),
        ];
    }

    /** Resolve + require an Owner/Admin membership for the caller's workspace. */
    private function requireAdminWorkspace(Request $request): Workspace
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }
        $workspace = $this->resolveWorkspace($request, $user);
        if ($workspace === null) {
            throw new AccessDeniedHttpException('No workspace context.');
        }
        $member = $this->em->getRepository(WorkspaceMember::class)->findOneBy(['workspace' => $workspace, 'user' => $user]);
        if ($member === null || !\in_array($member->getRole(), [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true)) {
            throw new AccessDeniedHttpException('AI cost data is restricted to workspace admins.');
        }

        return $workspace;
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
