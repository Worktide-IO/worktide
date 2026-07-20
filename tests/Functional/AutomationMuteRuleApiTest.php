<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\InboundMuteRule;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Token-authed mute-rule CRUD used by n8n (no staff JWT). AUTOMATION_API_TOKEN
 * is set in .env.test. Worktide stays the single source of truth for the rules.
 */
final class AutomationMuteRuleApiTest extends WebTestCase
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

    public function testCreateListPatchDelete(): void
    {
        $ws = $this->workspace();
        $wsId = $ws->getId()?->toRfc4122();

        // create
        $this->send('POST', '/v1/automation/mute-rules', self::TOKEN, [
            'workspaceId' => $wsId,
            'matchType' => 'sender_email',
            'value' => 'noreply@hetzner.com',
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $ruleId = $this->json()['id'];
        self::assertSame('noreply@hetzner.com', $this->json()['value']);
        self::assertTrue($this->json()['isEnabled']);

        // list
        $this->send('GET', '/v1/automation/mute-rules?workspaceId=' . $wsId, self::TOKEN);
        self::assertCount(1, $this->json()['rules']);

        // patch (disable)
        $this->send('PATCH', '/v1/automation/mute-rules/' . $ruleId, self::TOKEN, ['isEnabled' => false]);
        self::assertFalse($this->json()['isEnabled']);

        // delete
        $this->send('DELETE', '/v1/automation/mute-rules/' . $ruleId, self::TOKEN);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->em->getRepository(InboundMuteRule::class)->findBy(['workspace' => $ws]));
    }

    public function testWrongTokenRejected(): void
    {
        $this->send('GET', '/v1/automation/mute-rules?workspaceId=' . Uuid::v7()->toRfc4122(), 'nope');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownWorkspaceIs404(): void
    {
        $this->send('POST', '/v1/automation/mute-rules', self::TOKEN, [
            'workspaceId' => Uuid::v7()->toRfc4122(),
            'value' => 'x@y.com',
        ]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function workspace(): Workspace
    {
        $ws = (new Workspace())->setName('WS')->setSlug('ws-' . substr(Uuid::v7()->toRfc4122(), -12))
            ->setLocale('de')->setTimezone('Europe/Berlin')->setSettings([]);
        $this->em->persist($ws);
        $this->em->flush();

        return $ws;
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $uri, string $token, ?array $body = null): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WORKTIDE_AUTOMATION_TOKEN' => $token,
        ], $body !== null ? json_encode($body, \JSON_THROW_ON_ERROR) : null);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
    }
}
