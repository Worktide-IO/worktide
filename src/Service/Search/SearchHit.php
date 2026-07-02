<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * One provider-agnostic search result, ready to serialize for /v1/search. The
 * frontend navigates via {@see self::$iri}.
 */
final class SearchHit
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $iri,
        public readonly string $title,
        public readonly string $snippet,
        public readonly ?int $updatedAt,
        public readonly ?string $parentType = null,
        public readonly ?string $parentId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'iri' => $this->iri,
            'title' => $this->title,
            'snippet' => $this->snippet,
            'updatedAt' => $this->updatedAt,
            'parentType' => $this->parentType,
            'parentId' => $this->parentId,
        ];
    }
}
