<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\AIRecommendation;
use App\Entity\Enum\RecommendationKind;
use App\Entity\Enum\RecommendationStatus;
use App\Entity\Enum\RecommendationTarget;
use App\Entity\Workspace;
use App\Message\PlanDistributionMessage;
use App\Repository\AIRecommendationRepository;
use App\Service\Agent\CapabilityCatalog;
use App\Service\Ai\AgentActionPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Runs the distribution planner on the `ai_agents` transport: builds the
 * workspace capability catalog, asks the LLM for per-channel actions, and writes
 * each as a pending agent-action recommendation (target Workspace). A fresh plan
 * supersedes the workspace's still-pending agent-actions so they don't pile up.
 * Nothing is sent — a human accepts each into the normal egress-gated draft flow.
 */
#[AsMessageHandler]
final class PlanDistributionHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CapabilityCatalog $catalog,
        private readonly AgentActionPlanner $planner,
        private readonly AIRecommendationRepository $recommendations,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(PlanDistributionMessage $message): void
    {
        $workspaceId = $message->getWorkspaceId();
        $workspace = $this->em->find(Workspace::class, $workspaceId);
        if (!$workspace instanceof Workspace) {
            throw new UnrecoverableMessageHandlingException(sprintf('Workspace %s no longer exists; dropping distribution plan.', $workspaceId->toRfc4122()));
        }

        $capabilities = $this->catalog->forWorkspace($workspace);
        $actions = $this->planner->planDistribution($message->getContent(), $capabilities);
        if ($actions === []) {
            $this->logger->info('Distribution planner proposed no actions.', [
                'workspace' => $workspaceId->toRfc4122(),
                'capabilities' => \count($capabilities),
            ]);

            return;
        }

        // A new plan supersedes the workspace's still-pending agent actions.
        foreach ($this->recommendations->findPendingFor(RecommendationTarget::Workspace, $workspaceId, RecommendationKind::AgentAction) as $stale) {
            $stale->setStatus(RecommendationStatus::Superseded);
        }

        $model = $this->planner->getModel();
        foreach ($actions as $action) {
            $rationale = \is_string($action['rationale'] ?? null) ? $action['rationale'] : null;
            $recommendation = (new AIRecommendation())
                ->setWorkspace($workspace)
                ->setTarget(RecommendationTarget::Workspace)
                ->setTargetId($workspaceId)
                ->setKind(RecommendationKind::AgentAction)
                ->setStatus(RecommendationStatus::Pending)
                ->setSuggestion($action)
                ->setReasoning($rationale)
                ->setModel($model);
            $this->em->persist($recommendation);
        }
        $this->em->flush();

        $this->publish($workspace);
        $this->logger->info('Distribution plan produced agent-action recommendations.', [
            'workspace' => $workspaceId->toRfc4122(),
            'actions' => \count($actions),
        ]);
    }

    private function publish(Workspace $workspace): void
    {
        $wsId = $workspace->getId()?->toRfc4122();
        if ($wsId === null) {
            return;
        }
        try {
            $this->hub->publish(new Update(
                topics: ['worktide:workspace:' . $wsId . ':ai-recommendations'],
                data: json_encode(['kind' => 'agent_action']) ?: '{}',
                private: true,
            ));
        } catch (\Throwable $e) {
            $this->logger->debug('Mercure publish failed for distribution plan', ['error' => $e->getMessage()]);
        }
    }
}
