<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Social;

use App\Channels\SocialMediaConstraints;
use App\Channels\SocialPublishResult;
use App\Channels\SocialPublisherAdapter;
use App\Entity\Channel;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Publishes to a Discourse forum via its REST API — the proof that "forum
 * distribution" is just another connector: it implements the same
 * {@see SocialPublisherAdapter} contract, is auto-registered in
 * {@see \App\Channels\AdapterRegistry}, rides the existing egress-gated
 * SocialPublisher pipeline (module social_publish), and its published permalink
 * IS the distribution record — no bespoke forum entity/handler/controller.
 *
 * Connection (Channel):
 *   outboundConfig.baseUrl      e.g. "https://forum.example.org"
 *   outboundConfig.categoryId   optional Discourse category id
 *   authConfig.apiKey           Discourse API key (encrypted at rest)
 *   authConfig.apiUsername      the posting user
 *
 * Creates a new topic per post (POST /posts.json). Text-only for now.
 */
final class DiscourseForumAdapter implements SocialPublisherAdapter
{
    private const MAX_LENGTH = 32000;
    private const MIN_TITLE = 15;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function getCode(): string
    {
        return 'social_forum_discourse';
    }

    public function getLabel(): string
    {
        return 'Forum (Discourse)';
    }

    public function maxLength(): int
    {
        return self::MAX_LENGTH;
    }

    public function mediaConstraints(): SocialMediaConstraints
    {
        // Text-only proof adapter: no media upload path.
        return new SocialMediaConstraints(maxImages: 0, supportsVideo: false);
    }

    public function publish(Channel $channel, SocialPost $post, SocialPostTarget $target): SocialPublishResult
    {
        $baseUrl = rtrim((string) ($channel->getOutboundConfig()['baseUrl'] ?? ''), '/');
        $apiKey = (string) ($channel->getAuthConfig()['apiKey'] ?? '');
        $apiUser = (string) ($channel->getAuthConfig()['apiUsername'] ?? '');
        if ($baseUrl === '' || $apiKey === '' || $apiUser === '') {
            return SocialPublishResult::failed('Discourse channel missing baseUrl, apiKey or apiUsername.');
        }
        // Never send the API key to an internal/private host (SSRF).
        try {
            \App\Http\OutboundUrlGuard::ensureNotReservedHost($baseUrl);
        } catch (\App\Http\UnsafeUrlException $e) {
            return SocialPublishResult::failed($e->getMessage());
        }

        $raw = $target->effectiveBody();
        $title = $this->titleFrom($raw);
        $params = ['title' => $title, 'raw' => $raw];
        $categoryId = $channel->getOutboundConfig()['categoryId'] ?? null;
        if (is_numeric($categoryId)) {
            $params['category'] = (int) $categoryId;
        }

        try {
            $resp = $this->httpClient->request('POST', $baseUrl . '/posts.json', [
                'headers' => ['Api-Key' => $apiKey, 'Api-Username' => $apiUser],
                'body' => $params,
                'timeout' => 30,
            ]);
            $status = $resp->getStatusCode();
            $body = $resp->toArray(false);
            if ($status >= 400) {
                $detail = \is_array($body['errors'] ?? null) ? implode(' ', $body['errors']) : (string) ($body['error'] ?? 'unknown error');
                $message = sprintf('Discourse post failed (%d): %s', $status, $detail);

                return ($status >= 500 || $status === 429)
                    ? SocialPublishResult::retry($message)
                    : SocialPublishResult::failed($message);
            }

            $id = (string) ($body['id'] ?? '');
            if ($id === '') {
                return SocialPublishResult::failed('Discourse response had no post id.');
            }
            $permalink = null;
            $slug = (string) ($body['topic_slug'] ?? '');
            $topicId = $body['topic_id'] ?? null;
            if ($slug !== '' && is_numeric($topicId)) {
                $permalink = sprintf('%s/t/%s/%d', $baseUrl, $slug, (int) $topicId);
            }

            return SocialPublishResult::published($id, $permalink);
        } catch (TransportExceptionInterface $e) {
            return SocialPublishResult::retry('Discourse unreachable: ' . $e->getMessage());
        }
    }

    /** Discourse needs a topic title (min length); derive one from the body. */
    private function titleFrom(string $raw): string
    {
        $firstLine = trim((string) strtok(trim($raw), "\n"));
        $title = mb_substr($firstLine !== '' ? $firstLine : trim($raw), 0, 120);
        if (mb_strlen($title) < self::MIN_TITLE) {
            $title = mb_substr(trim($raw), 0, 120);
        }

        return $title !== '' ? $title : 'Beitrag';
    }
}
