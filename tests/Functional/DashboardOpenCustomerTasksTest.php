<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Customer;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Project;
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
 * GET /v1/dashboard/open-customer-tasks (web M3, surface 2). Contract:
 *  - only OPEN tasks whose project has a customer come back; a task in a
 *    customer-less project, and a closed task, are both excluded;
 *  - project + customer are inlined;
 *  - workspace-scoped with membership-checked X-Workspace-Id; unauth → 401.
 */
final class DashboardOpenCustomerTasksTest extends WebTestCase
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

    public function testListsOnlyOpenTasksInCustomerProjects(): void
    {
        $ws = $this->workspace('ocust');
        $alice = $this->user('alice.ocust@example.test');
        $this->member($alice, $ws);

        $customer = $this->customer($ws, 'Acme');
        $ps = $this->projectStatus($ws, 'Active');
        $withCustomer = $this->project($ws, 'With customer', 'PC', $ps, $customer);
        $noCustomer = $this->project($ws, 'Internal', 'PI', $ps, null);

        $open = $this->taskStatus($ws, 'Todo', false);
        $done = $this->taskStatus($ws, 'Done', true);

        $listed = $this->task($ws, 'OC-1', 'Open, has customer', $open, $withCustomer);
        $noCust = $this->task($ws, 'OC-2', 'Open, no customer', $open, $noCustomer);
        $closed = $this->task($ws, 'OC-3', 'Closed, has customer', $done, $withCustomer);
        $this->em->flush();

        $this->get('/v1/dashboard/open-customer-tasks', $this->token($alice));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $ids = $this->taskIds();
        self::assertContains($listed->getId()?->toRfc4122(), $ids, 'open task in a customer project must be listed');
        self::assertNotContains($noCust->getId()?->toRfc4122(), $ids, 'customer-less project task excluded');
        self::assertNotContains($closed->getId()?->toRfc4122(), $ids, 'closed task excluded');

        $row = $this->firstTask();
        self::assertSame('OC-1', $row['identifier']);
        self::assertSame('Acme', $row['customer']['name']);
        self::assertSame('With customer', $row['project']['name']);
    }

    public function testRejectsForeignWorkspaceHeader(): void
    {
        $wsA = $this->workspace('ocust-own');
        $wsB = $this->workspace('ocust-foreign');
        $alice = $this->user('alice.ocust2@example.test');
        $this->member($alice, $wsA);
        $this->em->flush();

        $this->get('/v1/dashboard/open-customer-tasks', $this->token($alice));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/v1/dashboard/open-customer-tasks', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($alice),
            'HTTP_X_WORKSPACE_ID' => $wsB->getId()?->toRfc4122() ?? '',
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $this->client->request('GET', '/v1/dashboard/open-customer-tasks');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    // ---- seeding helpers ----

    private function workspace(string $slugPrefix): Workspace
    {
        $ws = (new Workspace())
            ->setName('OC ' . $slugPrefix)
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

    private function projectStatus(Workspace $ws, string $name): ProjectStatus
    {
        $s = (new ProjectStatus())->setName($name);
        $s->setWorkspace($ws);
        $this->em->persist($s);

        return $s;
    }

    private function project(Workspace $ws, string $name, string $key, ProjectStatus $status, ?Customer $customer): Project
    {
        $p = (new Project())->setName($name)->setKey($key)->setStatus($status)->setCustomer($customer);
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
