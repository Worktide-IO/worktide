<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\DomainEventLog;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\UserCapacity;
use App\Entity\UserContactInfo;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Negative-authorization coverage for the resources hardened against the
 * "unscoped API Platform resource" class of finding:
 *
 *  - UserContactInfo / UserCapacity: per-user rows, self-only (a user can
 *    neither list nor read another user's PII/capacity).
 *  - DomainEventLog: read-only audit log scoped to the caller's workspaces
 *    (another tenant's events never appear and 404 on direct access).
 *
 * (ProjectMember scoping — via .project.workspace — is exercised live; it is
 * omitted here only because seeding a Project drags in the ProjectStatus
 * entity chain.)
 *
 * Same isolation pattern as {@see NotificationsInboxTest}: one kernel,
 * everything inside a rolled-back transaction.
 */
final class TenantScopingTest extends WebTestCase
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

    public function testUserContactInfoIsSelfOnly(): void
    {
        $ctx = $this->seed();
        $token = $this->token($ctx['alice']);

        // Collection: only Alice's own row; Bob's never leaks.
        $this->get('/v1/user_contact_infos', $token);
        self::assertSame(200, $this->statusCode());
        $ids = $this->memberIds();
        self::assertContains($ctx['ciAlice'], $ids);
        self::assertNotContains($ctx['ciBob'], $ids);

        // Item: another user's contact info is scoped away (404, no existence
        // disclosure). The self-only security expression is the second guard.
        $this->get('/v1/user_contact_infos/' . $ctx['ciBob'], $token);
        self::assertSame(404, $this->statusCode());

        // Own row is readable.
        $this->get('/v1/user_contact_infos/' . $ctx['ciAlice'], $token);
        self::assertSame(200, $this->statusCode());
    }

    public function testUserCapacityIsSelfOnly(): void
    {
        $ctx = $this->seed();
        $token = $this->token($ctx['alice']);

        $this->get('/v1/user_capacities', $token);
        self::assertSame(200, $this->statusCode());
        $ids = $this->memberIds();
        self::assertContains($ctx['capAlice'], $ids);
        self::assertNotContains($ctx['capBob'], $ids);

        $this->get('/v1/user_capacities/' . $ctx['capBob'], $token);
        self::assertSame(404, $this->statusCode());
    }

    public function testDomainEventsScopedToCallerWorkspaces(): void
    {
        $ctx = $this->seed();
        $token = $this->token($ctx['alice']);

        // Collection: only workspace-A events; workspace-B's are filtered out.
        $this->get('/v1/domain_events', $token);
        self::assertSame(200, $this->statusCode());
        $ids = $this->memberIds();
        self::assertContains($ctx['evA'], $ids);
        self::assertNotContains($ctx['evB'], $ids);

        // Item: a foreign-workspace event is scoped away → 404, not 200.
        $this->get('/v1/domain_events/' . $ctx['evB'], $token);
        self::assertSame(404, $this->statusCode());
    }

    /** @return array<string, mixed> */
    private function seed(): array
    {
        $wsA = $this->workspace('scope-a');
        $wsB = $this->workspace('scope-b');

        $alice = $this->user('alice.scope@example.test');
        $bob = $this->user('bob.scope@example.test');
        $this->member($alice, $wsA);
        $this->member($bob, $wsB);

        $ciAlice = (new UserContactInfo())->setUser($alice)->setType('phone')->setValue('+49 111');
        $ciBob = (new UserContactInfo())->setUser($bob)->setType('phone')->setValue('+49 222');
        $capAlice = (new UserCapacity())->setUser($alice);
        $capBob = (new UserCapacity())->setUser($bob);
        foreach ([$ciAlice, $ciBob, $capAlice, $capBob] as $e) {
            $this->em->persist($e);
        }

        $evA = new DomainEventLog('task.created', 'Task', null, $wsA, $alice, ['x' => 1]);
        $evB = new DomainEventLog('task.created', 'Task', null, $wsB, $bob, ['x' => 2]);
        $this->em->persist($evA);
        $this->em->persist($evB);

        $this->em->flush();

        return [
            'alice' => $alice,
            'ciAlice' => $ciAlice->getId()?->toRfc4122() ?? '',
            'ciBob' => $ciBob->getId()?->toRfc4122() ?? '',
            'capAlice' => $capAlice->getId()?->toRfc4122() ?? '',
            'capBob' => $capBob->getId()?->toRfc4122() ?? '',
            'evA' => $evA->getId()?->toRfc4122() ?? '',
            'evB' => $evB->getId()?->toRfc4122() ?? '',
        ];
    }

    private function workspace(string $slugPrefix): Workspace
    {
        $ws = (new Workspace())
            ->setName('Scope ' . $slugPrefix)
            ->setSlug($slugPrefix . '-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings([]);
        $this->em->persist($ws);

        return $ws;
    }

    private function user(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('T')
            ->setLastName('User')
            ->setRoles([]);
        $user->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function member(User $user, Workspace $ws): void
    {
        $m = (new WorkspaceMember())
            ->setUser($user)
            ->setWorkspace($ws)
            ->setRole(WorkspaceMemberRole::Member);
        $this->em->persist($m);
    }

    private function get(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
    }

    private function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** @return list<string> the RFC-4122 id of each collection member */
    private function memberIds(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        $members = $data['hydra:member'] ?? $data['member'] ?? [];
        $ids = [];
        foreach ($members as $m) {
            $iri = $m['@id'] ?? '';
            if ($iri !== '') {
                $ids[] = substr((string) $iri, (int) strrpos((string) $iri, '/') + 1);
            }
        }

        return $ids;
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
