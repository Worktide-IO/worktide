<?php

declare(strict_types=1);

namespace App\Tests\Channels\Adapter\Flarum;

use App\Channels\Adapter\Flarum\FlarumAdapter;
use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Channel;
use App\Entity\InboundEvent;
use App\Entity\Workspace;
use App\Repository\InboundEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit coverage for the Flarum pull adapter over a mocked HTTP client
 * (no network). Pins the discussion→InboundEvent mapping, JSON:API
 * `included[]` sideload resolution (tags/first-post/author), dedup via
 * high-water mark and externalId, author-handle toggle, multi-keyword
 * resilience, robots.txt blocking and the egress gate.
 *
 * Fixtures use the REAL JSON:API shape: relationship linkage carries only
 * {type,id}; attributes (tag slug, post body, username) live in `included[]`.
 */
final class FlarumAdapterTest extends TestCase
{
    public function testPullMapsDiscussionsToEvents(): void
    {
        $adapter = new FlarumAdapter(
            $this->http([$this->discussion(42, 'TYPO3 v13 released', 'typo3-v13-released', '2026-07-20T10:00:00+00:00', 3)]),
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
            $this->egress(),
        );
        $channel = $this->channel();

        $result = $adapter->pull($channel);

        self::assertCount(1, $result->events);
        $event = $result->events[0];
        self::assertSame('discussion:42', $event->getExternalId());
        self::assertSame('TYPO3 v13 released', $event->getSubject());
        self::assertSame('Awesome news about TYPO3!', $event->getBody());
        self::assertNull($event->getSenderRaw()); // default: includeAuthorHandle=false
        self::assertSame('https://example.test/d/42-typo3-v13-released', $event->getTraceUrl());
        $meta = $event->getSourceMetadata();
        self::assertSame(42, $meta['discussionId']);
        self::assertSame('tag:typo3', $meta['matchedBy']);
        self::assertSame(['typo3'], $meta['tags']); // resolved from included[]
    }

    public function testHighWaterMarkIdempotency(): void
    {
        $adapter = new FlarumAdapter(
            $this->http([$this->discussion(42, 'old', 'old', '2026-01-01T00:00:00+00:00', 0)]),
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
            $this->egress(),
        );
        $channel = $this->channel(seenHighWaterId: 42);

        $result = $adapter->pull($channel);

        self::assertCount(0, $result->events);
    }

    public function testDedupByExternalId(): void
    {
        $events = $this->createStub(InboundEventRepository::class);
        $events->method('findByExternalId')->willReturnCallback(
            fn (Channel $c, string $id): ?InboundEvent => $id === 'discussion:42' ? new InboundEvent() : null,
        );
        $adapter = new FlarumAdapter(
            $this->http([$this->discussion(42, 'seen', 'seen', '2026-07-20T10:00:00+00:00', 0)]),
            $this->createStub(EntityManagerInterface::class),
            $events,
            $this->egress(),
        );
        $channel = $this->channel();

        $result = $adapter->pull($channel);

        self::assertCount(0, $result->events);
    }

    public function testAuthorHandleIncludedWhenConfigured(): void
    {
        $adapter = new FlarumAdapter(
            $this->http([$this->discussion(42, 'topic', 'topic', '2026-07-20T10:00:00+00:00', 0)]),
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
            $this->egress(),
        );
        $channel = $this->channel(includeAuthorHandle: true);

        $result = $adapter->pull($channel);

        self::assertCount(1, $result->events);
        self::assertSame('forum_user', $result->events[0]->getSenderRaw());
    }

    public function testKeywordRequestFailingDoesNotAbortTagRequests(): void
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            $u = urldecode($url);
            if (str_contains($u, '/robots.txt')) {
                return new MockResponse('', ['http_code' => 404]);
            }
            if (str_contains($u, 'filter[tag]')) {
                return $this->doc([$this->discussion(55, 'Tag match', 'tag-match', '2026-07-20T10:00:00+00:00', 0)]);
            }
            if (str_contains($u, 'filter[q]')) {
                return new MockResponse('', ['http_code' => 500]);
            }

            return new MockResponse('{}');
        });

        $adapter = new FlarumAdapter(
            $http,
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
            $this->egress(),
        );
        $channel = $this->channel(tags: ['typo3'], keywords: ['broken']);

        $result = $adapter->pull($channel);

        self::assertCount(1, $result->events);
        self::assertSame('discussion:55', $result->events[0]->getExternalId());
    }

    public function testRobotsTxtDisallowBlocksPull(): void
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/robots.txt')) {
                return new MockResponse("User-agent: *\nDisallow: /api/\n");
            }

            return $this->doc([$this->discussion(1, 'topic', 'topic', '2026-07-20T10:00:00+00:00', 0)]);
        });
        $adapter = new FlarumAdapter(
            $http,
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
            $this->egress(),
        );
        $channel = $this->channel();

        $result = $adapter->pull($channel);

        self::assertCount(0, $result->events);
    }

    public function testRobotsTxtNotFoundProceedsPull(): void
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/robots.txt')) {
                return new MockResponse('', ['http_code' => 404]);
            }

            return $this->doc([$this->discussion(1, 'topic', 'topic', '2026-07-20T10:00:00+00:00', 0)]);
        });
        $adapter = new FlarumAdapter(
            $http,
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
            $this->egress(),
        );
        $channel = $this->channel();

        $result = $adapter->pull($channel);

        self::assertCount(1, $result->events);
    }

    public function testEgressDisabledReturnsEmpty(): void
    {
        $egress = new EgressGuard(''); // nothing allowed

        $adapter = new FlarumAdapter(
            $this->http([$this->discussion(1, 'topic', 'topic', '2026-07-20T10:00:00+00:00', 0)]),
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
            $egress,
        );
        $channel = $this->channel();

        $result = $adapter->pull($channel);

        self::assertCount(0, $result->events);
    }

    public function testSelfTestOkWhenReachable(): void
    {
        $http = new MockHttpClient([
            new MockResponse((string) json_encode(['data' => []])),
        ]);
        $adapter = new FlarumAdapter(
            $http,
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
            $this->egress(),
        );

        $result = $adapter->selfTest($this->channel());

        self::assertSame('ok', $result->status);
    }

    public function testSelfTestFailsWhenUnreachable(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
        ]);
        $adapter = new FlarumAdapter(
            $http,
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
            $this->egress(),
        );

        $result = $adapter->selfTest($this->channel());

        self::assertSame('failed', $result->status);
    }

    public function testKeywordsMatchedViaFilterQAreSeparateRequests(): void
    {
        $capturedUrls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$capturedUrls): MockResponse {
            $capturedUrls[] = urldecode($url);
            $u = urldecode($url);
            if (str_contains($u, '/robots.txt')) {
                return new MockResponse('', ['http_code' => 404]);
            }
            if (str_contains($u, 'filter[tag]')) {
                return $this->doc([]);
            }
            if (str_contains($u, 'filter[q]')) {
                return $this->doc([$this->discussion(10, 'keyword hit', 'keyword-hit', '2026-07-20T10:00:00+00:00', 1)]);
            }

            return new MockResponse('{}');
        });

        $adapter = new FlarumAdapter(
            $http,
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
            $this->egress(),
        );
        $channel = $this->channel(tags: ['typo3'], keywords: ['security']);

        $result = $adapter->pull($channel);

        // filter[tag] and filter[q] are mutually exclusive → two separate requests.
        self::assertCount(1, $result->events);
        self::assertSame('discussion:10', $result->events[0]->getExternalId());
        $joined = implode(' ', $capturedUrls);
        self::assertStringContainsString('filter[tag]=typo3', $joined);
        self::assertStringContainsString('filter[q]=security', $joined);
    }

    public function testNoTagsOrKeywordsReturnsEmpty(): void
    {
        $adapter = new FlarumAdapter(
            $this->http([]),
            $this->createStub(EntityManagerInterface::class),
            $this->eventsRepo(),
            $this->egress(),
        );
        $channel = (new Channel())
            ->setName('Flarum')
            ->setAdapterCode(FlarumAdapter::CODE)
            ->setWorkspace(new Workspace())
            ->setInboundConfig(['baseUrl' => 'https://example.test']);

        $result = $adapter->pull($channel);

        self::assertCount(0, $result->events);
    }

    // ---- helpers --------------------------------------------------

    private function eventsRepo(): InboundEventRepository
    {
        $events = $this->createStub(InboundEventRepository::class);
        $events->method('findByExternalId')->willReturn(null);

        return $events;
    }

    private function egress(): EgressGuard
    {
        return new EgressGuard(EgressModule::ForumMonitor->value);
    }

    /**
     * MockHttpClient: robots.txt → 404 (proceed), any /api/discussions → a
     * JSON:API doc wrapping the given discussions + default included[].
     *
     * @param list<array<string, mixed>> $discussions
     */
    private function http(array $discussions): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url) use ($discussions): MockResponse {
            if (str_contains($url, '/robots.txt')) {
                return new MockResponse('', ['http_code' => 404]);
            }
            if (str_contains($url, '/api/discussions')) {
                return $this->doc($discussions);
            }

            return new MockResponse('{}');
        });
    }

    /**
     * A JSON:API discussions document: primary data + the included[] resources
     * the discussion links to (first post, author, tag).
     *
     * @param list<array<string, mixed>> $discussions
     */
    private function doc(array $discussions): MockResponse
    {
        return new MockResponse((string) json_encode([
            'data' => $discussions,
            'included' => [
                ['type' => 'posts', 'id' => '99', 'attributes' => ['contentHtml' => '<p>Awesome news about TYPO3!</p>']],
                ['type' => 'users', 'id' => '1', 'attributes' => ['username' => 'forum_user']],
                ['type' => 'tags', 'id' => '10', 'attributes' => ['slug' => 'typo3', 'name' => 'TYPO3']],
            ],
        ]));
    }

    /**
     * A real-shape JSON:API discussion resource: relationship linkage carries
     * only {type,id} — NOT attributes (those live in included[]).
     *
     * @return array<string, mixed>
     */
    private function discussion(int $id, string $title, string $slug, string $createdAt, int $commentCount): array
    {
        return [
            'type' => 'discussions',
            'id' => (string) $id,
            'attributes' => [
                'title' => $title,
                'slug' => $slug,
                'commentCount' => $commentCount,
                'createdAt' => $createdAt,
                'lastPostedAt' => $createdAt,
            ],
            'relationships' => [
                'firstPost' => ['data' => ['type' => 'posts', 'id' => '99']],
                'user' => ['data' => ['type' => 'users', 'id' => '1']],
                'tags' => ['data' => [['type' => 'tags', 'id' => '10']]],
            ],
        ];
    }

    private function channel(
        int $seenHighWaterId = 0,
        bool $includeAuthorHandle = false,
        array $tags = ['typo3'],
        array $keywords = [],
    ): Channel {
        $cfg = ['baseUrl' => 'https://example.test'];
        if ($seenHighWaterId > 0) {
            $cfg['seenHighWaterId'] = $seenHighWaterId;
        }
        if ($includeAuthorHandle) {
            $cfg['includeAuthorHandle'] = true;
        }
        if ($tags !== []) {
            $cfg['tags'] = $tags;
        }
        if ($keywords !== []) {
            $cfg['keywords'] = $keywords;
        }

        return (new Channel())
            ->setName('Flarum')
            ->setAdapterCode(FlarumAdapter::CODE)
            ->setWorkspace(new Workspace())
            ->setInboundConfig($cfg);
    }
}
