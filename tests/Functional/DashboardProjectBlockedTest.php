<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\TaskDependencyType;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Entity\Task;
use App\Entity\TaskDependency;
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
 * GET /v1/dashboard/project-blocked?project= (web M3, surface 5 — board). A
 * successor is "blocked" iff a BLOCKING-type dependency's predecessor is in the
 * queried project AND still open. Contract:
 *  - blocking + predecessor-open + in-project → successor listed;
 *  - predecessor completed → not listed;
 *  - non-blocking type (relates) → not listed;
 *  - predecessor in a different project → not listed (project-scoped);
 *  - missing project → 400; unauth → 401.
 */
final class DashboardProjectBlockedTest extends WebTestCase
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

    public function testReturnsSuccessorsBlockedByAnOpenInProjectPredecessor(): void
    {
        $ws = $this->workspace('blk');
        $alice = $this->user('alice.blk@example.test');
        $this->member($alice, $ws);

        $ps = $this->projectStatus($ws, 'Active');
        $proj = $this->project($ws, 'Board', 'BD', $ps);
        $other = $this->project($ws, 'Other', 'OT', $ps);

        $open = $this->taskStatus($ws, 'Todo', false);
        $done = $this->taskStatus($ws, 'Done', true);

        $predOpen = $this->task($ws, 'BD-1', 'pred open', $open, $proj);
        $predDone = $this->task($ws, 'BD-2', 'pred done', $done, $proj);
        $predOther = $this->task($ws, 'OT-1', 'pred other project', $open, $other);
        $succBlocked = $this->task($ws, 'BD-3', 'blocked', $open, $proj);
        $succByDone = $this->task($ws, 'BD-4', 'not blocked (pred done)', $open, $proj);
        $succRelates = $this->task($ws, 'BD-5', 'not blocked (relates)', $open, $proj);
        $succOther = $this->task($ws, 'BD-6', 'not blocked (pred other project)', $open, $proj);

        $this->dep($ws, $predOpen, $succBlocked, TaskDependencyType::FinishToStart);
        $this->dep($ws, $predDone, $succByDone, TaskDependencyType::Blocks);
        $this->dep($ws, $predOpen, $succRelates, TaskDependencyType::Relates);
        $this->dep($ws, $predOther, $succOther, TaskDependencyType::FinishToStart);
        $this->em->flush();

        $this->get('/v1/dashboard/project-blocked?project=' . $proj->getId()?->toRfc4122(), $this->token($alice));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $blocked = $this->blockedIris();
        self::assertContains('/v1/tasks/' . $succBlocked->getId()?->toRfc4122(), $blocked);
        self::assertNotContains('/v1/tasks/' . $succByDone->getId()?->toRfc4122(), $blocked, 'completed predecessor does not block');
        self::assertNotContains('/v1/tasks/' . $succRelates->getId()?->toRfc4122(), $blocked, 'non-blocking type does not block');
        self::assertNotContains('/v1/tasks/' . $succOther->getId()?->toRfc4122(), $blocked, 'predecessor in another project is out of scope');
    }

    public function testMissingProjectIsBadRequest(): void
    {
        $ws = $this->workspace('blk-bad');
        $alice = $this->user('alice.blk2@example.test');
        $this->member($alice, $ws);
        $this->em->flush();

        $this->get('/v1/dashboard/project-blocked', $this->token($alice));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $this->client->request('GET', '/v1/dashboard/project-blocked?project=' . Uuid::v7()->toRfc4122());
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    // ---- seeding helpers ----

    private function workspace(string $slugPrefix): Workspace
    {
        $ws = (new Workspace())
            ->setName('Blk ' . $slugPrefix)
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

    private function dep(Workspace $ws, Task $pred, Task $succ, TaskDependencyType $type): void
    {
        $d = (new TaskDependency())->setPredecessor($pred)->setSuccessor($succ)->setType($type);
        $d->setWorkspace($ws);
        $this->em->persist($d);
    }

    private function get(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /** @return list<string> */
    private function blockedIris(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        return $data['blocked'] ?? [];
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
