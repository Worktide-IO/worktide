<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\File;
use App\Entity\SocialPost;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Turns a post's mediaRefs ({fileId, altText?}) into concrete bytes by loading
 * the {@see File} row and streaming its current version out of {@see FileStorage}.
 *
 * Adapters call this at publish time so the post row stays a thin pointer and
 * the bytes are only read when actually needed (and never duplicated in JSON).
 */
class SocialMediaResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FileStorage $fileStorage,
    ) {}

    /**
     * @return list<ResolvedMedia> resolvable media in post order; unresolvable
     *                             refs (missing file/version/bytes) are skipped
     */
    public function resolve(SocialPost $post): array
    {
        $out = [];
        foreach ($post->getMediaRefs() as $ref) {
            $fileId = $ref['fileId'] ?? null;
            if (!is_string($fileId) || $fileId === '') {
                continue;
            }
            try {
                $file = $this->em->find(File::class, Uuid::fromString($fileId));
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (!$file instanceof File) {
                continue;
            }
            $version = $file->getCurrentVersion();
            if ($version === null) {
                continue;
            }
            $stream = $this->fileStorage->readStream($version);
            if (!\is_resource($stream)) {
                continue;
            }
            $bytes = stream_get_contents($stream);
            fclose($stream);
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $alt = isset($ref['altText']) && is_string($ref['altText']) ? $ref['altText'] : null;
            $out[] = new ResolvedMedia(
                bytes: $bytes,
                mimeType: $version->getMimeType(),
                filename: $version->getOriginalFilename(),
                altText: $alt,
            );
        }
        return $out;
    }
}
