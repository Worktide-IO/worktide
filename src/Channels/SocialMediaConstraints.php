<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * What a network accepts for attached media. Used by
 * {@see \App\Service\Social\SocialPostValidator} to reject a post before it is
 * handed to the adapter, and by the SPA to guide composition.
 *
 * `requiresPublicMediaUrl` is the Instagram quirk: the Graph API pulls the
 * image from a public URL rather than accepting an upload, so the media must be
 * reachable without auth at publish time.
 */
final class SocialMediaConstraints
{
    /**
     * @param list<string> $allowedMimeTypes
     */
    public function __construct(
        public readonly int $maxImages = 4,
        public readonly bool $supportsVideo = false,
        public readonly bool $requiresPublicMediaUrl = false,
        public readonly array $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    ) {}
}
