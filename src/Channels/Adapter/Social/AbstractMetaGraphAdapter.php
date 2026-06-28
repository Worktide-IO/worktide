<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Social;

use App\Channels\SocialPublishResult;
use App\Channels\SocialPublisherAdapter;
use App\Entity\Channel;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Shared plumbing for the Meta Graph API publishers (Facebook Pages and
 * Instagram Business). Holds the HTTP client, the Graph version/base URL, and
 * the common error-shape mapping ({"error":{"message":...}}).
 *
 * Connection (Channel): S3 uses pasted long-lived tokens (Page token / IG
 * token) + ids in config — no app-review-gated OAuth dance in the publish path.
 * Full Meta OAuth (user token → /me/accounts → page tokens) can be layered on
 * later without changing publishing.
 */
abstract class AbstractMetaGraphAdapter implements SocialPublisherAdapter
{
    protected const DEFAULT_VERSION = 'v21.0';

    public function __construct(
        protected readonly HttpClientInterface $httpClient,
    ) {}

    protected function graphBase(Channel $channel): string
    {
        $version = (string) ($channel->getOutboundConfig()['graphVersion'] ?? static::DEFAULT_VERSION);
        return 'https://graph.facebook.com/' . $version;
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function mapError(int $status, array $body, string $stage): SocialPublishResult
    {
        $err = $body['error'] ?? [];
        $detail = is_array($err) ? (string) ($err['message'] ?? 'unknown error') : 'unknown error';
        $message = sprintf('%s %s failed (%d): %s', $this->getLabel(), $stage, $status, $detail);
        // 5xx + rate limit (4 / 17 / 32 / 613) are transient; other 4xx are permanent.
        $code = is_array($err) ? (int) ($err['code'] ?? 0) : 0;
        $transient = $status >= 500 || \in_array($code, [4, 17, 32, 613], true);
        return $transient
            ? SocialPublishResult::retry($message)
            : SocialPublishResult::failed($message);
    }
}
