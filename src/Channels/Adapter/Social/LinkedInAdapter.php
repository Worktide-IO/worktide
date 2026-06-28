<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Social;

use App\Channels\OAuth\OAuth2Client;
use App\Channels\SocialMediaConstraints;
use App\Channels\SocialPublishResult;
use App\Channels\SocialPublisherAdapter;
use App\Entity\Channel;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Service\Social\SocialMediaResolver;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Publishes member posts to LinkedIn via the versioned REST Posts API.
 *
 * Connection (Channel): OAuth2 (authorize via {@see \App\Controller\Api\ChannelOAuthController}
 * using the `social_linkedin` provider). The bearer comes from
 * {@see OAuth2Client::ensureAccessToken()}; the author URN is read from
 * `outboundConfig.authorUrn` (e.g. "urn:li:person:xxxx" or "urn:li:organization:123")
 * and falls back to resolving the member via /v2/userinfo.
 *
 * NOTE: posting requires LinkedIn app review (w_member_social, and
 * w_organization_social for company pages). The flow here follows the
 * documented Posts API; live posting only works once the app is approved.
 *
 * Images: rest/images initializeUpload → PUT bytes → reference image URN on the
 * post content (single `media`, or `multiImage` for several).
 */
final class LinkedInAdapter implements SocialPublisherAdapter
{
    private const MAX_LENGTH = 3000;
    private const DEFAULT_VERSION = '202405';
    private const BASE = 'https://api.linkedin.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OAuth2Client $oauth,
        private readonly SocialMediaResolver $media,
    ) {}

    public function getCode(): string
    {
        return 'social_linkedin';
    }

    public function getLabel(): string
    {
        return 'LinkedIn';
    }

    public function maxLength(): int
    {
        return self::MAX_LENGTH;
    }

    public function mediaConstraints(): SocialMediaConstraints
    {
        return new SocialMediaConstraints(
            maxImages: 9,
            supportsVideo: false,
            requiresPublicMediaUrl: false,
        );
    }

    public function publish(Channel $channel, SocialPost $post, SocialPostTarget $target): SocialPublishResult
    {
        $version = (string) ($channel->getOutboundConfig()['apiVersion'] ?? self::DEFAULT_VERSION);

        try {
            $token = $this->oauth->ensureAccessToken($channel);
            $headers = [
                'Authorization' => 'Bearer ' . $token,
                'LinkedIn-Version' => $version,
                'X-Restli-Protocol-Version' => '2.0.0',
            ];

            $author = $this->resolveAuthor($channel, $headers);
            if ($author === null) {
                return SocialPublishResult::failed('Could not determine LinkedIn author URN.');
            }

            $images = [];
            foreach ($this->media->resolve($post) as $item) {
                $urn = $this->uploadImage($author, $item->bytes, $headers);
                if ($urn === null) {
                    return SocialPublishResult::retry('LinkedIn image upload failed.');
                }
                $images[] = ['id' => $urn, 'altText' => $item->altText ?? ''];
            }

            $body = [
                'author' => $author,
                'commentary' => $target->effectiveBody(),
                'visibility' => 'PUBLIC',
                'distribution' => [
                    'feedDistribution' => 'MAIN_FEED',
                    'targetEntities' => [],
                    'thirdPartyDistributionChannels' => [],
                ],
                'lifecycleState' => 'PUBLISHED',
                'isReshareDisabledByAuthor' => false,
            ];
            if (\count($images) === 1) {
                $body['content'] = ['media' => $images[0]];
            } elseif (\count($images) > 1) {
                $body['content'] = ['multiImage' => ['images' => $images]];
            }

            $resp = $this->httpClient->request('POST', self::BASE . '/rest/posts', [
                'headers' => $headers,
                'json' => $body,
            ]);
            $status = $resp->getStatusCode();
            if ($status >= 400) {
                return $this->mapError($status, $resp->toArray(false), 'post');
            }

            // The created post URN is returned in the x-restli-id response header.
            $urn = $resp->getHeaders(false)['x-restli-id'][0] ?? '';
            if ($urn === '') {
                return SocialPublishResult::failed('LinkedIn response had no post id.');
            }

            return SocialPublishResult::published($urn, 'https://www.linkedin.com/feed/update/' . $urn);
        } catch (TransportExceptionInterface $e) {
            return SocialPublishResult::retry('LinkedIn unreachable: ' . $e->getMessage());
        }
    }

    /** @param array<string, string> $headers */
    private function resolveAuthor(Channel $channel, array $headers): ?string
    {
        $configured = $channel->getOutboundConfig()['authorUrn'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $resp = $this->httpClient->request('GET', self::BASE . '/v2/userinfo', ['headers' => $headers]);
        if ($resp->getStatusCode() >= 400) {
            return null;
        }
        $sub = $resp->toArray(false)['sub'] ?? null;
        return is_string($sub) && $sub !== '' ? 'urn:li:person:' . $sub : null;
    }

    /**
     * @param array<string, string> $headers
     * @return string|null the image URN, or null on failure
     */
    private function uploadImage(string $author, string $bytes, array $headers): ?string
    {
        $init = $this->httpClient->request('POST', self::BASE . '/rest/images?action=initializeUpload', [
            'headers' => $headers,
            'json' => ['initializeUploadRequest' => ['owner' => $author]],
        ]);
        if ($init->getStatusCode() >= 400) {
            return null;
        }
        $value = $init->toArray(false)['value'] ?? [];
        $uploadUrl = $value['uploadUrl'] ?? null;
        $imageUrn = $value['image'] ?? null;
        if (!is_string($uploadUrl) || !is_string($imageUrn)) {
            return null;
        }

        $put = $this->httpClient->request('PUT', $uploadUrl, [
            'headers' => ['Authorization' => $headers['Authorization']],
            'body' => $bytes,
        ]);
        if ($put->getStatusCode() >= 400) {
            return null;
        }

        return $imageUrn;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function mapError(int $status, array $body, string $stage): SocialPublishResult
    {
        $detail = (string) ($body['message'] ?? 'unknown error');
        $message = sprintf('LinkedIn %s failed (%d): %s', $stage, $status, $detail);
        return ($status >= 500 || $status === 429)
            ? SocialPublishResult::retry($message)
            : SocialPublishResult::failed($message);
    }
}
