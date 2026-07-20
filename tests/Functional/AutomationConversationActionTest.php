<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Enum\ConversationStatus;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The n8n "action back into Worktide" endpoint: token-authed, applies
 * status/tags/note to a conversation. AUTOMATION_API_TOKEN is set in .env.test.
 */
final class AutomationConversationActionTest extends WebTestCase
{
    private const TOKEN = 'test-automation-token';

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

    public function testAppliesStatusTagsAndNote(): void
    {
        $conversation = $this->conversation();
        $id = $conversation->getId()?->toRfc4122();

        $this->apply($id, self::TOKEN, [
            'status' => 'closed',
            'tags' => ['billing', 'n8n-auto'],
            'note' => 'Auto-triaged by n8n.',
            'pinNote' => true,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $json = $this->json();
        self::assertSame('closed', $json['status']);
        self::assertEqualsCanonicalizing(['billing', 'n8n-auto'], $json['tags']);
        self::assertEqualsCanonicalizing(['status', 'tags', 'note'], $json['applied']);

        // Entity actually mutated (same EM/transaction as the request).
        self::assertSame(ConversationStatus::Closed, $conversation->getStatus());
        self::assertCount(2, $conversation->getTags());
    }

    public function testTagsAreIdempotent(): void
    {
        $id = $this->conversation()->getId()?->toRfc4122();

        $this->apply($id, self::TOKEN, ['tags' => ['dupe']]);
        $this->apply($id, self::TOKEN, ['tags' => ['dupe']]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $this->json()['tags']);
    }

    public function testInvalidStatusRejected(): void
    {
        $id = $this->conversation()->getId()?->toRfc4122();
        $this->apply($id, self::TOKEN, ['status' => 'bogus']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownConversationIs404(): void
    {
        $this->apply(Uuid::v7()->toRfc4122(), self::TOKEN, ['status' => 'open']);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testWrongTokenRejected(): void
    {
        $id = $this->conversation()->getId()?->toRfc4122();
        $this->apply($id, 'nope', ['status' => 'open']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testMissingTokenRejected(): void
    {
        $id = $this->conversation()->getId()?->toRfc4122();
        $this->client->request('POST', "/v1/automation/conversations/$id/apply", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['status' => 'open'], \JSON_THROW_ON_ERROR));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    private function conversation(): Conversation
    {
        $ws = (new Workspace())->setName('WS')->setSlug('ws-' . substr(Uuid::v7()->toRfc4122(), -12))
            ->setLocale('de')->setTimezone('Europe/Berlin')->setSettings([]);
        $this->em->persist($ws);

        $channel = (new Channel())->setName('Zabbix')->setAdapterCode('zabbix');
        $channel->setWorkspace($ws);
        $this->em->persist($channel);

        $conversation = (new Conversation())
            ->setChannel($channel)
            ->setThreadKey('zabbix:test:' . substr(Uuid::v7()->toRfc4122(), -12))
            ->setSubject('CPU high')
            ->setStatus(ConversationStatus::Open);
        $conversation->setWorkspace($ws);
        $this->em->persist($conversation);
        $this->em->flush();

        return $conversation;
    }

    /** @param array<string, mixed> $body */
    private function apply(?string $id, string $token, array $body): void
    {
        $this->client->request('POST', "/v1/automation/conversations/$id/apply", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WORKTIDE_AUTOMATION_TOKEN' => $token,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
    }
}
