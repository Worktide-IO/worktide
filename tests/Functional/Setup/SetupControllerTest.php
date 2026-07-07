<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional coverage for the first-run setup wizard endpoints.
 *
 * Runs inside a rolled-back transaction (the test DB has no committed users at
 * rest), so `needsSetup` is genuinely true at the start — exercising the real
 * empty-instance path end-to-end, then the self-lock once a user exists.
 */
final class SetupControllerTest extends WebTestCase
{
    private const HOST = 'api.worktide.ddev.site';

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

    public function testStatusHealthAndInitBootstrapFirstAdmin(): void
    {
        // Fresh instance → setup required, health is served, DB reachable.
        $this->request('GET', '/v1/setup/status');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->json()['needsSetup']);

        $this->request('GET', '/v1/setup/health');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->json()['database']['ok']);

        // Bootstrap the first admin + workspace.
        $this->request('POST', '/v1/setup/init', [
            'email' => 'founder@example.test',
            'password' => 'sup3rsecret',
            'workspaceName' => 'Acme Corp',
            'firstName' => 'Fred',
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertNotEmpty($body['token']);
        self::assertNotEmpty($body['refresh_token']);
        self::assertNotEmpty($body['workspaceId']);

        // The graph is correct: user, workspace (slugified), owner membership.
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'founder@example.test']);
        self::assertInstanceOf(User::class, $user);
        $ws = $this->em->getRepository(Workspace::class)->findOneBy(['slug' => 'acme-corp']);
        self::assertInstanceOf(Workspace::class, $ws);
        self::assertSame('Acme Corp', $ws->getName());
        $member = $this->em->getRepository(WorkspaceMember::class)->findOneBy(['workspace' => $ws, 'user' => $user]);
        self::assertInstanceOf(WorkspaceMember::class, $member);
        self::assertSame(WorkspaceMemberRole::Owner, $member->getRole());

        // Now self-locked: status flips, health + init refuse with 409.
        $this->request('GET', '/v1/setup/status');
        self::assertFalse($this->json()['needsSetup']);

        $this->request('GET', '/v1/setup/health');
        self::assertSame(409, $this->client->getResponse()->getStatusCode());

        $this->request('POST', '/v1/setup/init', [
            'email' => 'second@example.test',
            'password' => 'anotherlongone',
            'workspaceName' => 'Second',
        ]);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('already_initialized', $this->json()['error']);
    }

    public function testInitValidatesInput(): void
    {
        $this->request('POST', '/v1/setup/init', [
            'email' => 'not-an-email',
            'password' => 'short',
            'workspaceName' => '',
        ]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $fields = $this->json()['fields'];
        self::assertArrayHasKey('email', $fields);
        self::assertArrayHasKey('password', $fields);
        self::assertArrayHasKey('workspaceName', $fields);
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, ?array $body = null): void
    {
        $server = ['HTTP_HOST' => self::HOST, 'CONTENT_TYPE' => 'application/json'];
        $content = $body !== null ? json_encode($body, \JSON_THROW_ON_ERROR) : null;
        $this->client->request($method, $uri, [], [], $server, $content);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
    }
}
