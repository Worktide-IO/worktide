<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Task;
use App\Entity\TaskAssignee;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * GET /v1/dashboard/my-tasks (web M3 read-model). Contract:
 *  - only the CALLER's directly-assigned tasks come back (another assignee's
 *    task never leaks), and closed out-of-window tasks are excluded;
 *  - the response is workspace-scoped and the client-supplied X-Workspace-Id is
 *    membership-checked (no cross-tenant read);
 *  - unauthenticated → 401.
 *
 * Same one-kernel / rolled-back-transaction isolation as {@see TenantScopingTest}.
 */
final class DashboardMyTasksTest extends WebTestCase
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

    public function testReturnsOnlyCallersOpenOrDueTasks(): void
    {
        $wsA = $this->workspace('dash-a');
        $alice = $this->user('alice.dash@example.test');
        $bob = $this->user('bob.dash@example.test');
        $this->member($alice, $wsA);
        $this->member($bob, $wsA);

        $open = $this->taskStatus($wsA, 'Todo', false);
        $done = $this->taskStatus($wsA, 'Done', true);

        $mine = $this->task($wsA, 'DASH-1', 'Mine, open, due today', $open, $alice, new \DateTimeImmutable('now'));
        $bobs = $this->task($wsA, 'DASH-2', 'Bob\'s task', $open, $bob, new \DateTimeImmutable('now'));
        $mineClosedOld = $this->task($wsA, 'DASH-3', 'Mine, closed, long past', $done, $alice, new \DateTimeImmutable('-40 days'));
        $this->em->flush();

        $this->get('/v1/dashboard/my-tasks', $this->token($alice));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $ids = $this->taskIds();
        self::assertContains($mine->getId()?->toRfc4122(), $ids, 'own open task must be listed');
        self::assertNotContains($bobs->getId()?->toRfc4122(), $ids, 'another assignee\'s task must not leak');
        self::assertNotContains($mineClosedOld->getId()?->toRfc4122(), $ids, 'closed out-of-window task excluded');

        // Shape: project inlined (null here) + isOpen present.
        $row = $this->firstTask();
        self::assertArrayHasKey('isOpen', $row);
        self::assertTrue($row['isOpen']);
        self::assertNull($row['project']);
        self::assertSame('DASH-1', $row['identifier']);
    }

    public function testRejectsForeignWorkspaceHeader(): void
    {
        $wsA = $this->workspace('dash-own');
        $wsB = $this->workspace('dash-foreign');
        $alice = $this->user('alice.dash2@example.test');
        $this->member($alice, $wsA); // NOT a member of wsB
        $this->em->flush();

        // Default (own membership) resolves → 200.
        $this->get('/v1/dashboard/my-tasks', $this->token($alice));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // A workspace the caller doesn't belong to → 403, never a cross-tenant read.
        $this->client->request('GET', '/v1/dashboard/my-tasks', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($alice),
            'HTTP_X_WORKSPACE_ID' => $wsB->getId()?->toRfc4122() ?? '',
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $this->client->request('GET', '/v1/dashboard/my-tasks');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    // ---- seeding helpers (mirror TenantScopingTest) ----

    private function workspace(string $slugPrefix): Workspace
    {
        $ws = (new Workspace())
            ->setName('Dash ' . $slugPrefix)
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
        $this->em->persist(
            (new WorkspaceMember())->setUser($user)->setWorkspace($ws)->setRole(WorkspaceMemberRole::Member),
        );
    }

    private function taskStatus(Workspace $ws, string $name, bool $completed): TaskStatus
    {
        $s = (new TaskStatus())->setName($name)->setIsCompleted($completed);
        $s->setWorkspace($ws);
        $this->em->persist($s);

        return $s;
    }

    private function task(Workspace $ws, string $identifier, string $title, TaskStatus $status, User $assignee, \DateTimeImmutable $dueOn): Task
    {
        $t = (new Task())->setIdentifier($identifier)->setTitle($title)->setStatus($status)->setDueOn($dueOn);
        $t->setWorkspace($ws);
        $this->em->persist($t);

        $a = (new TaskAssignee())
            ->setTask($t)
            ->setPrincipalType(AssigneePrincipalType::User)
            ->setPrincipalId($assignee->getId());
        $this->em->persist($a);

        return $t;
    }

    private function get(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
    }

    /** @return list<string> */
    private function taskIds(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        return array_map(static fn (array $t): string => (string) $t['id'], $data['tasks'] ?? []);
    }

    /** @return array<string, mixed> */
    private function firstTask(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        return $data['tasks'][0] ?? [];
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
