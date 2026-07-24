<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Flarum;

use App\Channels\InboundAdapter;
use App\Channels\InboundResult;
use App\Channels\Testable;
use App\Channels\TestResult;
use App\Channels\WebhookNotSupportedException;
use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Channel;
use App\Entity\Enum\InboundEventState;
use App\Entity\InboundEvent;
use App\Http\OutboundUrlGuard;
use App\Http\UnsafeUrlException;
use App\Repository\InboundEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Read-only pull adapter that monitors a THIRD-PARTY public Flarum forum
 * (e.g. t3forum.net) for new discussions matching configured tags/keywords.
 *
 * Anonymous guest reads only — no posting, no auth. Each new discussion
 * becomes an {@see InboundEvent} surfaced to the Research/Marketing agent.
 *
 * Channel.inboundConfig shape:
 *   {
 *     baseUrl: string,           // Flarum root, e.g. "https://t3forum.net"
 *     tags?: list<string>,       // tag slugs to monitor
 *     keywords?: list<string>,   // free-text keywords to monitor
 *     pollLimit?: int,           // max discussions per request (default 20, max 50)
 *     includeAuthorHandle?: bool,// include author handle in senderRaw (GDPR, default false)
 *     seenHighWaterId?: int,     // adapter state — highest discussion id seen so far
 *   }
 *
 * ## Poll strategy
 *
 * Flarum's `filter[q]` and `filter[tag]` are MUTUALLY EXCLUSIVE — filter[q]
 * makes the API ignore all other filter[] params. So we issue SEPARATE
 * requests per tag and per keyword, merge results, dedup by externalId.
 */
final class FlarumAdapter implements InboundAdapter, Testable
{
    public const CODE = 'flarum';

    private const USER_AGENT = 'Worktide-ForumMonitor/1.0 (+https://worktide.io)';

    private const DEFAULT_POLL_LIMIT = 20;
    private const MAX_POLL_LIMIT = 50;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly InboundEventRepository $events,
        private readonly EgressGuard $egress,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Flarum-Forum (Monitoring)';
    }

    public function pull(Channel $channel): InboundResult
    {
        $base = $this->baseUrl($channel);
        if ($base === '') {
            throw new \RuntimeException('Flarum baseUrl fehlt in der Channel-Konfiguration.');
        }
        OutboundUrlGuard::ensureNotReservedHost($base);

        $cfg = $channel->getInboundConfig();
        $tags = $this->normaliseStringList($cfg['tags'] ?? null);
        $keywords = $this->normaliseStringList($cfg['keywords'] ?? null);

        // Nothing to poll → bail before any outbound HTTP (robots, API).
        if ($tags === [] && $keywords === []) {
            $this->logger?->warning('Flarum-Adapter: Weder Tags noch Keywords konfiguriert — pull übersprungen.', [
                'channel' => $channel->getId()?->toRfc4122(),
            ]);

            return InboundResult::empty();
        }

        if (!$this->egress->isAllowed(EgressModule::ForumMonitor, $channel)) {
            return InboundResult::empty();
        }

        if ($this->isDisallowedByRobotsTxt($base)) {
            return InboundResult::empty();
        }

        $pollLimit = \is_int($cfg['pollLimit'] ?? null) ? $cfg['pollLimit'] : self::DEFAULT_POLL_LIMIT;
        $pollLimit = max(1, min($pollLimit, self::MAX_POLL_LIMIT));

        $seenHighWaterId = \is_int($cfg['seenHighWaterId'] ?? null) ? $cfg['seenHighWaterId'] : 0;

        $includeAuthor = ($cfg['includeAuthorHandle'] ?? false) === true;

        $candidates = [];

        foreach ($tags as $tag) {
            try {
                $page = $this->fetchDiscussions($base, ['filter[tag]' => $tag], $pollLimit);
                foreach ($page['data'] as $d) {
                    $key = 'tag:' . $tag . '|' . $d['id'];
                    $candidates[$key] = ['discussion' => $d, 'matchedBy' => 'tag:' . $tag, 'included' => $page['included']];
                }
            } catch (\Throwable $e) {
                $this->logger?->error('Flarum-Adapter: Fehler beim Abruf von Tag "{tag}": {msg}', [
                    'tag' => $tag,
                    'msg' => $e->getMessage(),
                    'channel' => $channel->getId()?->toRfc4122(),
                ]);
            }
        }

        foreach ($keywords as $kw) {
            try {
                $page = $this->fetchDiscussions($base, ['filter[q]' => $kw], $pollLimit);
                foreach ($page['data'] as $d) {
                    $key = 'keyword:' . $kw . '|' . $d['id'];
                    $candidates[$key] = ['discussion' => $d, 'matchedBy' => 'keyword:' . $kw, 'included' => $page['included']];
                }
            } catch (\Throwable $e) {
                $this->logger?->error('Flarum-Adapter: Fehler beim Abruf von Keyword "{kw}": {msg}', [
                    'kw' => $kw,
                    'msg' => $e->getMessage(),
                    'channel' => $channel->getId()?->toRfc4122(),
                ]);
            }
        }

        $newEvents = [];
        $maxSeen = $seenHighWaterId;

        foreach ($candidates as $candidate) {
            $d = $candidate['discussion'];
            $included = $candidate['included'];
            $id = (int) $d['id'];

            if ($id <= $seenHighWaterId) {
                continue;
            }

            $externalId = 'discussion:' . $id;
            if ($this->events->findByExternalId($channel, $externalId) !== null) {
                $maxSeen = max($maxSeen, $id);
                continue;
            }

            $authorHandle = null;
            if ($includeAuthor) {
                $authorHandle = $this->authorHandle($d, $included);
            }

            $firstPostBody = $this->firstPostBody($d, $included);
            $title = (string) ($d['attributes']['title'] ?? '');
            $slug = (string) ($d['attributes']['slug'] ?? '');
            $commentCount = (int) ($d['attributes']['commentCount'] ?? 0);
            $createdAt = (string) ($d['attributes']['createdAt'] ?? '');
            $tagSlugs = $this->tagSlugs($d, $included);

            $discussionUrl = sprintf('%s/d/%d-%s', $base, $id, $slug);

            $meta = [
                'discussionId' => $id,
                'slug' => $slug,
                'url' => $discussionUrl,
                'tags' => $tagSlugs,
                'commentCount' => $commentCount,
                'createdAt' => $createdAt,
                'matchedBy' => $candidate['matchedBy'],
            ];

            $event = (new InboundEvent())
                ->setWorkspace($channel->getWorkspace())
                ->setChannel($channel)
                ->setExternalId($externalId)
                ->setSubject(mb_substr($title, 0, 250))
                ->setBody($firstPostBody !== null ? mb_substr($firstPostBody, 0, 50000) : mb_substr($title, 0, 250))
                ->setSenderRaw($authorHandle !== null ? mb_substr($authorHandle, 0, 200) : null)
                ->setSourceMetadata($meta)
                ->setTraceUrl($discussionUrl)
                ->setState(InboundEventState::Pending);

            if ($createdAt !== '') {
                $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $createdAt)
                    ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $createdAt);
                if ($dt !== false) {
                    $event->setReceivedAt($dt);
                }
            }

            $this->em->persist($event);
            $newEvents[] = $event;
            $maxSeen = max($maxSeen, $id);
        }

        $cfg['seenHighWaterId'] = $maxSeen;
        $channel->setInboundConfig($cfg);

        $this->logger?->debug('Flarum-Adapter: Pull abgeschlossen — {count} neue Diskussion(en).', [
            'count' => \count($newEvents),
            'channel' => $channel->getId()?->toRfc4122(),
        ]);

        return new InboundResult($newEvents, null);
    }

    public function consumeWebhook(Channel $channel, Request $request): InboundResult
    {
        throw new WebhookNotSupportedException(
            'flarum is pull-only; the worktide:channel:pull cron polls the Flarum API anonymously.'
        );
    }

    public function selfTest(Channel $channel): TestResult
    {
        $base = $this->baseUrl($channel);
        if ($base === '') {
            return TestResult::failed('Base-URL fehlt in der Channel-Konfiguration.');
        }
        try {
            OutboundUrlGuard::ensureNotReservedHost($base);
        } catch (UnsafeUrlException $e) {
            return TestResult::failed($e->getMessage());
        }
        try {
            $this->httpGetJson($base . '/api/discussions?page[limit]=1');

            return TestResult::ok(
                sprintf('Flarum-API unter %s erreichbar.', $base),
                ['baseUrl' => $base],
            );
        } catch (\Throwable $e) {
            return TestResult::failed('Flarum-API nicht erreichbar: ' . $e->getMessage());
        }
    }

    // ---- request helpers -------------------------------------------

    /**
     * Fetch discussions from the Flarum JSON:API endpoint in ONE request,
     * sideloading the first post, author and tags via `include=` so we never
     * hit the server once per discussion (N+1) — the polite-polling choice.
     *
     * Returns the primary `data` list plus an `included` lookup map keyed by
     * "type:id", because JSON:API relationship linkage only carries {type,id};
     * the actual attributes (tag slug, post body, username) live in `included`.
     *
     * @param array<string, string> $filters
     * @return array{data: list<array<string, mixed>>, included: array<string, array<string, mixed>>}
     */
    private function fetchDiscussions(string $base, array $filters, int $limit): array
    {
        $query = http_build_query(array_merge($filters, [
            'include' => 'firstPost,user,tags',
            'sort' => '-createdAt',
            'page[limit]' => (string) $limit,
        ]));

        $doc = $this->httpGetJson($base . '/api/discussions?' . $query);

        $data = \is_array($doc['data'] ?? null) ? array_values($doc['data']) : [];

        return ['data' => $data, 'included' => $this->indexIncluded($doc['included'] ?? null)];
    }

    /**
     * Index a JSON:API `included[]` array by "type:id" for O(1) resolution.
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexIncluded(mixed $included): array
    {
        if (!\is_array($included)) {
            return [];
        }

        $map = [];
        foreach ($included as $res) {
            if (!\is_array($res)) {
                continue;
            }
            $type = (string) ($res['type'] ?? '');
            $id = (string) ($res['id'] ?? '');
            if ($type !== '' && $id !== '') {
                $map[$type . ':' . $id] = $res;
            }
        }

        return $map;
    }

    /**
     * First-post body, resolved from the sideloaded `included` map. Falls back
     * to null (caller uses the title instead).
     *
     * @param array<string, mixed> $discussion
     * @param array<string, array<string, mixed>> $included
     */
    private function firstPostBody(array $discussion, array $included): ?string
    {
        $postId = $discussion['relationships']['firstPost']['data']['id'] ?? null;
        if ($postId === null) {
            return null;
        }

        $res = $included['posts:' . $postId] ?? null;
        $body = $res['attributes']['contentHtml']
            ?? $res['attributes']['content']
            ?? null;

        if (!\is_string($body) || trim($body) === '') {
            return null;
        }

        return trim(strip_tags($body));
    }

    /**
     * @param array<string, mixed> $discussion
     * @param array<string, array<string, mixed>> $included
     */
    private function authorHandle(array $discussion, array $included): ?string
    {
        $userId = $discussion['relationships']['user']['data']['id'] ?? null;
        if ($userId === null) {
            return null;
        }

        $res = $included['users:' . $userId] ?? null;
        $handle = $res['attributes']['username']
            ?? $res['attributes']['displayName']
            ?? null;

        return \is_string($handle) && trim($handle) !== '' ? trim($handle) : null;
    }

    /**
     * Tag slugs, resolved from `relationships.tags.data[]` {type,id} links
     * against the sideloaded `included` map.
     *
     * @param array<string, mixed> $discussion
     * @param array<string, array<string, mixed>> $included
     * @return list<string>
     */
    private function tagSlugs(array $discussion, array $included): array
    {
        $links = $discussion['relationships']['tags']['data'] ?? [];
        if (!\is_array($links)) {
            return [];
        }

        $slugs = [];
        foreach ($links as $link) {
            $id = $link['id'] ?? null;
            if ($id === null) {
                continue;
            }
            $slug = $included['tags:' . $id]['attributes']['slug'] ?? null;
            if (\is_string($slug) && $slug !== '') {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * GET + decode a JSON:API endpoint. Throws on transport errors AND non-2xx
     * status (getContent() default) so callers' try/catch can log + continue;
     * a failed tag/keyword request must not silently look like "no results".
     *
     * @return array<string, mixed>
     */
    private function httpGetJson(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => self::USER_AGENT,
            ],
            'timeout' => 15,
            'max_redirects' => 3,
        ]);

        $decoded = json_decode($response->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    // ---- robots.txt check ------------------------------------------

    private function isDisallowedByRobotsTxt(string $base): bool
    {
        $scheme = parse_url($base, \PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($base, \PHP_URL_HOST) ?: '';

        if ($host === '') {
            return false;
        }

        $robotsUrl = sprintf('%s://%s/robots.txt', $scheme, $host);

        try {
            $response = $this->httpClient->request('GET', $robotsUrl, [
                'timeout' => 10,
                'max_redirects' => 3,
            ]);
            $body = $response->getContent(false);
        } catch (\Throwable) {
            return false; // if robots.txt is unreachable, proceed
        }

        $disallowed = $this->parseRobotsDisallowed($body, strtolower(self::USER_AGENT), '*');
        if ($disallowed === []) {
            return false;
        }

        $paths = ['/api/', '/'];
        foreach ($paths as $path) {
            foreach ($disallowed as $d) {
                if (str_starts_with($path, $d) || $d === '/' || $d === '/api/') {
                    $this->logger?->info('Flarum-Adapter: robots.txt verbietet "{path}" — pull übersprungen.', [
                        'path' => $d,
                        'base' => $base,
                    ]);

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Parse robots.txt for User-Agent disallowed paths. Simple substring match.
     *
     * @return list<string>
     */
    private function parseRobotsDisallowed(string $robotsTxt, string ...$agents): array
    {
        $lines = explode("\n", $robotsTxt);
        $currentAgent = null;
        $disallowed = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Normalise: lowercase field names, collapse whitespace
            $line = preg_replace('/\s+/', ' ', strtolower($line));
            if ($line === null) {
                continue;
            }

            if (str_starts_with($line, 'user-agent:')) {
                $ua = trim(substr($line, 11), " \t\n\r\0\x0B\"'");
                $currentAgent = $ua;
                continue;
            }

            if ($currentAgent !== null && \in_array($currentAgent, $agents, true) && str_starts_with($line, 'disallow:')) {
                $path = trim(substr($line, 9));
                if ($path !== '') {
                    $disallowed[] = $path;
                }
            }
        }

        return array_values(array_unique($disallowed));
    }

    // ---- config accessors ------------------------------------------

    private function baseUrl(Channel $channel): string
    {
        $url = $channel->getInboundConfig()['baseUrl'] ?? '';

        return \is_string($url) ? rtrim(trim($url), '/') : '';
    }

    /**
     * @return list<string>
     */
    private function normaliseStringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $v) {
            if (\is_string($v) && trim($v) !== '') {
                $out[] = trim($v);
            }
        }

        return array_values(array_unique($out));
    }
}
