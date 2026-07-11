<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Staff chat-webhook self-service: set/report/remove a webhook, reject unsafe
 * URLs, never echo the URL, and store it encrypted at rest.
 */
final class ChatWebhookTest extends WebTestCase
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

    public function testConfigureReportRemove(): void
    {
        $user = $this->owner();
        $token = $this->token($user);

        // Nothing configured yet.
        $this->request('GET', '/v1/me/chat-webhook', $token);
        self::assertFalse($this->json()['configured']);

        // Configure a Slack webhook (IP literal → no DNS needed; public IP).
        $url = 'https://1.1.1.1/services/T000/B000/secret';
        $this->request('PUT', '/v1/me/chat-webhook', $token, ['provider' => 'slack', 'url' => $url, 'enabled' => true]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $status = $this->json();
        self::assertTrue($status['configured']);
        self::assertSame('slack', $status['provider']);
        self::assertArrayNotHasKey('url', $status, 'the URL is never echoed back');

        // Encrypted at rest: the raw column value is NOT the plaintext URL.
        $stored = (string) $this->em->getConnection()
            ->fetchOne('SELECT url FROM user_chat_webhooks WHERE user_id = UNHEX(?)', [str_replace('-', '', $user->getId()?->toRfc4122() ?? '')]);
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString('1.1.1.1', $stored, 'URL must be encrypted at rest');

        // Remove it.
        $this->request('DELETE', '/v1/me/chat-webhook', $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/v1/me/chat-webhook', $token);
        self::assertFalse($this->json()['configured']);
    }

    public function testUnsafeUrlRejected(): void
    {
        $token = $this->token($this->owner());
        $this->request('PUT', '/v1/me/chat-webhook', $token, ['provider' => 'slack', 'url' => 'http://127.0.0.1/hook']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownProviderRejected(): void
    {
        $token = $this->token($this->owner());
        $this->request('PUT', '/v1/me/chat-webhook', $token, ['provider' => 'irc', 'url' => 'https://1.1.1.1/x']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    private function owner(): User
    {
        $ws = (new Workspace())->setName('WS')->setSlug('ws-' . substr(Uuid::v7()->toRfc4122(), -12))
            ->setLocale('de')->setTimezone('Europe/Berlin')->setSettings([]);
        $this->em->persist($ws);
        $user = (new User())->setEmail('chat-' . substr(Uuid::v7()->toRfc4122(), -12) . '@example.test')
            ->setFirstName('O')->setLastName('W')->setRoles([]);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->persist((new WorkspaceMember())->setWorkspace($ws)->setUser($user)->setRole(WorkspaceMemberRole::Owner));
        $this->em->flush();

        return $user;
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, string $token, ?array $body = null): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], $body !== null ? json_encode($body, \JSON_THROW_ON_ERROR) : null);
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
