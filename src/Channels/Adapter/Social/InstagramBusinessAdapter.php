<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Social;

use App\Channels\SocialMediaConstraints;
use App\Channels\SocialPublishResult;
use App\Entity\Channel;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Service\Social\SocialMediaUrlSigner;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Publishes to an Instagram Business/Creator account via the Graph API.
 *
 * Connection (Channel):
 *   outboundConfig.igUserId   the IG Business user id
 *   authConfig.accessToken    long-lived token of the linked Page (encrypted)
 *
 * IG requires at least one image and pulls it from a public `image_url`, so we
 * hand Graph a short-lived signed URL ({@see SocialMediaUrlSigner} →
 * {@see \App\Controller\Api\PublicSocialMediaController}). Flow: create media
 * container(s) → publish → fetch the permalink. Multiple images become a
 * CAROUSEL.
 */
final class InstagramBusinessAdapter extends AbstractMetaGraphAdapter
{
    public function __construct(
        HttpClientInterface $httpClient,
        private readonly SocialMediaUrlSigner $urlSigner,
    ) {
        parent::__construct($httpClient);
    }

    public function getCode(): string
    {
        return 'social_instagram';
    }

    public function getLabel(): string
    {
        return 'Instagram';
    }

    public function maxLength(): int
    {
        return 2200;
    }

    public function mediaConstraints(): SocialMediaConstraints
    {
        return new SocialMediaConstraints(
            maxImages: 10,
            supportsVideo: false,
            requiresPublicMediaUrl: true,
        );
    }

    public function publish(Channel $channel, SocialPost $post, SocialPostTarget $target): SocialPublishResult
    {
        $igUserId = (string) ($channel->getOutboundConfig()['igUserId'] ?? '');
        $token = (string) ($channel->getAuthConfig()['accessToken'] ?? $channel->getAuthConfig()['pageAccessToken'] ?? '');
        if ($igUserId === '' || $token === '') {
            return SocialPublishResult::failed('Instagram channel missing igUserId or accessToken.');
        }

        $urls = $this->publicImageUrls($post);
        if ($urls === []) {
            return SocialPublishResult::failed('Instagram requires at least one image.');
        }

        $base = $this->graphBase($channel);
        $caption = $target->effectiveBody();

        try {
            // 1. Container(s)
            if (\count($urls) === 1) {
                $creationId = $this->createContainer($base, $igUserId, $token, ['image_url' => $urls[0], 'caption' => $caption]);
            } else {
                $children = [];
                foreach ($urls as $url) {
                    $childId = $this->createContainer($base, $igUserId, $token, ['image_url' => $url, 'is_carousel_item' => 'true']);
                    if ($childId === null) {
                        return SocialPublishResult::retry('Instagram carousel item creation failed.');
                    }
                    $children[] = $childId;
                }
                $creationId = $this->createContainer($base, $igUserId, $token, [
                    'media_type' => 'CAROUSEL',
                    'children' => implode(',', $children),
                    'caption' => $caption,
                ]);
            }
            if ($creationId === null) {
                return SocialPublishResult::retry('Instagram media container creation failed.');
            }

            // 2. Publish
            $resp = $this->httpClient->request('POST', $base . '/' . $igUserId . '/media_publish', [
                'body' => ['creation_id' => $creationId, 'access_token' => $token],
            ]);
            $status = $resp->getStatusCode();
            $body = $resp->toArray(false);
            if ($status >= 400) {
                return $this->mapError($status, $body, 'media_publish');
            }
            $mediaId = (string) ($body['id'] ?? '');
            if ($mediaId === '') {
                return SocialPublishResult::failed('Instagram publish response had no id.');
            }

            // 3. Permalink (best-effort)
            return SocialPublishResult::published($mediaId, $this->fetchPermalink($base, $mediaId, $token));
        } catch (TransportExceptionInterface $e) {
            return SocialPublishResult::retry('Instagram unreachable: ' . $e->getMessage());
        }
    }

    /**
     * @return list<string> signed public URLs, one per resolvable mediaRef
     */
    private function publicImageUrls(SocialPost $post): array
    {
        $urls = [];
        foreach ($post->getMediaRefs() as $ref) {
            $fileId = $ref['fileId'] ?? null;
            if (!is_string($fileId) || $fileId === '') {
                continue;
            }
            try {
                $urls[] = $this->urlSigner->publicUrl(Uuid::fromString($fileId));
            } catch (\InvalidArgumentException) {
                // skip malformed ref
            }
        }
        return $urls;
    }

    /**
     * @param array<string, string> $params
     */
    private function createContainer(string $base, string $igUserId, string $token, array $params): ?string
    {
        $resp = $this->httpClient->request('POST', $base . '/' . $igUserId . '/media', [
            'body' => $params + ['access_token' => $token],
        ]);
        if ($resp->getStatusCode() >= 400) {
            return null;
        }
        $id = $resp->toArray(false)['id'] ?? null;
        return is_string($id) ? $id : null;
    }

    private function fetchPermalink(string $base, string $mediaId, string $token): ?string
    {
        try {
            $resp = $this->httpClient->request('GET', $base . '/' . $mediaId, [
                'query' => ['fields' => 'permalink', 'access_token' => $token],
            ]);
            if ($resp->getStatusCode() >= 400) {
                return null;
            }
            $permalink = $resp->toArray(false)['permalink'] ?? null;
            return is_string($permalink) ? $permalink : null;
        } catch (TransportExceptionInterface) {
            return null;
        }
    }
}
