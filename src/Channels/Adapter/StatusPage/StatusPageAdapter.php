<?php

declare(strict_types=1);

namespace App\Channels\Adapter\StatusPage;

use App\Channels\InboundAdapter;
use App\Channels\InboundResult;
use App\Entity\Channel;
use App\Entity\InboundEvent;
use App\Http\OutboundUrlGuard;
use App\Repository\InboundEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pull-based status-page monitor. Fetches Atom/RSS feeds from provider
 * status pages (Hetzner, Mittwald, AWS, etc.) and filters incidents by
 * configurable system/component keywords.
 *
 * Channel.address = status-page feed URL (e.g. https://status.hetzner.com/en.atom)
 * Channel.inboundConfig:
 *   - systems:  array of system/component names to watch (e.g. ["cloud", "dedicated"]).
 *               If empty, ALL incidents are ingested.
 *
 * Dedup by incident GUID (atom:id or guid element). Each incident update
 * replaces the previous event for the same GUID via externalId overwrite.
 */
final class StatusPageAdapter implements InboundAdapter
{
    public const CODE = 'status_page';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly InboundEventRepository $events,
        private readonly OutboundUrlGuard $urlGuard,
    ) {}

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Status Page (Atom/RSS)';
    }

    public function consumeWebhook(Channel $channel, Request $request): InboundResult
    {
        return InboundResult::noop();
    }

    public function pull(Channel $channel): InboundResult
    {
        $url = $channel->getAddress();
        if ($url === null || $url === '') {
            return InboundResult::noop();
        }

        $config = $channel->getInboundConfig() ?? [];
        $systems = (array) ($config['systems'] ?? []);

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
            $xml = $response->getContent();
        } catch (\Throwable) {
            return InboundResult::noop();
        }

        $sx = simplexml_load_string($xml);
        if ($sx === false) {
            return InboundResult::noop();
        }

        $ns = $sx->getNamespaces(true);
        $atom = $ns[''] === 'http://www.w3.org/2005/Atom' ? $sx : ($ns[''] !== '' ? $sx : null);

        $entries = $this->entries($sx, $ns);
        if ($entries === []) {
            return InboundResult::noop();
        }

        $feedTitle = $this->feedTitle($sx, $ns);

        $events = [];
        foreach ($entries as $entry) {
            $guid = $this->text($entry, 'id') ?: $this->text($entry, 'guid');
            if ($guid === '') {
                continue;
            }

            $externalId = $this->dedupId($channel, $guid);
            $existing = $this->events->findByExternalId($channel, $externalId);

            $title = $this->text($entry, 'title');
            $summary = $this->text($entry, 'summary');
            $content = $this->text($entry, 'content');
            $updated = $this->text($entry, 'updated') ?: $this->text($entry, 'pubDate');
            $link = $this->linkHref($entry);
            $categories = $this->categories($entry);

            if ($systems !== [] && !$this->matchesSystems($title, $categories, $systems)) {
                continue;
            }

            $body = $this->buildBody($title, $summary, $content, $updated, $link);

            if ($existing !== null) {
                $existing->setSubject($this->trim($title, 250));
                $existing->setBody($body);
                $existing->setReceivedAt(new \DateTimeImmutable($updated ?: 'now'));
                continue;
            }

            $event = (new InboundEvent())
                ->setWorkspace($channel->getWorkspace())
                ->setChannel($channel)
                ->setExternalId($externalId)
                ->setSenderRaw($feedTitle)
                ->setSubject($this->trim($title, 250))
                ->setBody($body)
                ->setReceivedAt(new \DateTimeImmutable($updated ?: 'now'))
                ->setSourceMetadata(['guid' => $guid, 'categories' => $categories, 'link' => $link]);

            $this->em->persist($event);
            $events[] = $event;
        }

        return $events === [] ? InboundResult::noop() : InboundResult::events($events);
    }

    /** @return list<\SimpleXMLElement> */
    private function entries(\SimpleXMLElement $root, array $ns): array
    {
        if (isset($ns['']) && ($ns[''] === 'http://www.w3.org/2005/Atom' || $ns[''] === 'http://purl.org/atom/ns#')) {
            $entries = [];
            foreach ($root->entry as $e) {
                $entries[] = $e;
            }
            return $entries;
        }
        $entries = [];
        foreach ($root->channel->item ?? [] as $e) {
            $entries[] = $e;
        }
        return $entries;
    }

    private function feedTitle(\SimpleXMLElement $root, array $ns): string
    {
        $t = $this->text($root, 'title');
        if ($t !== '') {
            return $t;
        }
        $channel = $root->channel ?? null;
        if ($channel !== null) {
            return $this->text($channel, 'title');
        }
        return 'Status Page';
    }

    private function text(mixed $el, string $tag): string
    {
        if ($el instanceof \SimpleXMLElement) {
            $child = $el->{$tag} ?? null;
            if ($child !== null) {
                return trim(strip_tags((string) $child));
            }
        }
        return '';
    }

    private function linkHref(\SimpleXMLElement $entry): string
    {
        $link = $entry->link ?? null;
        if ($link !== null) {
            $attrs = $link->attributes();
            if ($attrs !== null && isset($attrs['href'])) {
                return (string) $attrs['href'];
            }
            if ($attrs === null && (string) $link !== '') {
                return (string) $link;
            }
        }
        return '';
    }

    /** @return list<string> */
    private function categories(\SimpleXMLElement $entry): array
    {
        $cats = [];
        foreach ($entry->category ?? [] as $cat) {
            $attrs = $cat->attributes();
            if ($attrs !== null && isset($attrs['term'])) {
                $cats[] = (string) $attrs['term'];
            } elseif ($attrs === null && (string) $cat !== '') {
                $cats[] = (string) $cat;
            }
        }
        return $cats;
    }

    /** @param list<string> $categories */
    private function matchesSystems(string $title, array $categories, array $systems): bool
    {
        $haystack = mb_strtolower($title);
        foreach ($categories as $cat) {
            $haystack .= ' ' . mb_strtolower($cat);
        }
        foreach ($systems as $sys) {
            if (str_contains($haystack, mb_strtolower($sys))) {
                return true;
            }
        }
        return false;
    }

    private function buildBody(string $title, string $summary, string $content, string $date, string $link): string
    {
        $parts = [];
        if ($title !== '') {
            $parts[] = '## ' . $title;
        }
        if ($date !== '') {
            $parts[] = sprintf('_%s_', substr($date, 0, 19));
        }
        if ($link !== '') {
            $parts[] = sprintf('[Details](%s)', $link);
        }
        if ($summary !== '' && $summary !== $title) {
            $parts[] = $summary;
        }
        if ($content !== '' && $content !== $summary) {
            $parts[] = $content;
        }
        return implode("\n\n", $parts);
    }

    private function dedupId(Channel $channel, string $guid): string
    {
        return hash('sha256', 'status-' . ($channel->getId()?->toRfc4122() ?? '?') . '-' . $guid);
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
