<?php

declare(strict_types=1);

namespace App\Service\ExternalSearch;

use App\Entity\Enum\LeadSource;

/**
 * One raw hit from an external-search provider — a candidate the
 * {@see \App\Service\Ai\ResearchAssistant} later turns into a scored Lead.
 * Deliberately loose: providers fill what they have, the assistant + handler
 * extract/validate the rest.
 */
final readonly class ExternalSearchResult
{
    /**
     * @param array<string, mixed> $data extra provider fields (for the LLM context + provenance)
     */
    public function __construct(
        public string $title,
        public ?string $url,
        public ?string $snippet,
        public LeadSource $source,
        public string $provider,
        public array $data = [],
    ) {}
}
