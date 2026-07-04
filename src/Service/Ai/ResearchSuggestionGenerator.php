<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\AIRecommendation;
use App\Entity\Customer;
use App\Entity\Enum\RecommendationKind;
use App\Entity\Enum\RecommendationStatus;
use App\Entity\Enum\RecommendationTarget;
use App\Entity\Product;
use App\Entity\Workspace;
use App\Repository\AIRecommendationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Proactive side of the research agent: inspects a workspace's business snapshot
 * (products, customer base, industries) and asks {@see ResearchAssistant} for a
 * few high-value research missions worth running now. Each proposal is written
 * as a Pending {@see AIRecommendation} (target Workspace, kind ResearchSuggestion)
 * that a human accepts — {@see RecommendationApplier} then materialises a
 * {@see \App\Entity\ResearchMission}. Nothing runs autonomously.
 *
 * LLM-gated (default-deny egress); deduped so pending suggestions don't pile up.
 */
final class ResearchSuggestionGenerator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResearchAssistant $assistant,
        private readonly AIRecommendationRepository $recommendations,
        private readonly EgressGuard $egress,
        private readonly LoggerInterface $logger,
    ) {}

    public function isAvailable(): bool
    {
        return $this->assistant->isAvailable() && $this->egress->isAllowed(EgressModule::Llm);
    }

    /**
     * @return int number of suggestions created (0 when unavailable, already has
     *             pending suggestions, too little data, or nothing was proposed)
     */
    public function generateForWorkspace(Workspace $workspace): int
    {
        $wsId = $workspace->getId();
        if (!$this->isAvailable() || $wsId === null) {
            return 0;
        }
        // Don't pile up: skip if the workspace already has pending suggestions.
        if ($this->recommendations->findPendingFor(RecommendationTarget::Workspace, $wsId, RecommendationKind::ResearchSuggestion) !== []) {
            return 0;
        }

        $context = $this->buildContext($workspace);
        if ($context === null) {
            return 0;
        }

        $suggestions = $this->assistant->suggestMissions($context);
        if ($suggestions === []) {
            return 0;
        }

        $model = $this->assistant->getModel();
        $created = 0;
        foreach ($suggestions as $s) {
            $reco = (new AIRecommendation())
                ->setWorkspace($workspace)
                ->setTarget(RecommendationTarget::Workspace)
                ->setTargetId($wsId)
                ->setKind(RecommendationKind::ResearchSuggestion)
                ->setStatus(RecommendationStatus::Pending)
                ->setSuggestion([
                    'prompt' => $s['prompt'],
                    'objective' => $s['objective'],
                    'targetCount' => $s['targetCount'],
                    'brief' => $s['brief'],
                    'rationale' => $s['rationale'],
                ])
                ->setReasoning($s['rationale'])
                ->setModel($model);
            $this->em->persist($reco);
            ++$created;
        }
        $this->em->flush();

        $this->logger->info('Generated research suggestions.', [
            'workspace' => $wsId->toRfc4122(),
            'count' => $created,
        ]);

        return $created;
    }

    /**
     * A compact, factual snapshot of the workspace for the strategist prompt.
     * Returns null when there is too little to reason about.
     */
    private function buildContext(Workspace $workspace): ?string
    {
        /** @var list<Product> $products */
        $products = $this->em->getRepository(Product::class)->findBy(['workspace' => $workspace], ['name' => 'ASC'], 30);
        /** @var list<Customer> $customers */
        $customers = $this->em->getRepository(Customer::class)->findBy(['workspace' => $workspace], null, 200);
        if ($products === [] && $customers === []) {
            return null;
        }

        $productNames = array_values(array_filter(array_map(
            static fn (Product $p): string => trim($p->getName()),
            $products,
        ), static fn (string $n): bool => $n !== ''));

        /** @var array<string, int> $industries */
        $industries = [];
        foreach ($customers as $c) {
            $name = $c->getIndustry()?->getName();
            if (\is_string($name) && $name !== '') {
                $industries[$name] = ($industries[$name] ?? 0) + 1;
            }
        }
        arsort($industries);

        $lines = ['# Agency snapshot'];
        $lines[] = sprintf('Products (%d): %s', \count($productNames), $productNames === [] ? '—' : implode(', ', $productNames));
        $lines[] = 'Customers: ' . \count($customers);
        if ($industries !== []) {
            $top = [];
            foreach (\array_slice($industries, 0, 12, true) as $name => $n) {
                $top[] = sprintf('%s (%d)', $name, $n);
            }
            $lines[] = 'Top customer industries: ' . implode(', ', $top);
        }

        return mb_substr(implode("\n", $lines), 0, 4000);
    }
}
