<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\AIRecommendation;
use App\Entity\Enum\RecommendationKind;
use App\Entity\Enum\RecommendationStatus;
use App\Entity\Enum\RecommendationTarget;
use App\Entity\Workspace;
use App\Repository\AIRecommendationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Proactive product-strategy copilot: analyses channel conversations, customer
 * industries and the existing product catalogue to identify unmet needs and
 * propose new product opportunities. Generates Pending
 * {@see AIRecommendation} rows of kind ProductSuggestion that surface in the
 * /ki-agenten inbox for staff review.
 *
 * Deduped — skips workspaces with pending product suggestions — so safe to run
 * periodically via cron.
 */
final class ProductOpportunityGenerator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductOpportunityAssistant $assistant,
        private readonly AIRecommendationRepository $recommendations,
        private readonly EgressGuard $egress,
        private readonly LoggerInterface $logger,
    ) {}

    public function isAvailable(): bool
    {
        return $this->assistant->isAvailable() && $this->egress->isAllowed(EgressModule::Llm);
    }

    public function generateForWorkspace(Workspace $workspace): int
    {
        $wsId = $workspace->getId();
        if (!$this->isAvailable() || $wsId === null) {
            return 0;
        }

        // Dedup: skip if workspace already has pending product suggestions.
        if ($this->recommendations->findPendingFor(RecommendationTarget::Workspace, $wsId, RecommendationKind::ProductSuggestion) !== []) {
            return 0;
        }

        $opportunities = $this->assistant->suggestOpportunities($workspace);
        if ($opportunities === []) {
            return 0;
        }

        $model = $this->assistant->getModel();

        foreach ($opportunities as $opp) {
            $reco = (new AIRecommendation())
                ->setWorkspace($workspace)
                ->setTarget(RecommendationTarget::Workspace)
                ->setTargetId($wsId)
                ->setKind(RecommendationKind::ProductSuggestion)
                ->setStatus(RecommendationStatus::Pending)
                ->setSuggestion([
                    'title' => $opp['title'],
                    'description' => $opp['description'],
                    'targetIndustry' => $opp['targetIndustry'],
                    'wouldServeExistingCustomers' => $opp['wouldServeExistingCustomers'],
                ])
                ->setReasoning($opp['rationale'])
                ->setModel($model);
            $this->em->persist($reco);
        }

        $this->em->flush();

        $count = \count($opportunities);
        $this->logger->info('Generated product opportunity suggestions.', [
            'workspace' => $wsId->toRfc4122(),
            'count' => $count,
        ]);

        return $count;
    }
}
