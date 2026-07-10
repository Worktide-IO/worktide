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
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Publishes to a Mastodon instance via its REST API.
 *
 * Connection (Channel):
 *   outboundConfig.instanceUrl  e.g. "https://mastodon.social"
 *   authConfig.accessToken      a user access token (created under the
 *                               account's Preferences → Development; encrypted
 *                               at rest by {@see \App\EventSubscriber\ChannelAuthConfigCipherListener})
 *
 * S1 uses a pasted access token (no app review, works on any instance). Full
 * per-instance OAuth (register app via /api/v1/apps + authorize) can be layered
 * on later behind {@see \App\Channels\OAuth\OAuth2ProviderRegistry} without
 * touching this publish path.
 *
 * Media: POST /api/v2/media → media id, then attach via media_ids on the status.
 */
final class MastodonAdapter implements SocialPublisherAdapter
{
    private const MAX_LENGTH = 500;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SocialMediaResolver $media,
    ) {}

    public function getCode(): string
    {
        return 'social_mastodon';
    }

    public function getLabel(): string
    {
        return 'Mastodon';
    }

    public function maxLength(): int
    {
        return self::MAX_LENGTH;
    }

    public function mediaConstraints(): SocialMediaConstraints
    {
        return new SocialMediaConstraints(
            maxImages: 4,
            supportsVideo: true,
            requiresPublicMediaUrl: false,
        );
    }

    public function publish(Channel $channel, SocialPost $post, SocialPostTarget $target): SocialPublishResult
    {
        $instance = rtrim((string) ($channel->getOutboundConfig()['instanceUrl'] ?? ''), '/');
        $token = (string) ($channel->getAuthConfig()['accessToken'] ?? '');
        if ($instance === '' || $token === '') {
            return SocialPublishResult::failed('Mastodon channel missing instanceUrl or accessToken.');
        }
        // Never send the access token to an internal/private host (SSRF).
        try {
            \App\Http\OutboundUrlGuard::ensureNotReservedHost($instance);
        } catch (\App\Http\UnsafeUrlException $e) {
            return SocialPublishResult::failed($e->getMessage());
        }
        $auth = ['Authorization' => 'Bearer ' . $token];

        try {
            $mediaIds = [];
            foreach ($this->media->resolve($post) as $item) {
                $fields = ['file' => new DataPart($item->bytes, $item->filename, $item->mimeType)];
                if ($item->altText !== null && $item->altText !== '') {
                    $fields['description'] = $item->altText;
                }
                $form = new FormDataPart($fields);
                $resp = $this->httpClient->request('POST', $instance . '/api/v2/media', [
                    'headers' => array_merge($form->getPreparedHeaders()->toArray(), $auth),
                    'body' => $form->bodyToIterable(),
                ]);
                $status = $resp->getStatusCode();
                $body = $resp->toArray(false);
                if ($status >= 400) {
                    return $this->mapError($status, $body, 'media upload');
                }
                $mediaIds[] = (string) ($body['id'] ?? '');
            }

            $params = ['status' => $target->effectiveBody()];
            if ($mediaIds !== []) {
                $params['media_ids'] = array_values(array_filter($mediaIds));
            }
            $resp = $this->httpClient->request('POST', $instance . '/api/v1/statuses', [
                'headers' => $auth,
                'body' => $params,
            ]);
            $status = $resp->getStatusCode();
            $body = $resp->toArray(false);
            if ($status >= 400) {
                return $this->mapError($status, $body, 'status post');
            }

            $id = (string) ($body['id'] ?? '');
            if ($id === '') {
                return SocialPublishResult::failed('Mastodon response had no status id.');
            }
            return SocialPublishResult::published($id, isset($body['url']) ? (string) $body['url'] : null);
        } catch (TransportExceptionInterface $e) {
            return SocialPublishResult::retry('Mastodon unreachable: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function mapError(int $status, array $body, string $stage): SocialPublishResult
    {
        $detail = (string) ($body['error'] ?? 'unknown error');
        $message = sprintf('Mastodon %s failed (%d): %s', $stage, $status, $detail);
        // 5xx and 429 are transient; other 4xx are permanent (auth, content).
        return ($status >= 500 || $status === 429)
            ? SocialPublishResult::retry($message)
            : SocialPublishResult::failed($message);
    }
}
