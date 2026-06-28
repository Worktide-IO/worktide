<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Social;

use App\Channels\SocialMediaConstraints;
use App\Channels\SocialPublishResult;
use App\Entity\Channel;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Service\Social\SocialMediaResolver;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Publishes to a Facebook Page via the Graph API.
 *
 * Connection (Channel):
 *   outboundConfig.pageId        the Page id
 *   authConfig.pageAccessToken   a long-lived Page access token (encrypted)
 *
 * Text → POST /{pageId}/feed. One photo → POST /{pageId}/photos (multipart
 * `source`). Several photos → upload each unpublished, then /{pageId}/feed with
 * attached_media. Bytes are uploaded directly, so no public URL is required.
 */
final class FacebookPageAdapter extends AbstractMetaGraphAdapter
{
    public function __construct(
        HttpClientInterface $httpClient,
        private readonly SocialMediaResolver $media,
    ) {
        parent::__construct($httpClient);
    }

    public function getCode(): string
    {
        return 'social_facebook';
    }

    public function getLabel(): string
    {
        return 'Facebook Page';
    }

    public function maxLength(): int
    {
        return 63206;
    }

    public function mediaConstraints(): SocialMediaConstraints
    {
        return new SocialMediaConstraints(
            maxImages: 10,
            supportsVideo: false,
            requiresPublicMediaUrl: false,
        );
    }

    public function publish(Channel $channel, SocialPost $post, SocialPostTarget $target): SocialPublishResult
    {
        $pageId = (string) ($channel->getOutboundConfig()['pageId'] ?? '');
        $token = (string) ($channel->getAuthConfig()['pageAccessToken'] ?? $channel->getAuthConfig()['accessToken'] ?? '');
        if ($pageId === '' || $token === '') {
            return SocialPublishResult::failed('Facebook channel missing pageId or pageAccessToken.');
        }
        $base = $this->graphBase($channel);
        $text = $target->effectiveBody();

        try {
            $images = $this->media->resolve($post);

            // Single photo: publish it directly with the caption.
            if (\count($images) === 1) {
                return $this->postSinglePhoto($base, $pageId, $token, $images[0]->bytes, $images[0]->filename, $text);
            }

            // Several photos: upload each unpublished, attach to a feed post.
            $attached = [];
            foreach ($images as $item) {
                $fbid = $this->uploadUnpublishedPhoto($base, $pageId, $token, $item->bytes, $item->filename);
                if ($fbid === null) {
                    return SocialPublishResult::retry('Facebook photo upload failed.');
                }
                $attached[] = $fbid;
            }

            $params = ['message' => $text, 'access_token' => $token];
            foreach ($attached as $i => $fbid) {
                $params["attached_media[$i]"] = json_encode(['media_fbid' => $fbid]) ?: '';
            }
            $resp = $this->httpClient->request('POST', $base . '/' . $pageId . '/feed', ['body' => $params]);
            $status = $resp->getStatusCode();
            $body = $resp->toArray(false);
            if ($status >= 400) {
                return $this->mapError($status, $body, 'feed');
            }
            $id = (string) ($body['id'] ?? '');
            return $id !== ''
                ? SocialPublishResult::published($id, 'https://www.facebook.com/' . $id)
                : SocialPublishResult::failed('Facebook feed response had no id.');
        } catch (TransportExceptionInterface $e) {
            return SocialPublishResult::retry('Facebook unreachable: ' . $e->getMessage());
        }
    }

    private function postSinglePhoto(string $base, string $pageId, string $token, string $bytes, string $filename, string $caption): SocialPublishResult
    {
        $form = new FormDataPart([
            'source' => new DataPart($bytes, $filename),
            'caption' => $caption,
            'access_token' => $token,
        ]);
        $resp = $this->httpClient->request('POST', $base . '/' . $pageId . '/photos', [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToIterable(),
        ]);
        $status = $resp->getStatusCode();
        $body = $resp->toArray(false);
        if ($status >= 400) {
            return $this->mapError($status, $body, 'photo');
        }
        // photos returns post_id (the feed story) when published.
        $id = (string) ($body['post_id'] ?? $body['id'] ?? '');
        return $id !== ''
            ? SocialPublishResult::published($id, 'https://www.facebook.com/' . $id)
            : SocialPublishResult::failed('Facebook photo response had no id.');
    }

    private function uploadUnpublishedPhoto(string $base, string $pageId, string $token, string $bytes, string $filename): ?string
    {
        $form = new FormDataPart([
            'source' => new DataPart($bytes, $filename),
            'published' => 'false',
            'access_token' => $token,
        ]);
        $resp = $this->httpClient->request('POST', $base . '/' . $pageId . '/photos', [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToIterable(),
        ]);
        if ($resp->getStatusCode() >= 400) {
            return null;
        }
        $id = $resp->toArray(false)['id'] ?? null;
        return is_string($id) ? $id : null;
    }
}
