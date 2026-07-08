<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional coverage for the public GET /v1/branding endpoint.
 *
 * It is unauthenticated (needed on login/set-password pages) and must always
 * return a complete, string-typed payload with the stock Worktide defaults when
 * no BRAND_* env is customized.
 */
final class BrandingControllerTest extends WebTestCase
{
    private const HOST = 'api.worktide.ddev.site';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testBrandingIsPublicAndReturnsDefaults(): void
    {
        $this->client->request('GET', '/v1/branding', [], [], ['HTTP_HOST' => self::HOST]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $body = $this->json();
        // Every documented key is present and a string.
        foreach (['name', 'legalName', 'logoUrl', 'logoUrlDark', 'primaryColor', 'accentColor', 'imprintUrl', 'privacyUrl', 'supportEmail'] as $key) {
            self::assertArrayHasKey($key, $body);
            self::assertIsString($body[$key]);
        }

        // Stock defaults (no BRAND_* customization in the test env).
        self::assertSame('Worktide', $body['name']);
        self::assertSame('Worktide', $body['legalName']); // legalName falls back to name
        self::assertSame('#0F8C72', $body['primaryColor']);
        self::assertSame('#E0623A', $body['accentColor']);

        // Demo mode is off by default and typed as a bool in the payload.
        self::assertArrayHasKey('demoMode', $body);
        self::assertIsBool($body['demoMode']);
        self::assertFalse($body['demoMode']);
        self::assertArrayHasKey('demoBannerText', $body);
        self::assertIsString($body['demoBannerText']);

        // Empty logo URL falls back to the backend's own /branding/logo route.
        self::assertStringEndsWith('/branding/logo', $body['logoUrl']);
    }

    public function testLogoRouteServesAnImage(): void
    {
        $this->client->request('GET', '/branding/logo', [], [], ['HTTP_HOST' => self::HOST]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringStartsWith('image/', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
    }
}
