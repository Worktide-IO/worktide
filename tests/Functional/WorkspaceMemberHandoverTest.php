<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Enum\TaskPriority;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Project;
use App\Entity\ProjectStatus;
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

/**
 * Removing a member hands their task assignments over to another active member
 * instead of orphaning them: POST /v1/workspace_members/{id}/remove {reassignTo}.
 */
final class WorkspaceMemberHandoverTest extends WebTestCase
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

    public function testRemoveReassignsAssignedTasksToTarget(): void
    {
        $ws = (new Workspace())->setName('WS ho')->setSlug('ws-ho-' . substr(bin2hex(random_bytes(4)), 0, 8))
            ->setLocale('de')->setTimezone('Europe/Berlin')->setSettings([]);
        $this->em->persist($ws);

        $owner = $this->user('owner.ho@example.test');
        $leaving = $this->user('leaving.ho@example.test');
        $target = $this->user('target.ho@example.test');
        $this->em->persist((new WorkspaceMember())->setWorkspace($ws)->setUser($owner)->setRole(WorkspaceMemberRole::Owner));
        $leavingMembership = (new WorkspaceMember())->setWorkspace($ws)->setUser($leaving)->setRole(WorkspaceMemberRole::Member);
        $this->em->persist($leavingMembership);
        $this->em->persist((new WorkspaceMember())->setWorkspace($ws)->setUser($target)->setRole(WorkspaceMemberRole::Member));

        $ps = (new ProjectStatus())->setWorkspace($ws)->setName('Aktiv')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsArchived(false);
        $this->em->persist($ps);
        $project = (new Project())->setWorkspace($ws)->setName('HO P')->setKey('HO')->setColor('#000000')->setStatus($ps);
        $this->em->persist($project);
        $ts = (new TaskStatus())->setWorkspace($ws)->setName('Offen')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsDefault(true);
        $this->em->persist($ts);
        $task = (new Task())->setWorkspace($ws)->setProject($project)->setIdentifier('HO-1')->setTitle('t')->setStatus($ts)->setPriority(TaskPriority::Normal);
        $this->em->persist($task);
        $task->addAssignedPrincipal((new TaskAssignee())->setTask($task)->setPrincipalType(AssigneePrincipalType::User)->setPrincipalId($leaving->getId()));
        $this->em->flush();

        $memberId = $leavingMembership->getId()?->toRfc4122();

        // Count reflects the one assigned task.
        $this->req('GET', "/v1/workspace_members/{$memberId}/assignments", $owner);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->json()['assignedTaskCount'] ?? null);

        // Remove with reassignment to the target member.
        $this->req('POST', "/v1/workspace_members/{$memberId}/remove", $owner, ['reassignTo' => $target->getId()?->toRfc4122()]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->json()['reassignedTasks'] ?? null);

        $this->em->clear();
        // Leaving membership is gone.
        self::assertNull($this->em->getRepository(WorkspaceMember::class)->find($leavingMembership->getId()));
        // Task is now assigned to the target, not the leaving user.
        $assignees = $this->em->getRepository(TaskAssignee::class)->findBy(['task' => $task->getId()]);
        $ids = array_map(static fn (TaskAssignee $a) => $a->getPrincipalId()->toRfc4122(), $assignees);
        self::assertContains($target->getId()?->toRfc4122(), $ids, 'task reassigned to target');
        self::assertNotContains($leaving->getId()?->toRfc4122(), $ids, 'leaving user no longer assigned');
    }

    private function user(string $email): User
    {
        $u = (new User())->setEmail($email)->setFirstName('H')->setLastName('O')->setRoles([]);
        $u->setPassword('x');
        $this->em->persist($u);

        return $u;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed>|null $body */
    private function req(string $method, string $uri, User $as, ?array $body = null): void
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($as);
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json',
        ], $body === null ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
