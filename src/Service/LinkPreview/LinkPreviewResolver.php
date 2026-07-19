<?php

declare(strict_types=1);

namespace App\Service\LinkPreview;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Http\OutboundUrlGuard;
use App\Http\UnsafeUrlException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Turns a user-pasted EXTERNAL URL into a {@see LinkPreview} card (title +
 * description + thumbnail) for the smart-link editor chips.
 *
 * Every fetch is behind the default-deny {@see EgressModule::LinkPreview} gate
 * and, for the page fetch, SSRF-guarded ({@see OutboundUrlGuard}) with the
 * connection pinned to the validated public IP + redirects disabled — the same
 * hardening the ICS importer and webhook sender use. Results (and negatives)
 * are cached so re-rendering a document with many cards fetches each URL once.
 *
 * Resolution cascade:
 *   1. oEmbed provider whitelist (YouTube, Vimeo, Figma, …) — a call to the
 *      provider's KNOWN endpoint, robust structured JSON, no HTML parsing.
 *   2. OpenGraph fallback — fetch the page itself and read <meta og:*> /
 *      <title>. Bounded read, text/html only.
 * Anything that fails or is blocked returns null; the SPA then renders a plain
 * host chip.
 */
final class LinkPreviewResolver
{
    /** Successful previews are stable — cache a day. */
    private const CACHE_TTL_HIT = 86400;
    /** Failures/blocked URLs — short TTL so a transient error can recover. */
    private const CACHE_TTL_MISS = 600;
    /** Cap the OpenGraph HTML read; meta tags live in the <head>. */
    private const MAX_HTML_BYTES = 512_000;

    /**
     * host suffix => oEmbed endpoint (JSON). The user URL is passed as ?url=.
     * We connect to these KNOWN hosts, so they need no SSRF guard.
     *
     * @var array<string, string>
     */
    private const OEMBED_PROVIDERS = [
        'youtube.com' => 'https://www.youtube.com/oembed',
        'youtu.be' => 'https://www.youtube.com/oembed',
        'vimeo.com' => 'https://vimeo.com/api/oembed.json',
        'figma.com' => 'https://www.figma.com/api/oembed',
        'soundcloud.com' => 'https://soundcloud.com/oembed',
        'flickr.com' => 'https://www.flickr.com/services/oembed',
        'flic.kr' => 'https://www.flickr.com/services/oembed',
        'speakerdeck.com' => 'https://speakerdeck.com/oembed.json',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OutboundUrlGuard $urlGuard,
        private readonly EgressGuard $egress,
        private readonly CacheInterface $linkPreviewCache,
        private readonly LoggerInterface $logger,
    ) {}

    public function resolve(string $url): ?LinkPreview
    {
        $url = trim($url);
        if ($url === '' || !$this->egress->isAllowed(EgressModule::LinkPreview)) {
            return null;
        }

        // Cheap scheme/host parse (no DNS) — enough to route + reject bad schemes.
        // The full SSRF guard (DNS + IP pinning) runs only for the OpenGraph
        // page fetch below; oEmbed connects to a trusted provider host, not this.
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = isset($parts['host']) ? trim((string) $parts['host'], '[]') : '';
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return null;
        }

        $cacheKey = 'link_preview.' . hash('xxh128', $url);

        return $this->linkPreviewCache->get($cacheKey, function (ItemInterface $item) use ($url, $host): ?LinkPreview {
            $preview = $this->fetch($url, $host);
            $item->expiresAfter($preview !== null ? self::CACHE_TTL_HIT : self::CACHE_TTL_MISS);

            return $preview;
        });
    }

    private function fetch(string $url, string $host): ?LinkPreview
    {
        $preview = $this->tryOEmbed($url, $host);
        if ($preview !== null) {
            return $preview;
        }

        return $this->tryOpenGraph($url, $host);
    }

    private function tryOEmbed(string $url, string $host): ?LinkPreview
    {
        $endpoint = null;
        $needle = strtolower($host);
        foreach (self::OEMBED_PROVIDERS as $suffix => $ep) {
            if ($needle === $suffix || str_ends_with($needle, '.' . $suffix)) {
                $endpoint = $ep;
                break;
            }
        }
        if ($endpoint === null) {
            return null;
        }

        try {
            $resp = $this->httpClient->request('GET', $endpoint, [
                'query' => ['url' => $url, 'format' => 'json', 'maxwidth' => 640],
                'timeout' => 8,
                'max_duration' => 12,
                'max_redirects' => 3, // trusted provider host
                'headers' => ['Accept' => 'application/json'],
            ]);
            if ($resp->getStatusCode() >= 400) {
                return null;
            }
            $data = $resp->toArray(false);
        } catch (HttpExceptionInterface|\JsonException $e) {
            $this->logger->info('oEmbed fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        $title = \is_string($data['title'] ?? null) ? trim($data['title']) : '';
        if ($title === '') {
            return null; // no usable card — let OpenGraph try
        }

        return new LinkPreview(
            url: $url,
            title: $this->truncate($title, 200),
            description: null,
            thumbnailUrl: $this->safeImageUrl($data['thumbnail_url'] ?? null, $url),
            provider: \is_string($data['provider_name'] ?? null) ? $data['provider_name'] : $this->providerFromHost($host),
            faviconUrl: $this->faviconFor($url),
        );
    }

    private function tryOpenGraph(string $url, string $host): ?LinkPreview
    {
        // Full SSRF guard now: rejects a non-public host + gives us the IP to pin.
        try {
            $target = $this->urlGuard->assertPublicHttpUrl($url);
        } catch (UnsafeUrlException) {
            return null;
        }

        try {
            $resp = $this->httpClient->request('GET', $url, [
                'timeout' => 8,
                'max_duration' => 12,
                'max_redirects' => 0, // block redirect-to-internal SSRF bypass
                'resolve' => [$host => $target['ip']], // pin to the validated public IP (anti DNS-rebinding)
                'headers' => [
                    'Accept' => 'text/html, application/xhtml+xml',
                    'User-Agent' => 'WorktideLinkPreview/1.0 (+https://worktide.app)',
                ],
            ]);
            if ($resp->getStatusCode() >= 400) {
                return null;
            }
            $contentType = strtolower($resp->getHeaders(false)['content-type'][0] ?? '');
            if ($contentType !== '' && !str_contains($contentType, 'html')) {
                return null; // only parse HTML documents
            }

            $html = '';
            foreach ($this->httpClient->stream($resp) as $chunk) {
                $html .= $chunk->getContent();
                if (\strlen($html) >= self::MAX_HTML_BYTES) {
                    break;
                }
            }
        } catch (HttpExceptionInterface $e) {
            $this->logger->info('OpenGraph fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if ($html === '') {
            return null;
        }

        $og = $this->parseMeta($html);
        $title = $og['og:title'] ?? $og[':title'] ?? $this->parseTitleTag($html);
        if ($title === null || trim($title) === '') {
            return null;
        }

        return new LinkPreview(
            url: $url,
            title: $this->truncate(trim($title), 200),
            description: isset($og['og:description']) ? $this->truncate(trim($og['og:description']), 300) : null,
            thumbnailUrl: $this->safeImageUrl($og['og:image'] ?? null, $url),
            provider: isset($og['og:site_name']) ? trim($og['og:site_name']) : $this->providerFromHost($host),
            faviconUrl: $this->faviconFor($url),
        );
    }

    /**
     * Extract og:* / twitter:* meta tags. Only structural regex — we never
     * execute or trust the content, and downstream everything is escaped by the
     * JSON response + the React renderer.
     *
     * @return array<string, string>
     */
    private function parseMeta(string $html): array
    {
        $head = $html;
        if (($pos = stripos($html, '</head>')) !== false) {
            $head = substr($html, 0, $pos);
        }

        $out = [];
        if (preg_match_all('/<meta\b[^>]*>/i', $head, $tags) === false) {
            return $out;
        }
        foreach ($tags[0] as $tag) {
            if (!preg_match('/\b(?:property|name)\s*=\s*(["\'])(.*?)\1/i', $tag, $p)) {
                continue;
            }
            if (!preg_match('/\bcontent\s*=\s*(["\'])(.*?)\1/is', $tag, $c)) {
                continue;
            }
            $key = strtolower(trim($p[2]));
            // Normalise twitter:title etc. onto the og:* / :key slots.
            $key = str_replace('twitter:', 'og:', $key);
            if (!isset($out[$key]) && \in_array($key, ['og:title', 'og:description', 'og:image', 'og:site_name'], true)) {
                $out[$key] = html_entity_decode($c[2], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            }
        }

        return $out;
    }

    private function parseTitleTag(string $html): ?string
    {
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $m) === 1) {
            return html_entity_decode(trim($m[1]), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    /**
     * Only pass through absolute http(s) image URLs (resolving protocol-relative
     * + root-relative against the page). The browser loads these directly, so a
     * javascript:/data: value must never reach the card.
     */
    private function safeImageUrl(mixed $candidate, string $pageUrl): ?string
    {
        if (!\is_string($candidate) || trim($candidate) === '') {
            return null;
        }
        $candidate = trim($candidate);

        if (str_starts_with($candidate, '//')) {
            $scheme = parse_url($pageUrl, \PHP_URL_SCHEME) ?: 'https';
            $candidate = $scheme . ':' . $candidate;
        } elseif (str_starts_with($candidate, '/')) {
            $base = parse_url($pageUrl);
            if (!isset($base['scheme'], $base['host'])) {
                return null;
            }
            $candidate = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '') . $candidate;
        }

        $scheme = strtolower((string) (parse_url($candidate, \PHP_URL_SCHEME) ?: ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        return $candidate;
    }

    private function faviconFor(string $url): ?string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/favicon.ico';
    }

    private function providerFromHost(string $host): ?string
    {
        $host = preg_replace('/^www\./i', '', strtolower($host)) ?? $host;

        return $host !== '' ? $host : null;
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $max - 1)) . '…';
    }
}
