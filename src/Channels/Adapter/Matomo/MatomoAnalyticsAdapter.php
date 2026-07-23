<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Matomo;

use App\Channels\InboundAdapter;
use App\Channels\InboundResult;
use App\Channels\WebhookNotSupportedException;
use App\Entity\Channel;
use App\Entity\InboundEvent;
use App\Repository\InboundEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pull-based Matomo Analytics adapter. Every pull cycle fetches the last
 * 24 hours of key metrics (visits, page views, top pages, referrers) from
 * a configured Matomo instance and creates a summary InboundEvent.
 *
 * Channel.authConfig:
 *   - matomoUrl:  base URL of the Matomo instance (e.g. https://matomo.example.com)
 *   - authToken:  Matomo API token (token_auth)
 *
 * Channel.inboundConfig:
 *   - siteId:     Matomo site ID (integer)
 *   - period:     "day", "week" or "month" (default "day")
 *   - limit:      max top pages/referrers to include (default 10)
 *
 * Dedup by date — one event per (channel, date).
 */
final class MatomoAnalyticsAdapter implements InboundAdapter
{
    public const CODE = 'matomo_analytics';

    private const API_METHOD = '/index.php';

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
        return 'Matomo Analytics';
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
        $auth = $channel->getAuthConfig() ?? [];
        $matomoUrl = $auth['matomoUrl'] ?? '';
        $authToken = $auth['authToken'] ?? '';

        if ($matomoUrl === '' || $authToken === '') {
            return InboundResult::noop();
        }

        $config = $channel->getInboundConfig() ?? [];
        $siteId = (int) ($config['siteId'] ?? 1);
        $period = $config['period'] ?? 'day';
        $limit = (int) ($config['limit'] ?? 10);

        $today = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');

        // Dedup: skip if we already have an event for today.
        $eventId = $this->externalId($channel, $yesterday);
        if ($this->events->findByExternalId($channel, $eventId) !== null) {
            return InboundResult::noop();
        }

        $baseUrl = rtrim($matomoUrl, '/') . self::API_METHOD;

        // Fetch key metrics.
        $visits = $this->fetch($baseUrl, $authToken, $siteId, $period, $yesterday, 'VisitsSummary.get');
        $pages = $this->fetch($baseUrl, $authToken, $siteId, $period, $yesterday, 'Actions.getPageUrls');
        $referrers = $this->fetch($baseUrl, $authToken, $siteId, $period, $yesterday, 'Referrers.getWebsites');

        if ($visits === null && $pages === null && $referrers === null) {
            return InboundResult::noop();
        }

        $subject = sprintf('Matomo Report: %s', $yesterday);

        $sections = [];

        // Visits summary
        if ($visits !== null) {
            $nbVisits = $visits['nb_visits'] ?? 0;
            $nbActions = $visits['nb_actions'] ?? 0;
            $bounceRate = isset($visits['bounce_rate']) ? ((int) $visits['bounce_rate']) . '%' : 'N/A';
            $avgTime = isset($visits['avg_time_on_site']) ? $this->formatDuration((int) $visits['avg_time_on_site']) : 'N/A';

            $sections[] = sprintf(
                "## Visits: %s\n- Page views: %s\n- Bounce rate: %s\n- Avg time on site: %s",
                $nbVisits,
                $nbActions,
                $bounceRate,
                $avgTime,
            );
        }

        // Top pages
        if ($pages !== null && \is_array($pages)) {
            $top = \array_slice($pages, 0, $limit);
            if ($top !== []) {
                $lines = [];
                foreach ($top as $p) {
                    $label = $this->trim($p['label'] ?? '(unknown)', 120);
                    $hits = $p['nb_hits'] ?? 0;
                    $lines[] = sprintf('- %s (%s views)', $label, $hits);
                }
                $sections[] = "## Top Pages\n" . implode("\n", $lines);
            }
        }

        // Top referrers
        if ($referrers !== null && \is_array($referrers)) {
            $top = \array_slice($referrers, 0, $limit);
            if ($top !== []) {
                $lines = [];
                foreach ($top as $r) {
                    $label = $this->trim($r['label'] ?? '(unknown)', 120);
                    $visitsRef = $r['nb_visits'] ?? 0;
                    $lines[] = sprintf('- %s (%s visits)', $label, $visitsRef);
                }
                $sections[] = "## Top Referrers\n" . implode("\n", $lines);
            }
        }

        $body = implode("\n\n", $sections);

        $event = (new InboundEvent())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setExternalId($eventId)
            ->setSenderRaw('Matomo Analytics')
            ->setSubject($subject)
            ->setBody($body)
            ->setReceivedAt(new \DateTimeImmutable('now'))
            ->setSourceMetadata([
                'siteId' => $siteId,
                'period' => $period,
                'date' => $yesterday,
            ]);

        $this->em->persist($event);

        return InboundResult::events([$event]);
    }

    private function fetch(string $baseUrl, string $authToken, int $siteId, string $period, string $date, string $method): ?array
    {
        $params = [
            'module' => 'API',
            'method' => $method,
            'idSite' => $siteId,
            'period' => $period,
            'date' => $date,
            'format' => 'JSON',
            'token_auth' => $authToken,
        ];

        $url = $baseUrl . '?' . http_build_query($params);

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
            $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

            if (isset($data['result']) && $data['result'] === 'error') {
                return null;
            }

            return \is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function externalId(Channel $channel, string $date): string
    {
        return hash('sha256', 'matomo-' . ($channel->getId()?->toRfc4122() ?? '?') . '-' . $date);
    }

    private function formatDuration(int $seconds): string
    {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        return $m > 0 ? sprintf('%dm %ds', $m, $s) : sprintf('%ds', $s);
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
