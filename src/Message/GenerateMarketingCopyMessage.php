<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to draft marketing social copy for one catalog
 * {@see \App\Entity\Product} and persist a Pending
 * {@see \App\Entity\AIRecommendation} ({@see \App\Entity\Enum\RecommendationKind::MarketingSocialDraft}).
 *
 * Routed to the dedicated `ai_agents` transport so the slow LLM call can't
 * starve the fast `async` queue. Carries only the product id; the handler
 * re-loads the row, so a redelivery simply produces a fresh suggestion
 * (superseding any still-pending one).
 */
final class GenerateMarketingCopyMessage
{
    public function __construct(
        private readonly Uuid $productId,
    ) {}

    public function getProductId(): Uuid
    {
        return $this->productId;
    }
}
