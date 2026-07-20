<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Enum\ConversationStatus;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\InboundMuteRule;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * One-click "mute this kind of message": creates a rule, mutes the thread, and
 * back-fills existing matches — without deleting anything (still searchable).
 */
final class ConversationMuteSenderTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        if ($conn->isTransactionActive()) {
            $conn->rollBack();
        }
        parent::tearDown();
    }

    public function testMutesSenderAndBackfills(): void
    {
        [$user, $ws, $channel] = $this->fixtures();
        $target = $this->conversation($ws, $channel, 'noreply@hetzner.com', 'Ihr Hetzner Verification Code ist 1');
        $other = $this->conversation($ws, $channel, 'noreply@hetzner.com', 'Ihr Hetzner Verification Code ist 2');
        $keep = $this->conversation($ws, $channel, 'kunde@example.com', 'Frage zum Produkt');
        $this->em->flush();

        $this->request('POST', '/v1/conversations/' . $target->getId()?->toRfc4122() . '/mute-sender', $this->token($user));

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $json = $this->json();
        self::assertSame('sender_email', $json['rule']['matchType']);
        self::assertSame('noreply@hetzner.com', $json['rule']['value']);
        self::assertSame(2, $json['mutedCount']); // target + other, not keep

        $this->em->refresh($target);
        $this->em->refresh($other);
        $this->em->refresh($keep);
        self::assertNotNull($target->getMutedAt());
        self::assertNotNull($other->getMutedAt());
        self::assertNull($keep->getMutedAt(), 'a different sender stays visible');

        // A persisted, enabled rule now exists for the workspace.
        $rules = $this->em->getRepository(InboundMuteRule::class)->findBy(['workspace' => $ws]);
        self::assertCount(1, $rules);
        self::assertTrue($rules[0]->isEnabled());
    }

    public function testSubjectContainsRule(): void
    {
        [$user, $ws, $channel] = $this->fixtures();
        $conv = $this->conversation($ws, $channel, 'x@y.com', 'Weekly newsletter digest');
        $this->em->flush();

        $this->request('POST', '/v1/conversations/' . $conv->getId()?->toRfc4122() . '/mute-sender', $this->token($user), [
            'matchType' => 'subject_contains',
            'value' => 'newsletter',
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->json()['mutedCount']);
        $this->em->refresh($conv);
        self::assertNotNull($conv->getMutedAt());
    }

    public function testRequiresAuth(): void
    {
        [, $ws, $channel] = $this->fixtures();
        $conv = $this->conversation($ws, $channel, 'noreply@hetzner.com', 'x');
        $this->em->flush();

        $this->client->request('POST', '/v1/conversations/' . $conv->getId()?->toRfc4122() . '/mute-sender', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    /** @return array{0: User, 1: Workspace, 2: Channel} */
    private function fixtures(): array
    {
        $ws = (new Workspace())->setName('WS')->setSlug('ws-' . substr(Uuid::v7()->toRfc4122(), -12))
            ->setLocale('de')->setTimezone('Europe/Berlin')->setSettings([]);
        $this->em->persist($ws);
        $user = (new User())->setEmail('mute-' . substr(Uuid::v7()->toRfc4122(), -12) . '@example.test')
            ->setFirstName('O')->setLastName('W')->setRoles([]);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->persist((new WorkspaceMember())->setWorkspace($ws)->setUser($user)->setRole(WorkspaceMemberRole::Owner));
        $channel = (new Channel())->setName('Mail')->setAdapterCode('email_imap');
        $channel->setWorkspace($ws);
        $this->em->persist($channel);
        $this->em->flush();

        return [$user, $ws, $channel];
    }

    private function conversation(Workspace $ws, Channel $channel, string $sender, string $subject): Conversation
    {
        $c = (new Conversation())
            ->setChannel($channel)
            ->setThreadKey('t-' . substr(Uuid::v7()->toRfc4122(), -12))
            ->setSubject($subject)
            ->setSenderRaw($sender)
            ->setStatus(ConversationStatus::Open);
        $c->setWorkspace($ws);
        $this->em->persist($c);

        return $c;
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, string $token, ?array $body = null): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], $body !== null ? json_encode($body, \JSON_THROW_ON_ERROR) : '{}');
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
