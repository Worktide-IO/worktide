<?php

declare(strict_types=1);

namespace App\Service\Search;

use Meilisearch\Client;
use Symfony\Component\Uid\Uuid;

/**
 * Meilisearch backend — one shared index `<prefix>_documents`, tenant-isolated
 * via a `workspaceId` filter. Ranking, typo-tolerance and highlighting come for
 * free. Selected with SEARCH_PROVIDER=meilisearch; the indexing pipeline keeps
 * it in step (see SearchIndexingListener / SyncSearchIndexHandler).
 */
final class MeilisearchProvider implements SearchProviderInterface
{
    private readonly ?Client $client;
    private readonly string $indexName;
    private bool $ensured = false;

    public function __construct(
        MeilisearchClientFactory $clientFactory,
        string $indexPrefix,
    ) {
        $this->client = $clientFactory->create();
        $prefix = trim($indexPrefix) !== '' ? trim($indexPrefix) : 'worktide';
        $this->indexName = $prefix . '_documents';
    }

    public function search(string $query, Uuid $workspaceId, array $types = [], int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '' || $this->client === null) {
            return [];
        }

        $filters = ['workspaceId = "' . $workspaceId->toRfc4122() . '"'];
        if ($types !== []) {
            $quoted = array_map(static fn (string $t): string => '"' . addslashes($t) . '"', $types);
            $filters[] = 'type IN [' . implode(', ', $quoted) . ']';
        }

        try {
            $result = $this->client->index($this->indexName)->search($query, [
                'filter' => implode(' AND ', $filters),
                'limit' => $limit,
                'attributesToCrop' => ['body'],
                'cropLength' => 40,
                'attributesToHighlight' => ['title', 'body'],
            ]);
        } catch (\Throwable) {
            return [];
        }

        $hits = [];
        foreach ($result->getHits() as $hit) {
            $formatted = \is_array($hit['_formatted'] ?? null) ? $hit['_formatted'] : [];
            $hits[] = new SearchHit(
                type: (string) ($hit['type'] ?? ''),
                id: (string) ($hit['uuid'] ?? ''),
                iri: (string) ($hit['iri'] ?? ''),
                title: (string) ($formatted['title'] ?? $hit['title'] ?? ''),
                snippet: (string) ($formatted['body'] ?? mb_substr((string) ($hit['body'] ?? ''), 0, 200)),
                updatedAt: isset($hit['updatedAt']) ? (int) $hit['updatedAt'] : null,
                parentType: isset($hit['parentType']) ? (string) $hit['parentType'] : null,
                parentId: isset($hit['parentId']) ? (string) $hit['parentId'] : null,
            );
        }

        return $hits;
    }

    public function index(SearchDocument $document): void
    {
        if ($this->client === null) {
            return;
        }
        $this->ensureIndex();
        $this->client->index($this->indexName)->addDocuments([$document->toArray()], 'id');
    }

    public function delete(string $type, Uuid $id): void
    {
        if ($this->client === null) {
            return;
        }
        $this->client->index($this->indexName)->deleteDocument($type . '-' . $id->toRfc4122());
    }

    public function reindex(iterable $documents): void
    {
        if ($this->client === null) {
            return;
        }
        $this->ensureIndex();
        $index = $this->client->index($this->indexName);

        $batch = [];
        foreach ($documents as $document) {
            $batch[] = $document->toArray();
            if (\count($batch) >= 1000) {
                $index->addDocuments($batch, 'id');
                $batch = [];
            }
        }
        if ($batch !== []) {
            $index->addDocuments($batch, 'id');
        }
    }

    public function requiresIndexing(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        if ($this->client === null) {
            return false;
        }
        try {
            return $this->client->isHealthy();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Idempotently create the index + set its attributes. Cheap after the first
     * call (guarded per process); Meilisearch itself de-dupes settings tasks.
     */
    private function ensureIndex(): void
    {
        if ($this->ensured || $this->client === null) {
            return;
        }
        try {
            $this->client->createIndex($this->indexName, ['primaryKey' => 'id']);
        } catch (\Throwable) {
            // Already exists — fine.
        }
        $index = $this->client->index($this->indexName);
        $index->updateSearchableAttributes(['title', 'body']);
        $index->updateFilterableAttributes(['workspaceId', 'type']);
        $index->updateSortableAttributes(['updatedAt']);
        $this->ensured = true;
    }
}
