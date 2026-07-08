<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The inbound-webhook endpoint answers the provider subscription-validation
 * handshake (Microsoft Graph et al. POST with ?validationToken=… and expect it
 * echoed verbatim as text/plain 200), before any token/channel resolution.
 */
final class WebhookValidationHandshakeTest extends WebTestCase
{
    private const HOST = 'api.worktide.ddev.site';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testValidationTokenIsEchoedAsText(): void
    {
        $token = str_repeat('a', 24); // matches the route requirement
        $this->client->request(
            'POST',
            "/v1/inbound/webhooks/{$token}?validationToken=Validate-123_abc",
            [],
            [],
            ['HTTP_HOST' => self::HOST],
        );

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertSame('Validate-123_abc', $response->getContent());
    }
}
