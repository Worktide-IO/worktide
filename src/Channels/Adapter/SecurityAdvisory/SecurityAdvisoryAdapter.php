<?php

declare(strict_types=1);

namespace App\Channels\Adapter\SecurityAdvisory;

use App\Channels\InboundAdapter;
use App\Channels\InboundResult;
use App\Channels\WebhookNotSupportedException;
use App\Entity\Channel;
use App\Entity\InboundEvent;
use App\Repository\InboundEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pull-based security advisory monitor: fetches from NVD (CVE database) and
 * GitHub Advisory Database, filters by configured product/technology keywords,
 * and ingests matching advisories as inbound events for staff review.
 *
 * Supports two sources, configured via channel.inboundConfig:
 *   - "nvd":            https://services.nvd.nist.gov/rest/json/cves/2.0
 *   - "github":         https://api.github.com/advisories
 *
 * Channel.address contains a comma-separated list of product/technology
 * keywords (e.g. "typo3,php,apache,symfony"). Only advisories matching
 * these keywords are ingested. An optional NVD API key can be set in
 * channel.authConfig.nvdApiKey for higher rate limits.
 *
 * Dedup by externalId (CVE ID or GHSA ID). No threading — each advisory
 * is a standalone event.
 */
final class SecurityAdvisoryAdapter implements InboundAdapter
{
    public const CODE = 'security_advisory';

    private const NVD_URL = 'https://services.nvd.nist.gov/rest/json/cves/2.0';
    private const GITHUB_URL = 'https://api.github.com/advisories';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly InboundEventRepository $events,
    ) {}

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Security Advisories (CVE / GitHub)';
    }

    public function supportsInbound(): bool
    {
        return true;
    }

    public function supportsOutbound(): bool
    {
        return false;
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    public function processWebhook(Channel $channel, string $payload, array $headers = []): void
    {
        throw WebhookNotSupportedException::forAdapter($this);
    }

    public function pull(Channel $channel): InboundResult
    {
        $keywords = $this->parseKeywords($channel->getAddress() ?? '');
        if ($keywords === []) {
            return InboundResult::noop();
        }

        $config = $channel->getInboundConfig() ?? [];
        $sources = (array) ($config['sources'] ?? ['nvd', 'github']);
        $apiKey = $config['nvdApiKey'] ?? null;

        $lastPull = $config['lastPull'] ?? null;

        $events = [];
        $newestDate = $lastPull;

        if (\in_array('nvd', $sources, true)) {
            $nvdEvents = $this->pullNvd($channel, $keywords, $lastPull, $apiKey);
            $events = array_merge($events, $nvdEvents);
        }

        if (\in_array('github', $sources, true)) {
            $ghEvents = $this->pullGitHub($channel, $keywords, $lastPull);
            $events = array_merge($events, $ghEvents);
        }

        $updatedConfig = $channel->getInboundConfig() ?? [];
        $updatedConfig['lastPull'] = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
        $channel->setInboundConfig($updatedConfig);

        if ($events === []) {
            return InboundResult::noop();
        }

        return InboundResult::events($events);
    }

    /** @return list<InboundEvent> */
    private function pullNvd(Channel $channel, array $keywords, ?string $lastPull, mixed $apiKey): array
    {
        $query = [
            'keywordSearch' => implode(' ', $keywords),
            'pubStartDate' => $lastPull ? substr($lastPull, 0, 10) . 'T00:00:00.000' : (new \DateTimeImmutable('-7 days'))->format('Y-m-d\T00:00:00.000'),
            'pubEndDate' => (new \DateTimeImmutable('now'))->format('Y-m-d\T00:00:00.000'),
            'resultsPerPage' => 50,
        ];

        $url = self::NVD_URL . '?' . http_build_query($query);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $apiKey ? ['apiKey' => $apiKey] : [],
                'timeout' => 30,
            ]);
            $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return [];
        }

        $vulnerabilities = $data['vulnerabilities'] ?? [];

        $events = [];
        foreach ($vulnerabilities as $item) {
            $cve = $item['cve'] ?? [];
            $cveId = $cve['id'] ?? null;
            if (!\is_string($cveId)) {
                continue;
            }

            $externalId = $cveId;
            if ($this->events->findByExternalId($channel, $externalId) !== null) {
                continue;
            }

            $descriptions = $cve['descriptions'] ?? [];
            $desc = '';
            foreach ($descriptions as $d) {
                if (($d['lang'] ?? '') === 'en') {
                    $desc = $d['value'] ?? '';
                    break;
                }
            }

            $metrics = $cve['metrics'] ?? [];
            $severity = $this->extractSeverity($metrics);

            $publishedDate = $cve['published'] ?? (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);

            $subject = sprintf('[%s] %s', $severity['label'] ?? 'N/A', $cveId);

            $sections = [];
            if ($desc !== '') {
                $sections[] = $desc;
            }
            if ($severity['score'] !== null) {
                $sections[] = sprintf('CVSS Score: %.1f (%s)', $severity['score'], $severity['severity'] ?? 'N/A');
            }
            $sections[] = sprintf('Published: %s', substr($publishedDate, 0, 10));

            $body = implode("\n\n", $sections);

            $events[] = $this->makeEvent(
                channel: $channel,
                externalId: $externalId,
                subject: $subject,
                body: $body,
                receivedAt: new \DateTimeImmutable($publishedDate),
                metadata: $cve,
            );
        }

        return $events;
    }

    /** @return list<InboundEvent> */
    private function pullGitHub(Channel $channel, array $keywords, ?string $lastPull): array
    {
        $perPage = 30;
        $query = implode(' ', $keywords);

        $url = self::GITHUB_URL . '?' . http_build_query([
            'q' => $query,
            'type' => 'reviewed',
            'per_page' => $perPage,
            'sort' => 'updated',
            'direction' => 'desc',
        ]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'application/vnd.github+json'],
                'timeout' => 30,
            ]);
            $advisories = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return [];
        }

        if (!\is_array($advisories)) {
            return [];
        }

        $events = [];
        foreach ($advisories as $adv) {
            $ghsaId = $adv['ghsa_id'] ?? null;
            if (!\is_string($ghsaId)) {
                continue;
            }

            $externalId = $ghsaId;
            if ($this->events->findByExternalId($channel, $externalId) !== null) {
                continue;
            }

            $severity = $adv['severity'] ?? 'N/A';
            $summary = $adv['summary'] ?? $ghsaId;
            $description = $adv['description'] ?? '';
            $publishedAt = $adv['published_at'] ?? (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);

            $subject = sprintf('[%s] %s: %s', strtoupper($severity), $ghsaId, mb_substr($summary, 0, 120));

            $sections = [];
            if ($description !== '') {
                $sections[] = $description;
            }
            $cveId = $adv['cve_id'] ?? null;
            if ($cveId !== null) {
                $sections[] = sprintf('CVE: %s', $cveId);
            }
            $sections[] = sprintf('Published: %s', substr($publishedAt, 0, 10));
            $cwes = $adv['cwes'] ?? [];
            if ($cwes !== []) {
                $names = array_map(static fn (array $c) => $c['cwe_id'] . ': ' . ($c['name'] ?? ''), $cwes);
                $sections[] = 'CWEs: ' . implode(', ', $names);
            }

            $body = implode("\n\n", $sections);

            $events[] = $this->makeEvent(
                channel: $channel,
                externalId: $externalId,
                subject: $subject,
                body: $body,
                receivedAt: new \DateTimeImmutable($publishedAt),
                metadata: $adv,
            );
        }

        return $events;
    }

    /**
     * @param array<int, string> $addresses
     * @return list<string>
     */
    private function parseKeywords(string $addresses): array
    {
        $parts = explode(',', $addresses);
        $keywords = [];
        foreach ($parts as $p) {
            $k = trim($p);
            if ($k !== '') {
                $keywords[] = $k;
            }
        }
        return $keywords;
    }

    /**
     * @param array<int, array<string, mixed>> $metrics
     * @return array{label: string, score: ?float, severity: ?string}
     */
    private function extractSeverity(array $metrics): array
    {
        $label = 'N/A';
        $score = null;
        $severity = null;

        foreach ($metrics as $metricEntry) {
            $cvssV31 = $metricEntry['cvssMetricV31'] ?? $metricEntry['cvssMetricV30'] ?? $metricEntry['cvssMetricV2'] ?? null;
            if ($cvssV31 !== null && \is_array($cvssV31)) {
                $first = $cvssV31[0] ?? $cvssV31;
                $cvssData = $first['cvssData'] ?? $first;
                if (isset($cvssData['baseSeverity'], $cvssData['baseScore'])) {
                    $score = (float) $cvssData['baseScore'];
                    $severity = $cvssData['baseSeverity'];
                    $label = sprintf('%.1f %s', $score, $severity);
                    break;
                }
            }
        }

        return ['label' => $label, 'score' => $score, 'severity' => $severity];
    }

    private function makeEvent(Channel $channel, string $externalId, string $subject, string $body, \DateTimeImmutable $receivedAt, array $metadata): InboundEvent
    {
        return (new InboundEvent())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setExternalId($externalId)
            ->setSubject($this->trim($subject, 250))
            ->setBody($body)
            ->setReceivedAt($receivedAt)
            ->setSourceMetadata($metadata);
    }

    private function trim(?string $s, int $max): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . "\u{2026}" : $s;
    }
}
