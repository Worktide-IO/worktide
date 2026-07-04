<?php

declare(strict_types=1);

namespace App\Service\ExternalSearch;

use Psr\Log\LoggerInterface;

/**
 * Aggregates the configured external-search adapters. The research run handler
 * asks the registry once; it fans the query out to every adapter that has
 * credentials and merges the hits. A single adapter failing (rate limit, API
 * error) is logged and skipped — the run continues with whatever came back.
 */
final class ExternalSearchRegistry
{
    /** @var list<ExternalSearchProviderInterface> */
    private array $providers;

    /**
     * @param iterable<ExternalSearchProviderInterface> $providers
     */
    public function __construct(iterable $providers, private readonly LoggerInterface $logger)
    {
        $this->providers = array_values([...$providers]);
    }

    /** @return list<ExternalSearchProviderInterface> adapters that have credentials */
    public function configured(): array
    {
        return array_values(array_filter($this->providers, static fn (ExternalSearchProviderInterface $p): bool => $p->isConfigured()));
    }

    /** True if at least one adapter is usable right now. */
    public function isAvailable(): bool
    {
        return $this->configured() !== [];
    }

    /**
     * Run the query across all configured adapters, merging results.
     *
     * @return ExternalSearchResult[]
     */
    public function searchAll(ExternalSearchQuery $query): array
    {
        $results = [];
        foreach ($this->configured() as $provider) {
            try {
                foreach ($provider->search($query) as $hit) {
                    $results[] = $hit;
                }
            } catch (ExternalSearchException $e) {
                $this->logger->warning('External search provider failed; skipping.', [
                    'provider' => $provider->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }
}
