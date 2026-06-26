<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MentionExtractor;
use PHPUnit\Framework\TestCase;

final class MentionExtractorTest extends TestCase
{
    private MentionExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new MentionExtractor();
    }

    public function testFindsDistinctUserIris(): void
    {
        $a = '/v1/users/0192a1b2-c3d4-7890-abcd-ef0123456789';
        $b = '/v1/users/0192ffff-1111-7222-8333-444455556666';
        $body = "hey $a and $b — also $a again";

        self::assertSame([$a, $b], $this->extractor->iris($body));
    }

    public function testEmptyBodyYieldsNothing(): void
    {
        self::assertSame([], $this->extractor->iris(''));
    }

    public function testBodyWithoutMentionsYieldsNothing(): void
    {
        self::assertSame([], $this->extractor->iris('just a plain note, /v1/projects/123 not a user'));
    }
}
