<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Social;

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
 * Publishes to Bluesky via the AT Protocol XRPC API.
 *
 * Connection (Channel):
 *   authConfig.identifier   the handle, e.g. "acme.bsky.social"
 *   authConfig.appPassword  an app password (Settings → App Passwords — NOT the
 *                           account password); encrypted at rest
 *   authConfig.service?     PDS host, defaults to "https://bsky.social"
 *
 * Bluesky uses app passwords (no OAuth), so a session is created per publish via
 * com.atproto.server.createSession. Images are uploaded as blobs and embedded.
 * Rich-text facets (clickable links/hashtags) are a known follow-up; S1 posts
 * plain text + images.
 */
final class BlueskyAdapter implements SocialPublisherAdapter
{
    private const MAX_LENGTH = 300;
    private const DEFAULT_SERVICE = 'https://bsky.social';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SocialMediaResolver $media,
    ) {}

    public function getCode(): string
    {
        return 'social_bluesky';
    }

    public function getLabel(): string
    {
        return 'Bluesky';
    }

    public function maxLength(): int
    {
        return self::MAX_LENGTH;
    }

    public function mediaConstraints(): SocialMediaConstraints
    {
        return new SocialMediaConstraints(
            maxImages: 4,
            supportsVideo: false,
            requiresPublicMediaUrl: false,
        );
    }

    public function publish(Channel $channel, SocialPost $post, SocialPostTarget $target): SocialPublishResult
    {
        $auth = $channel->getAuthConfig();
        $service = rtrim((string) ($auth['service'] ?? self::DEFAULT_SERVICE), '/');
        $identifier = (string) ($auth['identifier'] ?? '');
        $appPassword = (string) ($auth['appPassword'] ?? '');
        if ($identifier === '' || $appPassword === '') {
            return SocialPublishResult::failed('Bluesky channel missing identifier or appPassword.');
        }

        try {
            // 1. Session
            $resp = $this->httpClient->request('POST', $service . '/xrpc/com.atproto.server.createSession', [
                'json' => ['identifier' => $identifier, 'password' => $appPassword],
            ]);
            if ($resp->getStatusCode() >= 400) {
                return $this->mapError($resp->getStatusCode(), $resp->toArray(false), 'session');
            }
            $session = $resp->toArray(false);
            $jwt = (string) ($session['accessJwt'] ?? '');
            $did = (string) ($session['did'] ?? '');
            $handle = (string) ($session['handle'] ?? $identifier);
            if ($jwt === '' || $did === '') {
                return SocialPublishResult::failed('Bluesky session response incomplete.');
            }
            $bearer = ['Authorization' => 'Bearer ' . $jwt];

            // 2. Blobs
            $images = [];
            foreach ($this->media->resolve($post) as $item) {
                $resp = $this->httpClient->request('POST', $service . '/xrpc/com.atproto.repo.uploadBlob', [
                    'headers' => array_merge($bearer, ['Content-Type' => $item->mimeType]),
                    'body' => $item->bytes,
                ]);
                if ($resp->getStatusCode() >= 400) {
                    return $this->mapError($resp->getStatusCode(), $resp->toArray(false), 'blob upload');
                }
                $blob = $resp->toArray(false)['blob'] ?? null;
                if ($blob !== null) {
                    $images[] = ['alt' => $item->altText ?? '', 'image' => $blob];
                }
            }

            // 3. Record
            $record = [
                '$type' => 'app.bsky.feed.post',
                'text' => $target->effectiveBody(),
                'createdAt' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            ];
            if ($images !== []) {
                $record['embed'] = ['$type' => 'app.bsky.embed.images', 'images' => $images];
            }
            $resp = $this->httpClient->request('POST', $service . '/xrpc/com.atproto.repo.createRecord', [
                'headers' => $bearer,
                'json' => ['repo' => $did, 'collection' => 'app.bsky.feed.post', 'record' => $record],
            ]);
            if ($resp->getStatusCode() >= 400) {
                return $this->mapError($resp->getStatusCode(), $resp->toArray(false), 'create record');
            }
            $created = $resp->toArray(false);
            $uri = (string) ($created['uri'] ?? '');
            if ($uri === '') {
                return SocialPublishResult::failed('Bluesky response had no record uri.');
            }

            return SocialPublishResult::published($uri, $this->permalink($handle, $uri));
        } catch (TransportExceptionInterface $e) {
            return SocialPublishResult::retry('Bluesky unreachable: ' . $e->getMessage());
        }
    }

    /** at://did/app.bsky.feed.post/{rkey} → https://bsky.app/profile/{handle}/post/{rkey} */
    private function permalink(string $handle, string $uri): ?string
    {
        $rkey = substr((string) strrchr($uri, '/'), 1);
        if ($rkey === '' || $handle === '') {
            return null;
        }
        return sprintf('https://bsky.app/profile/%s/post/%s', $handle, $rkey);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function mapError(int $status, array $body, string $stage): SocialPublishResult
    {
        $detail = (string) ($body['message'] ?? $body['error'] ?? 'unknown error');
        $message = sprintf('Bluesky %s failed (%d): %s', $stage, $status, $detail);
        return ($status >= 500 || $status === 429)
            ? SocialPublishResult::retry($message)
            : SocialPublishResult::failed($message);
    }
}
