<?php

declare(strict_types=1);

namespace App\Service\Social;

/**
 * A media attachment resolved from a {@see \App\Entity\SocialPost} mediaRef to
 * its concrete bytes, ready to hand to a network adapter.
 */
final class ResolvedMedia
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mimeType,
        public readonly string $filename,
        public readonly ?string $altText = null,
    ) {}
}
