<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to fan one {@see \App\Entity\SocialPost} out to its
 * networks, off the request thread.
 *
 * Dispatched immediately when a post is approved/published now, and by the
 * `app:social:publish-due` command when a scheduled post comes due. The post
 * ROW is the source of truth, so we carry only its id and re-load on handle —
 * a redelivery acts on the current state and the handler only touches Queued
 * targets, making at-least-once delivery safe.
 */
final class PublishSocialPostMessage
{
    public function __construct(
        private readonly Uuid $socialPostId,
    ) {}

    public function getSocialPostId(): Uuid
    {
        return $this->socialPostId;
    }
}
