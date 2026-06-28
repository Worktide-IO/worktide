<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Channels\AdapterRegistry;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;

/**
 * Validates a post against each target network's rules (text length, media
 * count/type, Instagram's public-URL requirement) before it is handed to an
 * adapter. Used both by the preview endpoint (so the SPA can warn the user)
 * and as a pre-flight check inside {@see SocialPublisher} (so a bad target is
 * failed cleanly instead of bubbling a provider 4xx).
 */
final class SocialPostValidator
{
    public function __construct(
        private readonly AdapterRegistry $registry,
    ) {}

    /**
     * @return list<string> human-readable problems; empty list = valid
     */
    public function validateTarget(SocialPostTarget $target): array
    {
        $errors = [];
        $code = $target->getChannel()->getAdapterCode();
        $adapter = $this->registry->trySocial($code);
        if ($adapter === null) {
            return [sprintf('No social publisher is registered for "%s".', $code)];
        }

        $text = trim($target->effectiveBody());
        $mediaCount = \count($target->getSocialPost()->getMediaRefs());
        if ($text === '' && $mediaCount === 0) {
            $errors[] = 'Post is empty (no text and no media).';
        }

        $len = mb_strlen($text);
        if ($len > $adapter->maxLength()) {
            $errors[] = sprintf(
                'Text is %d characters; %s allows %d.',
                $len,
                $adapter->getLabel(),
                $adapter->maxLength(),
            );
        }

        $constraints = $adapter->mediaConstraints();
        if ($mediaCount > $constraints->maxImages) {
            $errors[] = sprintf(
                '%d media attached; %s allows %d.',
                $mediaCount,
                $adapter->getLabel(),
                $constraints->maxImages,
            );
        }
        foreach ($target->getSocialPost()->getMediaRefs() as $ref) {
            $mime = isset($ref['mimeType']) && is_string($ref['mimeType']) ? $ref['mimeType'] : null;
            if ($mime !== null && !\in_array($mime, $constraints->allowedMimeTypes, true)) {
                $errors[] = sprintf('%s does not accept media of type "%s".', $adapter->getLabel(), $mime);
            }
        }

        return $errors;
    }

    /**
     * Validate every target of a post.
     *
     * @return array<string, list<string>> keyed by target id (or channel
     *                                      adapterCode when the target is
     *                                      not yet persisted)
     */
    public function validatePost(SocialPost $post): array
    {
        $out = [];
        foreach ($post->getTargets() as $target) {
            $key = $target->getId()?->toRfc4122() ?? $target->getChannel()->getAdapterCode();
            $out[$key] = $this->validateTarget($target);
        }
        return $out;
    }
}
