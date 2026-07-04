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
 * BuiltWith Lists API — finds domains running a given technology (e.g. TYPO3),
 * i.e. tech-stack targeting for key-account discovery. Only runs when the query
 * carries a `tech` filter (the API is technology-keyed); otherwise returns [].
 * Inert without BUILTWITH_API_KEY; gated by external_search. Results are
 * directory hits → {@see LeadSource::Directory}.
 */
final class BuiltWithSearchProvider implements ExternalSearchProviderInterface
{
    private const ENDPOINT = 'https://api.builtwith.com/lists12/api.json';

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
        return 'builtwith';
    }

    public function search(ExternalSearchQuery $query): array
    {
        $tech = isset($query->filters['tech']) ? trim((string) $query->filters['tech']) : '';
        if ($tech === '') {
            return []; // BuiltWith is technology-keyed — nothing to do without a tech hint
        }
        if (!$this->egress->isAllowed(EgressModule::ExternalSearch)) {
            throw new ExternalSearchException('External search egress not approved (module "external_search").');
        }
        if (!$this->isConfigured()) {
            throw new ExternalSearchException('BuiltWith is not configured (missing BUILTWITH_API_KEY).');
        }

        try {
            $resp = $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => ['KEY' => $this->apiKey, 'TECH' => $tech],
                'timeout' => 30,
            ]);
            if ($resp->getStatusCode() >= 400) {
                throw new ExternalSearchException('BuiltWith HTTP ' . $resp->getStatusCode() . ' — ' . substr($resp->getContent(false), 0, 200));
            }
            $data = $resp->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new ExternalSearchException('BuiltWith transport error: ' . $e->getMessage(), 0, $e);
        }

        $out = [];
        foreach (($data['Results'] ?? []) as $r) {
            $domain = \is_array($r) ? (string) ($r['Domain'] ?? $r['D'] ?? '') : '';
            if ($domain === '') {
                continue;
            }
            $out[] = new ExternalSearchResult(
                title: $domain,
                url: 'https://' . $domain,
                snippet: sprintf('Uses %s', $tech),
                source: LeadSource::Directory,
                provider: $this->getName(),
                data: \is_array($r) ? $r : [],
            );
            if (\count($out) >= max($query->limit, 1)) {
                break;
            }
        }

        return $out;
    }
}
