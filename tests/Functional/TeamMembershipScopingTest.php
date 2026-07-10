<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * POST /v1/teams/{id}/add-users must only add users who belong to the team's
 * OWN workspace — a cross-workspace user is silently skipped, never added.
 * Team membership expands into task VIEW/EDIT via TaskVoter, so adding an
 * outside user would leak the tenant's tasks.
 *
 * Same isolation pattern as {@see NotificationsInboxTest}: one kernel,
 * everything inside a rolled-back transaction.
 */
final class TeamMembershipScopingTest extends WebTestCase
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

    public function testAddUsersSkipsCrossWorkspaceUser(): void
    {
        $wsA = $this->workspace('team-a');
        $wsB = $this->workspace('team-b');

        $admin = $this->user('admin.team@example.test');   // caller — Admin in A (EDIT)
        $insider = $this->user('insider.team@example.test'); // Member of A
        $outsider = $this->user('outsider.team@example.test'); // Member of B only
        $this->member($admin, $wsA, WorkspaceMemberRole::Admin);
        $this->member($insider, $wsA, WorkspaceMemberRole::Member);
        $this->member($outsider, $wsB, WorkspaceMemberRole::Member);

        $team = (new Team())->setName('Team A');
        $team->setWorkspace($wsA);
        $this->em->persist($team);
        $this->em->flush();
        $teamId = $team->getId()?->toRfc4122() ?? '';

        // Try to add BOTH the insider and the foreign-workspace outsider.
        $this->client->request(
            'POST',
            '/v1/teams/' . $teamId . '/add-users',
            [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($admin), 'CONTENT_TYPE' => 'application/json'],
            json_encode(['userIds' => [
                $insider->getId()?->toRfc4122(),
                $outsider->getId()?->toRfc4122(),
            ]], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $json['added'], 'only the same-workspace user is added');
        self::assertSame(1, $json['skipped'], 'the cross-workspace user is skipped');

        // The DB must reflect it: insider on the team, outsider NOT.
        $this->em->clear();
        $reloaded = $this->em->find(Team::class, Uuid::fromString($teamId));
        self::assertInstanceOf(Team::class, $reloaded);
        $memberIds = [];
        foreach ($reloaded->getMembers() as $m) {
            $memberIds[] = $m->getId()?->toRfc4122();
        }
        self::assertContains($insider->getId()?->toRfc4122(), $memberIds);
        self::assertNotContains($outsider->getId()?->toRfc4122(), $memberIds);
    }

    private function workspace(string $slugPrefix): Workspace
    {
        $ws = (new Workspace())
            ->setName('Team scope ' . $slugPrefix)
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

    private function member(User $user, Workspace $ws, WorkspaceMemberRole $role): void
    {
        $m = (new WorkspaceMember())
            ->setUser($user)
            ->setWorkspace($ws)
            ->setRole($role);
        $this->em->persist($m);
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
