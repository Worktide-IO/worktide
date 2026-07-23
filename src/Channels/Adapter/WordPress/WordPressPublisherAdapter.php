<?php

declare(strict_types=1);

namespace App\Channels\Adapter\WordPress;

use App\Channels\OutboundAdapter;
use App\Entity\Channel;
use App\Http\OutboundUrlGuard;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Outbound adapter for publishing content to a WordPress site via its REST API.
 * Typically used from an AI Agent Action — when a marketing draft is accepted,
 * the RecommendationApplier can create a WordPress post through this adapter.
 *
 * Channel.authConfig:
 *   - wpUrl:    WordPress site URL (e.g. https://example.com)
 *   - username: Application Password user name
 *   - password: Application Password (NOT the account password)
 *
 * Channel.inboundConfig:
 *   - defaultStatus: "draft" (default) or "publish"
 *   - defaultCategory: category ID (optional)
 */
final class WordPressPublisherAdapter implements OutboundAdapter
{
    public const CODE = 'wordpress_blog';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OutboundUrlGuard $urlGuard,
    ) {}

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'WordPress Blog';
    }

    public function supportsInbound(): bool
    {
        return false;
    }

    public function supportsOutbound(): bool
    {
        return true;
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    public function send(Channel $channel, string $subject, string $body, array $context = []): string
    {
        $auth = $channel->getAuthConfig() ?? [];
        $wpUrl = rtrim($auth['wpUrl'] ?? '', '/');
        $username = $auth['username'] ?? '';
        $password = $auth['password'] ?? '';

        if ($wpUrl === '' || $username === '' || $password === '') {
            throw new \RuntimeException('WordPress adapter requires wpUrl, username and password in authConfig.');
        }

        $config = $channel->getInboundConfig() ?? [];
        $status = $config['defaultStatus'] ?? 'draft';

        $payload = [
            'title' => $subject,
            'content' => $body,
            'status' => $status,
        ];

        $categoryId = $config['defaultCategory'] ?? null;
        if ($categoryId !== null) {
            $payload['categories'] = [(int) $categoryId];
        }

        $url = $wpUrl . '/wp-json/wp/v2/posts';

        $response = $this->httpClient->request('POST', $url, [
            'auth_basic' => [$username, $password],
            'json' => $payload,
            'timeout' => 30,
        ]);

        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $postId = $data['id'] ?? null;
        if ($postId === null) {
            throw new \RuntimeException('WordPress API did not return a post ID.');
        }

        return (string) $postId;
    }
}
