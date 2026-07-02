<?php

declare(strict_types=1);

namespace App\Service\Search;

use Symfony\Component\Uid\Uuid;

/**
 * A normalized, provider-agnostic search document — one per searchable entity.
 * The Meilisearch primary key is "<type>-<uuid>" (Meilisearch ids allow only
 * [a-zA-Z0-9-_], which rfc4122 satisfies), keeping types in one shared index.
 */
final class SearchDocument
{
    public function __construct(
        public readonly string $type,
        public readonly Uuid $id,
        public readonly Uuid $workspaceId,
        public readonly string $title,
        public readonly string $body,
        public readonly string $iri,
        public readonly \DateTimeImmutable $updatedAt,
    ) {}

    public function meiliId(): string
    {
        return $this->type . '-' . $this->id->toRfc4122();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->meiliId(),
            'uuid' => $this->id->toRfc4122(),
            'type' => $this->type,
            'workspaceId' => $this->workspaceId->toRfc4122(),
            'title' => $this->title,
            'body' => $this->body,
            'iri' => $this->iri,
            'updatedAt' => $this->updatedAt->getTimestamp(),
        ];
    }
}
