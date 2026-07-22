<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\AIRecommendation;
use App\Entity\Enum\RecommendationKind;
use App\Entity\Enum\RecommendationStatus;
use App\Entity\Enum\RecommendationTarget;
use App\Entity\Product;
use App\Entity\ProductFeature;
use App\Entity\Workspace;
use App\Entity\Enum\ProductStatus;
use App\Repository\AIRecommendationRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Proactive marketing copilot: scans the product catalogue for marketing-worthy
 * events (new releases, untapped features, stale copy) and generates pending
 * {@see AIRecommendation} social-copy drafts that surface in the /ki-agenten
 * inbox. Staff review and accept — {@see RecommendationApplier} materialises
 * a Draft {@see \App\Entity\SocialPost} that still goes through the normal
 * approval gate.
 *
 * LLM-gated (default-deny egress); deduped per-product so pending suggestions
 * don't pile up. Designed to run from cron (worktide:marketing:suggest).
 */
final class ProactiveMarketingGenerator
{
    private const RECENT_DAYS = 14;
    private const MAX_PRODUCTS_PER_RUN = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketingCopyAssistant $assistant,
        private readonly AIRecommendationRepository $recommendations,
        private readonly ProductRepository $products,
        private readonly EgressGuard $egress,
        private readonly LoggerInterface $logger,
    ) {}

    public function isAvailable(): bool
    {
        return $this->assistant->isAvailable() && $this->egress->isAllowed(EgressModule::Llm);
    }

    /**
     * @return int number of suggestions created
     */
    public function generateForWorkspace(Workspace $workspace): int
    {
        if (!$this->isAvailable() || $workspace->getId() === null) {
            return 0;
        }

        $candidates = $this->findMarketingCandidates($workspace);
        if ($candidates === []) {
            return 0;
        }

        $model = $this->assistant->getModel();
        $created = 0;

        foreach ($candidates as $product) {
            $pid = $product->getId();
            if ($pid === null) {
                continue;
            }

            // Dedup: skip if this product already has a pending marketing draft.
            if ($this->recommendations->findPendingFor(RecommendationTarget::Product, $pid, RecommendationKind::MarketingSocialDraft) !== []) {
                continue;
            }

            $result = $this->assistant->draftSocialPosts($product);

            $reco = (new AIRecommendation())
                ->setWorkspace($workspace)
                ->setTarget(RecommendationTarget::Product)
                ->setTargetId($pid)
                ->setKind(RecommendationKind::MarketingSocialDraft)
                ->setStatus(RecommendationStatus::Pending)
                ->setSuggestion($result['suggestion'])
                ->setReasoning($result['reasoning'])
                ->setModel($model);
            $this->em->persist($reco);
            ++$created;

            if ($created >= self::MAX_PRODUCTS_PER_RUN) {
                break;
            }
        }

        $this->em->flush();

        if ($created > 0) {
            $this->logger->info('Generated proactive marketing suggestions.', [
                'workspace' => $workspace->getId()->toRfc4122(),
                'count' => $created,
            ]);
        }

        return $created;
    }

    /**
     * @return list<Product>
     */
    private function findMarketingCandidates(Workspace $workspace): array
    {
        $all = $this->products->findBy(['workspace' => $workspace], ['name' => 'ASC']);

        $candidates = [];
        foreach ($all as $product) {
            $score = $this->marketingScore($product);
            if ($score > 0) {
                $candidates[] = ['product' => $product, 'score' => $score];
            }
        }

        // Sort by score descending, then take top N.
        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(static fn (array $c): Product => $c['product'], $candidates);
    }

    /**
     * Calculates a "marketing-worthiness" score for a product. Higher = more urgent.
     */
    private function marketingScore(Product $product): int
    {
        // Don't market deprecated or end-of-life products.
        $status = $product->getStatus();
        if ($status === ProductStatus::Deprecated || $status === ProductStatus::Eol) {
            return 0;
        }

        $score = 0;

        // Has a description? Base line.
        if ($product->getDescription() !== null && trim($product->getDescription()) !== '') {
            $score += 1;
        }

        // Has a recent version release?
        $latest = $product->getLatestVersion();
        if ($latest !== null) {
            $releaseDate = $latest->getReleaseDate();
            if ($releaseDate !== null) {
                $days = (int) $releaseDate->diff(new \DateTimeImmutable('now'))->days;
                if ($days <= self::RECENT_DAYS) {
                    $score += 5; // Very recent release → high priority
                } elseif ($days <= 60) {
                    $score += 2; // Recent-ish
                }
            }

            // Has features defined on the latest version?
            $features = $latest->getFeatures();
            if ($features->count() > 0) {
                $score += 3; // Rich feature data → better marketing copy
            }

            // Has release notes?
            if ($latest->getReleaseNotes() !== null && trim($latest->getReleaseNotes()) !== '') {
                $score += 1;
            }
        }

        return $score;
    }
}
