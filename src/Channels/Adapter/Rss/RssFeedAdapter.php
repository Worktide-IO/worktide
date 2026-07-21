<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Rss;

use App\Channels\InboundAdapter;
use App\Channels\InboundResult;
use App\Channels\WebhookNotSupportedException;
use App\Entity\Channel;
use App\Entity\InboundEvent;
use App\Http\OutboundUrlGuard;
use App\Repository\InboundEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pull-based RSS/Atom feed reader.
 *
 * Every pull cycle fetches the feed URL, parses the XML, deduplicates
 * against {@see InboundEvent::$externalId} (unique per channel), and
 * ingests new items as inbound events. No threading — each feed item
 * stands alone as its own event.
 *
 * Cursor: the newest pubDate seen is stored in channel.inboundConfig
 * and used to filter only newer items on the next pull.
 */
final class RssFeedAdapter implements InboundAdapter
{
    public const CODE = 'rss_feed';

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
        return 'RSS / Atom Feed';
    }

    public function pull(Channel $channel): InboundResult
    {
        $inbound = $channel->getInboundConfig();
        $feedUrl = \is_array($inbound) ? ($inbound['feedUrl'] ?? '') : '';
        if (!\is_string($feedUrl) || $feedUrl === '') {
            return new InboundResult();
        }

        // SSRF guard — only public hosts for RSS feeds
        OutboundUrlGuard::ensurePublic($feedUrl);

        $resp = $this->httpClient->request('GET', $feedUrl, [
            'max_redirects' => 3,
            'timeout' => 30,
        ]);

        $body = $resp->getContent();
        try {
            $xml = new \SimpleXMLElement($body);
        } catch (\Exception) {
            return new InboundResult();
        }

        $items = $this->parseItems($xml);
        if ($items === []) {
            return new InboundResult();
        }

        // Sort oldest first (natural order)
        usort($items, static fn (array $a, array $b) => ($a['pubDate'] ?? 0) <=> ($b['pubDate'] ?? 0));

        $cursor = \is_array($inbound) ? ($inbound['cursor'] ?? '') : '';
        $maxCursor = '';
        $newEvents = [];

        foreach ($items as $item) {
            $itemCursor = \is_string($item['pubDate'] ?? null) ? $item['pubDate'] : '0';
            if ($itemCursor <= $cursor) {
                continue;
            }
            $maxCursor = $itemCursor;

            $externalId = $item['guid'] ?? $item['link'] ?? hash('sha256', ($item['title'] ?? '') . ($item['pubDate'] ?? ''));

            // Dedup via unique constraint
            if ($this->events->findByExternalId($channel, $externalId) !== null) {
                continue;
            }

            $event = (new InboundEvent())
                ->setWorkspace($channel->getWorkspace())
                ->setChannel($channel)
                ->setExternalId($externalId)
                ->setSenderRaw($this->trim($item['author'] ?? $item['feedTitle'] ?? null, 200))
                ->setSubject($this->trim($item['title'] ?? null, 250))
                ->setBody($this->stripHtml($item['description'] ?? $item['content'] ?? ''))
                ->setReceivedAt(new \DateTimeImmutable('@' . ((int) $item['pubDateTimestamp'])))
                ->setSourceMetadata($item)
                ->setTraceUrl($this->trim($item['link'] ?? null, 500));

            $this->em->persist($event);
            $newEvents[] = $event;
        }

        $newCursor = $maxCursor ?: $cursor;

        return new InboundResult($newEvents, $newCursor);
    }

    public function consumeWebhook(Channel $channel, \Symfony\Component\HttpFoundation\Request $request): InboundResult
    {
        throw new WebhookNotSupportedException('rss_feed is pull-only. Use cron: worktide:channel:pull .');
    }

    // ── XML parsing ──────────────────────────────────────────────

    /**
     * @return list<array{guid?:string, link?:string, title?:string, description?:string, content?:string, author?:string, pubDate?:string, pubDateTimestamp:int, feedTitle?:string, categories?:list<string>}>
     */
    private function parseItems(\SimpleXMLElement $xml): array
    {
        $feedTitle = $this->text($xml->channel->title ?? $xml->title ?? null);
        $items = [];

        // RSS 2.0
        if ($xml->channel && $xml->channel->item) {
            foreach ($xml->channel->item as $item) {
                $ns = $item->getNamespaces(true);
                $items[] = $this->parseRssItem($item, $ns, $feedTitle);
            }
            return $items;
        }

        // Atom (no namespace prefix)
        if ($xml->entry && \count($xml->entry) > 0) {
            foreach ($xml->entry as $entry) {
                $items[] = $this->parseAtomEntry($entry, $feedTitle);
            }
            return $items;
        }

        // Atom with default namespace
        foreach ($xml->getDocNamespaces() as $ns) {
            $children = $xml->children($ns);
            if ($children && $children->entry && \count($children->entry) > 0) {
                foreach ($children->entry as $entry) {
                    $items[] = $this->parseAtomEntry($entry, $feedTitle);
                }
                return $items;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $ns
     * @return array<string, mixed>
     */
    private function parseRssItem(\SimpleXMLElement $item, array $ns, string $feedTitle): array
    {
        $pubDate = $this->text($item->pubDate);
        $ts = $pubDate !== '' ? strtotime($pubDate) : time();
        if ($ts === false) {
            $ts = time();
        }

        $guid = $this->text($item->guid);
        $isPermalink = isset($item->guid['isPermaLink']) && (string) $item->guid['isPermaLink'] === 'true';
        if ($isPermalink || $guid === '') {
            $guid = null;
        }

        $cats = [];
        foreach ($item->category ?? [] as $c) {
            $cats[] = $this->text($c);
        }

        return [
            'feedTitle' => $feedTitle,
            'guid' => $guid,
            'link' => $this->text($item->link),
            'title' => $this->text($item->title),
            'description' => $this->text($item->description),
            'content' => $this->text($item->children('content', true)->encoded ?? null),
            'author' => $this->text($item->author),
            'pubDate' => (string) $ts,
            'pubDateTimestamp' => $ts,
            'categories' => $cats,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAtomEntry(\SimpleXMLElement $entry, string $feedTitle): array
    {
        $published = $this->text($entry->published) ?: $this->text($entry->updated);
        $ts = $published !== '' ? strtotime($published) : time();
        if ($ts === false) {
            $ts = time();
        }

        $authors = [];
        foreach ($entry->author ?? [] as $a) {
            $authors[] = $this->text($a->name);
        }

        $cats = [];
        foreach ($entry->category ?? [] as $c) {
            $cats[] = $this->text($c['term'] ?? $c);
        }

        $links = [];
        foreach ($entry->link ?? [] as $l) {
            $links[] = $this->text($l['href']);
        }
        $firstLink = $links[0] ?? '';

        $content = $this->text($entry->content) ?: $this->text($entry->summary);

        return [
            'feedTitle' => $feedTitle,
            'guid' => $this->text($entry->id),
            'link' => $firstLink,
            'title' => $this->text($entry->title),
            'description' => $this->text($entry->summary) ?: $content,
            'content' => $content,
            'author' => $authors[0] ?? $feedTitle,
            'pubDate' => (string) $ts,
            'pubDateTimestamp' => $ts,
            'categories' => $cats,
        ];
    }

    // ── helpers ───────────────────────────────────────────────────

    private function text(\SimpleXMLElement|string|null $el): string
    {
        if ($el === null) {
            return '';
        }
        if (\is_string($el)) {
            return $el;
        }

        return trim((string) $el);
    }

    private function trim(?string $s, int $max): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    private function stripHtml(string $s): string
    {
        $clean = \strip_tags($s);
        $clean = \html_entity_decode($clean, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $clean = \preg_replace('/\s+/', ' ', $clean);

        return \is_string($clean) ? trim($clean) : '';
    }
}
