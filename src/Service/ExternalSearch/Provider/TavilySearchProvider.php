<?php

declare(strict_types=1);

namespace App\Service\ExternalSearch\Provider;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Enum\LeadSource;
use App\Service\ExternalSearch\ExternalSearchException;
use App\Service\ExternalSearch\ExternalSearchProviderInterface;
use App\Service\ExternalSearch\ExternalSearchQuery;
use App\Service\ExternalSearch\ExternalSearchResult;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tavily (AI-agent-optimized web search). Inert without TAVILY_API_KEY; every
 * call is gated by the external_search egress module. Results are open-web hits
 * → {@see LeadSource::WebSearch}.
 */
final class TavilySearchProvider implements ExternalSearchProviderInterface
{
    private const ENDPOINT = 'https://api.tavily.com/search';

    private readonly string $apiKey;

    public function __construct(
        private readonly EgressGuard $egress,
        private readonly HttpClientInterface $httpClient,
        ?string $apiKey = null,
    ) {
        $this->apiKey = (string) $apiKey;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function getName(): string
    {
        return 'tavily';
    }

    public function search(ExternalSearchQuery $query): array
    {
        if (!$this->egress->isAllowed(EgressModule::ExternalSearch)) {
            throw new ExternalSearchException('External search egress not approved (module "external_search").');
        }
        if (!$this->isConfigured()) {
            throw new ExternalSearchException('Tavily is not configured (missing TAVILY_API_KEY).');
        }

        try {
            $resp = $this->httpClient->request('POST', self::ENDPOINT, [
                'json' => [
                    'api_key' => $this->apiKey,
                    'query' => $query->query,
                    'max_results' => min(max($query->limit, 1), 50),
                    'search_depth' => 'advanced',
                ],
                'timeout' => 30,
            ]);
            if ($resp->getStatusCode() >= 400) {
                throw new ExternalSearchException('Tavily HTTP ' . $resp->getStatusCode() . ' — ' . substr($resp->getContent(false), 0, 200));
            }
            $data = $resp->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new ExternalSearchException('Tavily transport error: ' . $e->getMessage(), 0, $e);
        }

        $out = [];
        foreach (($data['results'] ?? []) as $r) {
            $url = \is_string($r['url'] ?? null) ? $r['url'] : null;
            $out[] = new ExternalSearchResult(
                title: (string) ($r['title'] ?? $url ?? 'result'),
                url: $url,
                snippet: \is_string($r['content'] ?? null) ? $r['content'] : null,
                source: LeadSource::WebSearch,
                provider: $this->getName(),
                data: \is_array($r) ? $r : [],
            );
        }

        return $out;
    }
}
