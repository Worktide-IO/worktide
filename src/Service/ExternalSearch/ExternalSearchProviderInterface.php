<?php

declare(strict_types=1);

namespace App\Service\ExternalSearch;

/**
 * A swappable external search/discovery backend for the research agent (web
 * search, company/tech directories, …). Mirrors the {@see \App\Service\Llm\LlmProviderInterface}
 * shape: inert (isConfigured() = false) without credentials, and every call is
 * gated by the `external_search` egress module before any outbound request.
 */
interface ExternalSearchProviderInterface
{
    /** Whether a credential is present — lets the registry skip this adapter cleanly. */
    public function isConfigured(): bool;

    /** Stable identifier for provenance/logging (e.g. "tavily", "builtwith"). */
    public function getName(): string;

    /**
     * @return ExternalSearchResult[]
     *
     * @throws \App\Service\ExternalSearch\ExternalSearchException on egress denial or transport/API failure
     */
    public function search(ExternalSearchQuery $query): array;
}
