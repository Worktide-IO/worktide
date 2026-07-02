<?php

declare(strict_types=1);

namespace App\Service\Search;

use Symfony\Component\Uid\Uuid;

/**
 * Pluggable backend for the global full-text search. The MySQL provider is the
 * default and needs no external service; Meilisearch is a drop-in for scale
 * (ranking, typo-tolerance, highlighting). Selected via SEARCH_PROVIDER.
 */
interface SearchProviderInterface
{
    /**
     * @param string[] $types restrict to these type slugs (empty = all)
     *
     * @return SearchHit[]
     */
    public function search(string $query, Uuid $workspaceId, array $types = [], int $limit = 20): array;

    public function index(SearchDocument $document): void;

    public function delete(string $type, Uuid $id): void;

    /**
     * @param iterable<SearchDocument> $documents
     */
    public function reindex(iterable $documents): void;

    /**
     * Whether entity changes must be pushed to an index (true = Meilisearch).
     * When false (MySQL) the indexing pipeline stays idle — search reads the DB.
     */
    public function requiresIndexing(): bool;

    /** Whether the backend is reachable/usable right now. */
    public function isAvailable(): bool;
}
