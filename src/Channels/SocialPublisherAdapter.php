<?php

declare(strict_types=1);

namespace App\Channels;

use App\Entity\Channel;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;

/**
 * Implemented by every concrete social network Worktide can post to — Mastodon,
 * Bluesky, LinkedIn, Facebook Page, Instagram Business, …
 *
 * Like {@see OutboundAdapter}, implementations are stateless transport layers:
 * they take a fully-prepared {@see SocialPostTarget} (whose `effectiveBody()`
 * and the parent post's media are ready) and hand it to their provider, using
 * {@see Channel::$outboundConfig} + {@see Channel::$authConfig} for endpoint
 * and credentials. They MUST NOT mutate the entities — status is written back
 * by {@see \App\Service\Social\SocialPublisher}; adapters only return a
 * {@see SocialPublishResult}.
 *
 * Auto-discovered via the `worktide.channel.social` tag (see config/services.yaml)
 * and indexed by {@see AdapterRegistry} keyed on {@see self::getCode()}, which
 * must equal the Channel's adapterCode (`social_mastodon`, `social_bluesky`, …).
 */
interface SocialPublisherAdapter
{
    public function getCode(): string;

    public function getLabel(): string;

    /** Maximum text length this network accepts (characters). */
    public function maxLength(): int;

    public function mediaConstraints(): SocialMediaConstraints;

    /**
     * Publish the target to the network. Surface permanent errors via
     * {@see SocialPublishResult::failed()} and transient ones via
     * {@see SocialPublishResult::retry()} rather than throwing — the publisher
     * decides whether to re-queue based on the target's attemptCount.
     */
    public function publish(Channel $channel, SocialPost $post, SocialPostTarget $target): SocialPublishResult;
}
