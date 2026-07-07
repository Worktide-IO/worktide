<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Verifies PublicEndpointRateLimitSubscriber caps the token-as-credential
 * invitation-accept endpoint before the controller runs — i.e. even a stream
 * of guesses for non-existent tokens (404s) gets throttled to a 429 with a
 * Retry-After header once the per-IP budget (public_token_accept: 10/15min)
 * is spent. This is the brute-force guard for invitation tokens.
 */
final class PublicEndpointRateLimitTest extends WebTestCase
{
    private const HOST = 'api.worktide.ddev.site';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Keep one kernel (and one limiter cache) across the request stream.
        $this->client->disableReboot();
    }

    public function testInvitationAcceptIsRateLimited(): void
    {
        // A syntactically valid but non-existent token (matches the route
        // requirement so routing is reached; the invitation lookup 404s).
        $token = str_repeat('a', 40);
        $path = "/v1/workspace_invitations/{$token}/accept";

        $sawThrottle = false;
        // Budget is 10 → the 11th request within the window must be rejected.
        for ($i = 1; $i <= 11; $i++) {
            $this->client->request('POST', $path, [], [], [
                'HTTP_HOST' => self::HOST,
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => '203.0.113.7',
            ], '{}');
            $status = $this->client->getResponse()->getStatusCode();

            if ($status === 429) {
                $sawThrottle = true;
                self::assertNotNull(
                    $this->client->getResponse()->headers->get('Retry-After'),
                    '429 must carry a Retry-After header',
                );
                break;
            }
            // Before the budget is spent, the token simply doesn't exist.
            self::assertSame(404, $status, "request #{$i} should 404, not {$status}");
        }

        self::assertTrue($sawThrottle, 'expected a 429 within 11 requests');
    }
}
