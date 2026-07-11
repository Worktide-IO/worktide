<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\CustomerGoal;
use App\Repository\CustomerGoalRepository;
use App\Service\Portal\PortalAccessResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Customer-portal "Ziele" (goals) — read-only KPI goals for the customer
 * (wireframe screen 5, top half). Agency sets target/current/status; the
 * portal renders progress. Gated by the `ideas` feature flag (the Ziele &
 * Ideen screen).
 */
final class PortalGoalsController
{

    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly CustomerGoalRepository $goals,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route(
        path: '/v1/portal/goals',
        name: 'api_portal_goals_list',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('ideas');

        return new JsonResponse([
            'goals' => array_map(
                $this->goalDto(...),
                $this->goals->findForPortalCustomer($this->portal->customer()),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function goalDto(CustomerGoal $goal): array
    {
        $status = $goal->getStatus()->value;
        $target = $goal->getTargetValue();
        $current = $goal->getCurrentValue();

        // Progress only when both numbers are present and the target is > 0;
        // reached goals clamp to 100. Purely for the progress bar.
        $progressPct = null;
        if ($status === 'reached') {
            $progressPct = 100;
        } elseif ($target !== null && $target > 0 && $current !== null) {
            $progressPct = (int) min(100, max(0, round(($current / $target) * 100)));
        }

        return [
            'id' => $goal->getId()?->toRfc4122(),
            'title' => $goal->getTitle(),
            'description' => $goal->getDescription(),
            'unit' => $goal->getUnit(),
            'targetValue' => $target,
            'currentValue' => $current,
            'status' => $status,
            'statusLabel' => $this->translator->trans('label.goal_status.' . $status),
            'progressPct' => $progressPct,
            'targetDate' => $goal->getTargetDate()?->format('Y-m-d'),
        ];
    }
}
