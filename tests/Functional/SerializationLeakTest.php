<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Channel;
use App\Tests\Support\TenantFixtureTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Phase-T serialization guardrail: secrets must never appear in an API response.
 *
 * Targeted (not a broad reflective scan): the two highest-risk surfaces are
 *  - {@see Channel::$authConfig} — holds IMAP/SMTP passwords, OAuth refresh
 *    tokens, API keys. It is a plain public JSON getter with no `readable:false`
 *    ApiProperty, so a naive serializer WOULD emit the cleartext credential in
 *    GET /v1/channels{,/id}. This test pins that it does not.
 *  - {@see \App\Entity\User::$password} — must never round-trip out.
 *
 * If this test fails on authConfig, that is a genuine finding (a real leak),
 * not a flaky test — fix the resource (ApiProperty readable:false / a
 * normalizer / serialization group), do not weaken the assertion.
 */
final class SerializationLeakTest extends WebTestCase
{
    use TenantFixtureTrait;

    /** A distinctive marker so a substring search can't false-negative. */
    private const SECRET = 'S3CR3T-imap-token-9c1f2a-DO-NOT-LEAK';

    protected function setUp(): void
    {
        $this->bootTenant();
    }

    protected function tearDown(): void
    {
        $this->rollbackTenant();
        parent::tearDown();
    }

    /**
     * Surfaced by this test on first run and since fixed: Channel GET used to
     * serialize `authConfig` (IMAP/SMTP password, OAuth token) in cleartext to
     * any workspace member. Now guarded by `#[ApiProperty(readable: false)]`.
     */
    public function testChannelAuthConfigSecretIsNotInItemResponse(): void
    {
        [$token, $channelId] = $this->seedChannelWithSecret();

        $this->apiGet('/v1/channels/' . $channelId, $token);
        self::assertSame(200, $this->responseStatus());
        self::assertStringNotContainsString(
            self::SECRET,
            $this->rawBody(),
            'Channel GET leaked the authConfig secret — expose authConfig as readable:false or strip it in a normalizer.',
        );
    }

    public function testChannelAuthConfigSecretIsNotInCollectionResponse(): void
    {
        [$token] = $this->seedChannelWithSecret();

        $this->apiGet('/v1/channels', $token);
        self::assertSame(200, $this->responseStatus());
        self::assertStringNotContainsString(
            self::SECRET,
            $this->rawBody(),
            'Channel collection leaked the authConfig secret.',
        );
    }

    public function testUserResponseHasNoPasswordField(): void
    {
        $ws = $this->makeWorkspace('leak-u');
        $alice = $this->makeUser('alice.leak@example.test');
        $this->makeMember($alice, $ws);
        $this->em->flush();
        $token = $this->jwt($alice);
        $id = $alice->getId()?->toRfc4122() ?? '';
        $this->em->clear();

        $this->apiGet('/v1/users/' . $id, $token);
        self::assertSame(200, $this->responseStatus());
        $body = $this->rawBody();
        self::assertStringNotContainsString('"password"', $body, 'User response exposes a password field.');
        self::assertStringNotContainsString('noop', $body, 'User response leaked the (hashed) password value.');
    }

    /**
     * @return array{0: string, 1: string} [token, channelId]
     */
    private function seedChannelWithSecret(): array
    {
        $ws = $this->makeWorkspace('leak-ch');
        $alice = $this->makeUser('alice.chleak@example.test');
        $this->makeMember($alice, $ws);

        $channel = (new Channel())
            ->setWorkspace($ws)
            ->setName('Support-Postfach')
            ->setAdapterCode('email_imap')
            ->setIsShared(true)
            ->setAuthConfig(['password' => self::SECRET]);
        $this->em->persist($channel);
        $this->em->flush();

        $out = [$this->jwt($alice), $channel->getId()?->toRfc4122() ?? ''];
        $this->em->clear();

        return $out;
    }
}
