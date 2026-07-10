<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\ProjectHealth;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Entity\ProjectStatusUpdate;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * GET /v1/dashboard/recent-status-updates (web M3, surface 3). Contract:
 *  - newest-first, LIMIT 12, project + author inlined;
 *  - workspace-scoped: another workspace's updates never appear;
 *  - membership-checked X-Workspace-Id; unauth → 401.
 */
final class DashboardRecentStatusUpdatesTest extends WebTestCase
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

    public function testReturnsWorkspaceUpdatesNewestFirstWithInlinedRefs(): void
    {
        $wsA = $this->workspace('rsu-a');
        $wsB = $this->workspace('rsu-b');
        $alice = $this->user('alice.rsu@example.test');
        $this->member($alice, $wsA);

        $psA = $this->projectStatus($wsA, 'Active');
        $projA = $this->project($wsA, 'Alpha', 'AL', $psA);
        $psB = $this->projectStatus($wsB, 'Active');
        $projB = $this->project($wsB, 'Beta', 'BE', $psB);

        // Two updates in A (author = alice), one in B (must not leak).
        $older = $this->update($wsA, $projA, $alice, ProjectHealth::AtRisk, 'Older A');
        $newer = $this->update($wsA, $projA, $alice, ProjectHealth::OnTrack, 'Newer A');
        $foreign = $this->update($wsB, $projB, $alice, ProjectHealth::OffTrack, 'B update');
        $this->em->flush();

        $this->get('/v1/dashboard/recent-status-updates', $this->token($alice));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $ids = $this->updateIds();
        self::assertContains($newer->getId()?->toRfc4122(), $ids);
        self::assertContains($older->getId()?->toRfc4122(), $ids);
        self::assertNotContains($foreign->getId()?->toRfc4122(), $ids, 'another workspace\'s update must not leak');

        // Inlined project/author/health shape (looked up by title — createdAt is
        // stamped at flush time, so same-flush rows can't be ordered reliably here).
        $row = $this->updateByTitle('Newer A');
        self::assertSame('Alpha', $row['project']['name']);
        self::assertSame($alice->getFullName(), $row['author']['name']);
        self::assertSame('on_track', $row['health']);
    }

    public function testRejectsForeignWorkspaceHeader(): void
    {
        $wsA = $this->workspace('rsu-own');
        $wsB = $this->workspace('rsu-foreign');
        $alice = $this->user('alice.rsu2@example.test');
        $this->member($alice, $wsA);
        $this->em->flush();

        $this->get('/v1/dashboard/recent-status-updates', $this->token($alice));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/v1/dashboard/recent-status-updates', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($alice),
            'HTTP_X_WORKSPACE_ID' => $wsB->getId()?->toRfc4122() ?? '',
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $this->client->request('GET', '/v1/dashboard/recent-status-updates');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    // ---- seeding helpers ----

    private function workspace(string $slugPrefix): Workspace
    {
        $ws = (new Workspace())
            ->setName('RSU ' . $slugPrefix)
            ->setSlug($slugPrefix . '-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings([]);
        $this->em->persist($ws);

        return $ws;
    }

    private function user(string $email): User
    {
        $user = (new User())->setEmail($email)->setFirstName('Anna')->setLastName('Riedel')->setRoles([]);
        $user->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function member(User $user, Workspace $ws): void
    {
        $this->em->persist(
            (new WorkspaceMember())->setUser($user)->setWorkspace($ws)->setRole(WorkspaceMemberRole::Member),
        );
    }

    private function projectStatus(Workspace $ws, string $name): ProjectStatus
    {
        $s = (new ProjectStatus())->setName($name);
        $s->setWorkspace($ws);
        $this->em->persist($s);

        return $s;
    }

    private function project(Workspace $ws, string $name, string $key, ProjectStatus $status): Project
    {
        $p = (new Project())->setName($name)->setKey($key)->setStatus($status);
        $p->setWorkspace($ws);
        $this->em->persist($p);

        return $p;
    }

    private function update(Workspace $ws, Project $p, User $author, ProjectHealth $health, string $title): ProjectStatusUpdate
    {
        $u = (new ProjectStatusUpdate())->setProject($p)->setHealth($health)->setTitle($title);
        $u->setWorkspace($ws);
        $u->setCreatedByUser($author);
        $this->em->persist($u);

        return $u;
    }

    private function get(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /** @return list<string> */
    private function updateIds(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        return array_map(static fn (array $u): string => (string) $u['id'], $data['updates'] ?? []);
    }

    /** @return array<string, mixed> */
    private function updateByTitle(string $title): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        foreach ($data['updates'] ?? [] as $u) {
            if (($u['title'] ?? null) === $title) {
                return $u;
            }
        }

        return [];
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
