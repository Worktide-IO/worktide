<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * Picks the active search backend at runtime from SEARCH_PROVIDER
 * (mysql = default, meilisearch = drop-in), mirroring StorageAdapterFactory.
 */
final class SearchProviderFactory
{
    public function __construct(
        private readonly string $provider,
        private readonly MysqlSearchProvider $mysql,
        private readonly MeilisearchProvider $meilisearch,
    ) {}

    public function create(): SearchProviderInterface
    {
        return strtolower(trim($this->provider)) === 'meilisearch' ? $this->meilisearch : $this->mysql;
    }
}
