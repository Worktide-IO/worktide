<?php

declare(strict_types=1);

namespace App\Service\LinkPreview;

/**
 * Card-ready preview of an EXTERNAL URL, produced by {@see LinkPreviewResolver}
 * from an oEmbed provider response or OpenGraph meta tags.
 *
 * Distinct from the INTERNAL entity resolution in LinkResolverController
 * (/v1/links/resolve): this describes a third-party page (YouTube, Figma,
 * Confluence, …), fetched through the egress gate + SSRF guard.
 */
final readonly class LinkPreview implements \JsonSerializable
{
    public function __construct(
        public string $url,
        public string $title,
        public ?string $description = null,
        public ?string $thumbnailUrl = null,
        public ?string $provider = null,
        public ?string $faviconUrl = null,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function jsonSerialize(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'description' => $this->description,
            'thumbnailUrl' => $this->thumbnailUrl,
            'provider' => $this->provider,
            'faviconUrl' => $this->faviconUrl,
        ];
    }
}
