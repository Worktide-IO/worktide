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
 * Newsletter templates: an admin creates a reusable named template; it lists for
 * their workspace and stays invisible to other tenants (WorkspaceScopeExtension).
 */
final class NewsletterTemplateTest extends WebTestCase
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

    public function testCreateAndTenantScoping(): void
    {
        $wsA = $this->workspace();
        $wsB = $this->workspace();
        $ownerA = $this->owner($wsA, 'nt.a@example.test');
        $ownerB = $this->owner($wsB, 'nt.b@example.test');
        $this->em->flush();

        // A creates a template.
        $this->request('POST', '/v1/newsletter_templates', $this->token($ownerA), [
            'name' => 'Monats-Update',
            'subject' => 'Neuigkeiten im {{ company }}',
            'body' => '# Hallo {{ firstName }}',
            'workspace' => '/v1/workspaces/' . $wsA->getId()?->toRfc4122(),
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame('Monats-Update', $this->json()['name']);

        // A sees it; B does not (tenant isolation).
        $this->request('GET', '/v1/newsletter_templates', $this->token($ownerA));
        self::assertCount(1, $this->members());

        $this->request('GET', '/v1/newsletter_templates', $this->token($ownerB));
        self::assertCount(0, $this->members());
    }

    private function workspace(): Workspace
    {
        $ws = (new Workspace())->setName('WS')->setSlug('ws-' . substr(Uuid::v7()->toRfc4122(), -12))
            ->setLocale('de')->setTimezone('Europe/Berlin')->setSettings([]);
        $this->em->persist($ws);

        return $ws;
    }

    private function owner(Workspace $ws, string $email): User
    {
        $user = (new User())->setEmail($email)->setFirstName('O')->setLastName('W')->setRoles([]);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->persist((new WorkspaceMember())->setWorkspace($ws)->setUser($user)->setRole(WorkspaceMemberRole::Owner));

        return $user;
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, string $token, ?array $body = null): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ], $body !== null ? json_encode($body, \JSON_THROW_ON_ERROR) : null);
    }

    /** @return list<array<string, mixed>> */
    private function members(): array
    {
        $data = $this->json();

        return $data['member'] ?? $data['hydra:member'] ?? [];
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
