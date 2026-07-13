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
 * Surfe People Search — B2B people/contact discovery keyed on company + people
 * filters (industry, seniority, department, region, …). Fills the gap the web
 * (Tavily) and tech-directory (BuiltWith) adapters leave: actual decision-maker
 * records with verified contact data. Only runs when the query carries a real
 * targeting signal (industry/seniority/department/jobTitle/size/revenue) — a
 * bare region hint is not enough, so generic market-research runs don't burn
 * credits (mirrors BuiltWith returning [] without a `tech` hint). Inert without
 * SURFE_API_KEY; every call is gated by the external_search egress module.
 * Results are LinkedIn-sourced people → {@see LeadSource::LinkedIn}.
 */
final class SurfeSearchProvider implements ExternalSearchProviderInterface
{
    private const ENDPOINT = 'https://api.surfe.com/v2/people/search';

    /** DACH scope applied when a targeting query carries no explicit country hint. */
    private const DEFAULT_COUNTRIES = ['DE', 'AT', 'CH'];

    /** Allowed people.seniorities enum values (per the API's validation). */
    private const SENIORITIES = [
        'Board Member', 'C-Level', 'Director', 'Founder', 'Head',
        'Manager', 'Other', 'Owner', 'Partner', 'VP',
    ];

    /** Allowed people.departments enum values (per the API's validation). */
    private const DEPARTMENTS = [
        'Accounting and Finance', 'Board', 'Business Support', 'Customer Relations',
        'Design', 'Editorial Personnel', 'Engineering', 'Founder/Owner', 'Healthcare',
        'HR', 'Legal', 'Management', 'Manufacturing', 'Marketing and Advertising',
        'Operations', 'PR and Communications', 'Procurement', 'Product',
        'Quality Control', 'R&D', 'Sales', 'Security', 'Supply Chain', 'Other',
    ];

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
        return 'surfe';
    }

    public function search(ExternalSearchQuery $query): array
    {
        $companies = $this->companyFilters($query->filters);
        $people = $this->peopleFilters($query);

        // No targeting signal (industry/seniority/department/jobTitle/size/revenue) → nothing
        // to search on; skip the credit-costing call entirely.
        if ($companies === [] && $people === []) {
            return [];
        }

        // Scope to DACH by default once we do have a signal, unless a country hint is set.
        if (!isset($companies['countries'])) {
            $companies['countries'] = self::DEFAULT_COUNTRIES;
        }

        if (!$this->egress->isAllowed(EgressModule::ExternalSearch)) {
            throw new ExternalSearchException('External search egress not approved (module "external_search").');
        }
        if (!$this->isConfigured()) {
            throw new ExternalSearchException('Surfe is not configured (missing SURFE_API_KEY).');
        }

        try {
            $resp = $this->httpClient->request('POST', self::ENDPOINT, [
                'auth_bearer' => $this->apiKey,
                'json' => [
                    'companies' => (object) $companies,
                    'people' => (object) $people,
                    'limit' => min(max($query->limit, 1), 100),
                ],
                'timeout' => 30,
            ]);
            if ($resp->getStatusCode() >= 400) {
                throw new ExternalSearchException('Surfe HTTP ' . $resp->getStatusCode() . ' — ' . substr($resp->getContent(false), 0, 200));
            }
            $data = $resp->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new ExternalSearchException('Surfe transport error: ' . $e->getMessage(), 0, $e);
        }

        $out = [];
        foreach (($data['people'] ?? []) as $r) {
            if (!\is_array($r)) {
                continue;
            }
            $name = trim(((string) ($r['firstName'] ?? '')) . ' ' . ((string) ($r['lastName'] ?? '')));
            $company = \is_string($r['companyName'] ?? null) ? $r['companyName'] : null;
            $jobTitle = \is_string($r['jobTitle'] ?? null) ? $r['jobTitle'] : null;
            $url = \is_string($r['linkedInUrl'] ?? null) ? $r['linkedInUrl'] : null;

            $out[] = new ExternalSearchResult(
                title: $name !== '' ? $name : ($company ?? 'person'),
                url: $url,
                snippet: trim(implode(' @ ', array_filter([$jobTitle, $company]))) ?: null,
                source: LeadSource::LinkedIn,
                provider: $this->getName(),
                data: $r,
            );
        }

        return $out;
    }

    /**
     * @param array<string, scalar> $filters
     *
     * @return array<string, list<string>>
     */
    private function companyFilters(array $filters): array
    {
        $out = [];
        foreach (['countries', 'industries', 'employeeCounts', 'revenues', 'domains'] as $key) {
            // `region`/`industry` are the singular hints the research agent already emits.
            $raw = $filters[$key]
                ?? ($key === 'countries' ? ($filters['region'] ?? null) : null)
                ?? ($key === 'industries' ? ($filters['industry'] ?? null) : null);
            $values = $this->toList($raw);
            if ($values !== []) {
                $out[$key] = $values;
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    private function peopleFilters(ExternalSearchQuery $query): array
    {
        $out = [];

        $seniorities = array_values(array_intersect($this->toList($query->filters['seniorities'] ?? null), self::SENIORITIES));
        if ($seniorities !== []) {
            $out['seniorities'] = $seniorities;
        }

        $departments = array_values(array_intersect($this->toList($query->filters['departments'] ?? null), self::DEPARTMENTS));
        if ($departments !== []) {
            $out['departments'] = $departments;
        }

        // Explicit jobTitles hint, else fall back to the free-text query as a title keyword.
        $jobTitles = $this->toList($query->filters['jobTitles'] ?? null);
        if ($jobTitles === [] && trim($query->query) !== '') {
            $jobTitles = [trim($query->query)];
        }
        if ($jobTitles !== []) {
            $out['jobTitles'] = $jobTitles;
        }

        return $out;
    }

    /**
     * Normalizes a scalar filter value (single value or comma-separated list) to a clean list.
     *
     * @return list<string>
     */
    private function toList(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $raw)), static fn (string $v): bool => $v !== ''));
    }
}
