<?php

declare(strict_types=1);

namespace App\Service\ExternalSearch\Provider;

use App\Entity\Enum\LeadSource;
use App\Service\ExternalSearch\ExternalSearchProviderInterface;
use App\Service\ExternalSearch\ExternalSearchQuery;
use App\Service\ExternalSearch\ExternalSearchResult;
use App\Service\Search\SearchHit;
use App\Service\Search\SearchProviderInterface;

/**
 * Own-database provider: the research agent's "aus der eigenen Datenbank" source.
 * Instead of hitting the open web, it queries the workspace's existing search
 * index (customers + contacts) and returns matches as candidate leads. No egress
 * — it reads local data through the active {@see SearchProviderInterface}
 * (Meilisearch or the MySQL fallback), so it works whenever search is available.
 *
 * Objective-aware: only contributes to partner/market/general missions, where
 * existing relationships are legitimate candidates. For pure new-customer
 * acquisition (lead_generation) or content distribution it stays silent — you
 * don't want your own customers surfaced as "new" leads there.
 */
final class InternalSearchProvider implements ExternalSearchProviderInterface
{
    /** Objectives for which the own database is NOT a useful lead source. */
    private const SKIP_OBJECTIVES = ['lead_generation', 'content_distribution'];

    /** Which indexed entity types count as candidate organizations/people. */
    private const TYPES = ['customer', 'contact'];

    public function __construct(
        private readonly SearchProviderInterface $search,
    ) {}

    public function isConfigured(): bool
    {
        return $this->search->isAvailable();
    }

    public function getName(): string
    {
        return 'internal';
    }

    public function search(ExternalSearchQuery $query): array
    {
        $workspaceId = $query->workspaceId;
        if ($workspaceId === null) {
            return [];
        }
        $objective = (string) ($query->filters['objective'] ?? '');
        if (\in_array($objective, self::SKIP_OBJECTIVES, true)) {
            return [];
        }
        if (trim($query->query) === '') {
            return [];
        }

        $out = [];
        foreach ($this->search->search($query->query, $workspaceId, self::TYPES, $query->limit) as $hit) {
            if (!$hit instanceof SearchHit) {
                continue;
            }
            $out[] = new ExternalSearchResult(
                title: $hit->title,
                // Synthetic URL so the run handler's byUrl mapping carries the
                // internal origin (source/provider) onto the created lead.
                url: 'internal://' . $hit->type . '/' . $hit->id,
                snippet: $hit->snippet !== '' ? $hit->snippet : null,
                source: LeadSource::Referral,
                provider: $this->getName(),
                data: ['internalType' => $hit->type, 'internalId' => $hit->id, 'iri' => $hit->iri],
            );
        }

        return $out;
    }
}
