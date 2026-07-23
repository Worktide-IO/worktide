<?php

declare(strict_types=1);

namespace App\Channels\Adapter\WordPress;

use App\Channels\OutboundAdapter;
use App\Channels\OutboundResult;
use App\Entity\Channel;
use App\Entity\OutboundMessage;
use App\Http\OutboundUrlGuard;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Outbound adapter for publishing content to a WordPress site via its REST API.
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

    public function send(Channel $channel, OutboundMessage $message): OutboundResult
    {
        $auth = $channel->getAuthConfig() ?? [];
        $wpUrl = rtrim($auth['wpUrl'] ?? '', '/');
        $username = $auth['username'] ?? '';
        $password = $auth['password'] ?? '';

        if ($wpUrl === '' || $username === '' || $password === '') {
            return OutboundResult::fail('WordPress adapter requires wpUrl, username and password in authConfig.');
        }

        $config = $channel->getInboundConfig() ?? [];
        $status = $config['defaultStatus'] ?? 'draft';

        $payload = [
            'title' => mb_substr($message->getSubject() ?? 'Untitled', 0, 500),
            'content' => $message->getBody() ?? '',
            'status' => $status,
        ];

        $categoryId = $config['defaultCategory'] ?? null;
        if ($categoryId !== null) {
            $payload['categories'] = [(int) $categoryId];
        }

        try {
            $response = $this->httpClient->request('POST', $wpUrl . '/wp-json/wp/v2/posts', [
                'auth_basic' => [$username, $password],
                'json' => $payload,
                'timeout' => 30,
            ]);

            $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $postId = $data['id'] ?? null;

            if ($postId === null) {
                return OutboundResult::fail('WordPress API did not return a post ID.');
            }

            return OutboundResult::sent((string) $postId);
        } catch (\Throwable $e) {
            return OutboundResult::fail($e->getMessage());
        }
    }
}
