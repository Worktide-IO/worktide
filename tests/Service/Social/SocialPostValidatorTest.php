<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Channels\AdapterRegistry;
use App\Channels\SocialMediaConstraints;
use App\Channels\SocialPublishResult;
use App\Channels\SocialPublisherAdapter;
use App\Entity\Channel;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Service\Social\SocialPostValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for per-network validation (text length, emptiness, media
 * count/type, unknown adapter).
 */
final class SocialPostValidatorTest extends TestCase
{
    public function testValidTargetHasNoProblems(): void
    {
        $validator = $this->validator($this->adapter('social_x', maxLength: 50));
        $target = $this->target('social_x', 'short enough');

        self::assertSame([], $validator->validateTarget($target));
    }

    public function testTextOverLimitIsReported(): void
    {
        $validator = $this->validator($this->adapter('social_x', maxLength: 10));
        $target = $this->target('social_x', str_repeat('a', 25));

        $problems = $validator->validateTarget($target);
        self::assertNotEmpty($problems);
        self::assertStringContainsString('25 characters', $problems[0]);
    }

    public function testEmptyPostIsReported(): void
    {
        $validator = $this->validator($this->adapter('social_x', maxLength: 50));
        $target = $this->target('social_x', '   ');

        self::assertNotEmpty($validator->validateTarget($target));
    }

    public function testTooManyImagesReported(): void
    {
        $validator = $this->validator($this->adapter('social_x', maxLength: 500, maxImages: 1));
        $target = $this->target('social_x', 'hi', mediaRefs: [
            ['fileId' => 'a', 'mimeType' => 'image/png'],
            ['fileId' => 'b', 'mimeType' => 'image/png'],
        ]);

        self::assertNotEmpty($validator->validateTarget($target));
    }

    public function testUnknownAdapterReported(): void
    {
        $validator = $this->validator(); // no adapters registered
        $target = $this->target('social_missing', 'hi');

        $problems = $validator->validateTarget($target);
        self::assertNotEmpty($problems);
        self::assertStringContainsString('social_missing', $problems[0]);
    }

    // --- helpers ----------------------------------------------------

    private function validator(SocialPublisherAdapter ...$adapters): SocialPostValidator
    {
        return new SocialPostValidator(new AdapterRegistry([], [], [], [], $adapters));
    }

    /** @param list<array<string, mixed>> $mediaRefs */
    private function target(string $code, string $body, array $mediaRefs = []): SocialPostTarget
    {
        $post = (new SocialPost())->setBody($body)->setMediaRefs($mediaRefs);
        $channel = (new Channel())->setName($code)->setAdapterCode($code);
        $target = (new SocialPostTarget())->setChannel($channel);
        $post->addTarget($target);
        return $target;
    }

    private function adapter(string $code, int $maxLength, int $maxImages = 4): SocialPublisherAdapter
    {
        return new class($code, $maxLength, $maxImages) implements SocialPublisherAdapter {
            public function __construct(private string $code, private int $max, private int $maxImages) {}
            public function getCode(): string { return $this->code; }
            public function getLabel(): string { return $this->code; }
            public function maxLength(): int { return $this->max; }
            public function mediaConstraints(): SocialMediaConstraints { return new SocialMediaConstraints(maxImages: $this->maxImages); }
            public function publish(Channel $c, SocialPost $p, SocialPostTarget $t): SocialPublishResult { return SocialPublishResult::failed('n/a'); }
        };
    }
}
