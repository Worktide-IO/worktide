<?php

declare(strict_types=1);

namespace App\Service\Search;

use Meilisearch\Client;

/**
 * Builds the Meilisearch client from env (MEILISEARCH_DSN / MEILISEARCH_API_KEY).
 * Returns null when no DSN is configured — the provider then reports itself
 * unavailable instead of throwing.
 */
final class MeilisearchClientFactory
{
    public function __construct(
        private readonly string $dsn,
        private readonly string $apiKey,
    ) {}

    public function create(): ?Client
    {
        $dsn = trim($this->dsn);
        if ($dsn === '') {
            return null;
        }

        return new Client($dsn, $this->apiKey !== '' ? $this->apiKey : null);
    }
}
