<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\SocialMediaUrlSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Unit coverage for the signed public media URL: round-trip, tamper rejection,
 * and expiry.
 */
final class SocialMediaUrlSignerTest extends TestCase
{
    private function signer(): SocialMediaUrlSigner
    {
        return new SocialMediaUrlSigner('test-app-secret', 'https://api.worktide.test');
    }

    public function testRoundTrip(): void
    {
        $signer = $this->signer();
        $id = Uuid::v7();

        $verified = $signer->verify($signer->sign($id, 600));

        self::assertNotNull($verified);
        self::assertTrue($id->equals($verified));
    }

    public function testPublicUrlShape(): void
    {
        $url = $this->signer()->publicUrl(Uuid::v7());

        self::assertStringStartsWith('https://api.worktide.test/v1/social/media/', $url);
    }

    public function testTamperedTokenRejected(): void
    {
        $signer = $this->signer();
        $token = $signer->sign(Uuid::v7(), 600);

        self::assertNull($signer->verify($token . 'x'));
    }

    public function testExpiredTokenRejected(): void
    {
        $signer = $this->signer();

        self::assertNull($signer->verify($signer->sign(Uuid::v7(), -10)));
    }

    public function testForeignSecretRejected(): void
    {
        $token = (new SocialMediaUrlSigner('secret-a', 'https://x'))->sign(Uuid::v7(), 600);
        $other = new SocialMediaUrlSigner('secret-b', 'https://x');

        self::assertNull($other->verify($token));
    }
}
