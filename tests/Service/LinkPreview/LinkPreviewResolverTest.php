<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkPreview;

use App\Egress\EgressGuard;
use App\Http\OutboundUrlGuard;
use App\Service\LinkPreview\LinkPreviewResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit coverage for the smart-link preview resolver over a mocked HTTP client
 * (IP-literal URLs keep the SSRF guard deterministic — no DNS). Verifies the
 * egress gate, the oEmbed provider path, the OpenGraph fallback, content-type
 * filtering, SSRF rejection, and image-URL sanitising.
 */
final class LinkPreviewResolverTest extends TestCase
{
    private function resolver(MockHttpClient $http, string $allow = 'link_preview'): LinkPreviewResolver
    {
        return new LinkPreviewResolver(
            $http,
            new OutboundUrlGuard(),
            new EgressGuard($allow),
            new ArrayAdapter(),
            new NullLogger(),
        );
    }

    public function testReturnsNullWhenEgressDenied(): void
    {
        $http = new MockHttpClient(static function (): never {
            self::fail('No outbound fetch may happen when egress is denied.');
        });

        self::assertNull($this->resolver($http, '')->resolve('https://1.1.1.1/article'));
    }

    public function testReturnsNullForNonHttpScheme(): void
    {
        $http = new MockHttpClient(static function (): never {
            self::fail('Bad scheme must never be fetched.');
        });

        self::assertNull($this->resolver($http)->resolve('file:///etc/passwd'));
        self::assertNull($this->resolver($http)->resolve('ftp://example.com/x'));
    }

    public function testReturnsNullForSsrfTarget(): void
    {
        // Not an oEmbed provider → OpenGraph path → SSRF guard rejects the host.
        $http = new MockHttpClient(static function (): never {
            self::fail('An internal target must be blocked before any fetch.');
        });

        self::assertNull($this->resolver($http)->resolve('http://169.254.169.254/latest/meta-data/'));
        self::assertNull($this->resolver($http)->resolve('http://127.0.0.1/internal'));
    }

    public function testResolvesViaOEmbedProvider(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse((string) json_encode([
                'title' => 'Rick Astley - Never Gonna Give You Up',
                'provider_name' => 'YouTube',
                'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
            ]), ['http_code' => 200]);
        });

        $preview = $this->resolver($http)->resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        self::assertNotNull($preview);
        self::assertStringContainsString('youtube.com/oembed', (string) $captured);
        self::assertSame('Rick Astley - Never Gonna Give You Up', $preview->title);
        self::assertSame('YouTube', $preview->provider);
        self::assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $preview->thumbnailUrl);
        self::assertSame('https://www.youtube.com/favicon.ico', $preview->faviconUrl);
    }

    public function testOpenGraphFallbackParsesMetaTags(): void
    {
        $html = <<<'HTML'
            <!doctype html><html><head>
              <title>ignored</title>
              <meta property="og:title" content="Design Systems &amp; Tokens">
              <meta property="og:description" content="A shared visual language.">
              <meta property="og:image" content="/assets/cover.png">
              <meta property="og:site_name" content="Figma">
            </head><body>…</body></html>
            HTML;
        $http = new MockHttpClient(new MockResponse($html, [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'text/html; charset=utf-8'],
        ]));

        $preview = $this->resolver($http)->resolve('https://1.1.1.1/design-systems');

        self::assertNotNull($preview);
        self::assertSame('Design Systems & Tokens', $preview->title);
        self::assertSame('A shared visual language.', $preview->description);
        self::assertSame('https://1.1.1.1/assets/cover.png', $preview->thumbnailUrl); // root-relative resolved
        self::assertSame('Figma', $preview->provider);
    }

    public function testOpenGraphFallsBackToTitleTag(): void
    {
        $http = new MockHttpClient(new MockResponse('<html><head><title>  Just a Title  </title></head></html>', [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'text/html'],
        ]));

        $preview = $this->resolver($http)->resolve('https://8.8.8.8/page');

        self::assertNotNull($preview);
        self::assertSame('Just a Title', $preview->title);
        self::assertNull($preview->description);
        self::assertNull($preview->thumbnailUrl);
    }

    public function testRejectsNonHtmlContentType(): void
    {
        $http = new MockHttpClient(new MockResponse('{"data":1}', [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        self::assertNull($this->resolver($http)->resolve('https://8.8.8.8/data.json'));
    }

    public function testDropsUnsafeImageScheme(): void
    {
        $html = '<html><head><meta property="og:title" content="X"><meta property="og:image" content="javascript:alert(1)"></head></html>';
        $http = new MockHttpClient(new MockResponse($html, [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'text/html'],
        ]));

        $preview = $this->resolver($http)->resolve('https://1.1.1.1/x');

        self::assertNotNull($preview);
        self::assertNull($preview->thumbnailUrl);
    }
}
