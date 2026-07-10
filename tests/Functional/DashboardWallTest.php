<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Customer;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\ProjectStatus;
use App\Entity\Task;
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
 * GET /v1/dashboard/wall (web M3, surface 4). Contract:
 *  - lanes = OPEN project statuses only (completed + archived excluded);
 *  - projects = non-archived only, with customer inlined, per-project task
 *    counts (total + open via GROUP BY) and member user-IRIs;
 *  - workspace-scoped; membership-checked X-Workspace-Id; unauth → 401.
 */
final class DashboardWallTest extends WebTestCase
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

    public function testAssemblesLanesProjectsCountsAndMembers(): void
    {
        $ws = $this->workspace('wall');
        $alice = $this->user('alice.wall@example.test');
        $this->member($alice, $ws);

        $laneOpen = $this->projectStatus($ws, 'Aktiv', completed: false, archived: false);
        $laneDone = $this->projectStatus($ws, 'Fertig', completed: true, archived: false);
        $laneArch = $this->projectStatus($ws, 'Alt', completed: false, archived: true);

        $customer = $this->customer($ws, 'Acme');
        $shown = $this->project($ws, 'Shown', 'SH', $laneOpen, $customer, archived: false);
        $archived = $this->project($ws, 'Archived', 'AR', $laneOpen, $customer, archived: true);

        $tOpen = $this->taskStatus($ws, 'Todo', false);
        $tDone = $this->taskStatus($ws, 'Done', true);
        $this->task($ws, 'W-1', 'open', $tOpen, $shown);
        $this->task($ws, 'W-2', 'done', $tDone, $shown);

        $this->em->persist((new ProjectMember())->setProject($shown)->setUser($alice));
        $this->em->flush();

        $this->get('/v1/dashboard/wall', $this->token($alice));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = $this->json();

        $laneIds = array_map(static fn (array $l): string => (string) $l['id'], $data['lanes']);
        self::assertContains($laneOpen->getId()?->toRfc4122(), $laneIds, 'open lane present');
        self::assertNotContains($laneDone->getId()?->toRfc4122(), $laneIds, 'completed status is not a lane');
        self::assertNotContains($laneArch->getId()?->toRfc4122(), $laneIds, 'archived status is not a lane');

        $projById = [];
        foreach ($data['projects'] as $p) {
            $projById[(string) $p['id']] = $p;
        }
        self::assertArrayHasKey($shown->getId()?->toRfc4122(), $projById, 'non-archived project present');
        self::assertArrayNotHasKey($archived->getId()?->toRfc4122(), $projById, 'archived project excluded');

        $row = $projById[$shown->getId()?->toRfc4122()];
        self::assertSame(2, $row['totalTasks']);
        self::assertSame(1, $row['openTasks']);
        self::assertSame('Acme', $row['customer']['name']);
        self::assertSame('/v1/project_statuses/' . $laneOpen->getId()?->toRfc4122(), $row['status']);
        self::assertSame(['/v1/users/' . $alice->getId()?->toRfc4122()], $row['memberIris']);
    }

    public function testRejectsForeignWorkspaceHeader(): void
    {
        $wsA = $this->workspace('wall-own');
        $wsB = $this->workspace('wall-foreign');
        $alice = $this->user('alice.wall2@example.test');
        $this->member($alice, $wsA);
        $this->em->flush();

        $this->get('/v1/dashboard/wall', $this->token($alice));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/v1/dashboard/wall', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($alice),
            'HTTP_X_WORKSPACE_ID' => $wsB->getId()?->toRfc4122() ?? '',
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $this->client->request('GET', '/v1/dashboard/wall');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    // ---- seeding helpers ----

    private function workspace(string $slugPrefix): Workspace
    {
        $ws = (new Workspace())
            ->setName('Wall ' . $slugPrefix)
            ->setSlug($slugPrefix . '-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings([]);
        $this->em->persist($ws);

        return $ws;
    }

    private function user(string $email): User
    {
        $user = (new User())->setEmail($email)->setFirstName('T')->setLastName('User')->setRoles([]);
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

    private function customer(Workspace $ws, string $name): Customer
    {
        $c = (new Customer())->setName($name);
        $c->setWorkspace($ws);
        $this->em->persist($c);

        return $c;
    }

    private function projectStatus(Workspace $ws, string $name, bool $completed, bool $archived): ProjectStatus
    {
        $s = (new ProjectStatus())->setName($name)->setIsCompleted($completed)->setIsArchived($archived);
        $s->setWorkspace($ws);
        $this->em->persist($s);

        return $s;
    }

    private function project(Workspace $ws, string $name, string $key, ProjectStatus $status, Customer $customer, bool $archived): Project
    {
        $p = (new Project())->setName($name)->setKey($key)->setStatus($status)->setCustomer($customer)->setIsArchived($archived);
        $p->setWorkspace($ws);
        $this->em->persist($p);

        return $p;
    }

    private function taskStatus(Workspace $ws, string $name, bool $completed): TaskStatus
    {
        $s = (new TaskStatus())->setName($name)->setIsCompleted($completed);
        $s->setWorkspace($ws);
        $this->em->persist($s);

        return $s;
    }

    private function task(Workspace $ws, string $identifier, string $title, TaskStatus $status, Project $project): Task
    {
        $t = (new Task())->setIdentifier($identifier)->setTitle($title)->setStatus($status)->setProject($project);
        $t->setWorkspace($ws);
        $this->em->persist($t);

        return $t;
    }

    private function get(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
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
