<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\ProjectMemberRole;
use App\Entity\Enum\TaskPriority;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Project;
use App\Entity\ProjectShare;
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
 * Cross-workspace project sharing scope + voter: a member of workspace B that
 * has an accepted ProjectShare of workspace A's project can see + open the
 * shared project and its tasks; a member of an unrelated workspace C cannot
 * (no cross-tenant leak).
 */
final class ProjectShareScopeTest extends WebTestCase
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

    public function testSharedProjectVisibleToTargetWorkspaceButNotOthers(): void
    {
        $ctx = $this->seed();
        $projectPath = '/v1/projects/' . $ctx['projectId'];
        $tasksQuery = '/v1/tasks?project=' . rawurlencode($projectPath);

        // B (accepted share) SEES + opens the shared project + its task.
        $tokenB = $this->token($ctx['userB']);
        $this->request('GET', $projectPath, $tokenB, $ctx['wsB']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'B can open the shared project');

        $this->request('GET', '/v1/projects', $tokenB, $ctx['wsB']);
        self::assertContains($ctx['projectId'], $this->memberIds(), 'shared project in B project list');

        $this->request('GET', $tasksQuery, $tokenB, $ctx['wsB']);
        self::assertContains($ctx['taskId'], $this->memberIds(), 'B sees the shared project task');

        // C (no share) sees NOTHING of workspace A.
        $tokenC = $this->token($ctx['userC']);
        $this->request('GET', $projectPath, $tokenC, $ctx['wsC']);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'C cannot open the un-shared project');

        $this->request('GET', '/v1/projects', $tokenC, $ctx['wsC']);
        self::assertNotContains($ctx['projectId'], $this->memberIds(), 'shared project NOT in C project list');

        $this->request('GET', $tasksQuery, $tokenC, $ctx['wsC']);
        self::assertNotContains($ctx['taskId'], $this->memberIds(), 'C does not see the task (no leak)');
    }

    /**
     * @return array{userB: User, userC: User, wsB: string, wsC: string, projectId: string, taskId: string}
     */
    private function seed(): array
    {
        $wsA = $this->workspace('A');
        $wsB = $this->workspace('B');
        $wsC = $this->workspace('C');

        $userB = $this->user('share.b@example.test');
        $userC = $this->user('share.c@example.test');
        $this->em->persist((new WorkspaceMember())->setWorkspace($wsB)->setUser($userB)->setRole(WorkspaceMemberRole::Owner));
        $this->em->persist((new WorkspaceMember())->setWorkspace($wsC)->setUser($userC)->setRole(WorkspaceMemberRole::Owner));

        $status = (new TaskStatus())->setWorkspace($wsA)->setName('Offen')->setColor('#888')
            ->setPosition(0)->setIsCompleted(false)->setIsDefault(true);
        $this->em->persist($status);
        $projectStatus = (new ProjectStatus())->setWorkspace($wsA)->setName('Aktiv')->setColor('#888')
            ->setPosition(0)->setIsCompleted(false)->setIsArchived(false);
        $this->em->persist($projectStatus);

        $project = (new Project())->setWorkspace($wsA)->setName('Shared P')->setKey('SP')
            ->setColor('#000000')->setStatus($projectStatus);
        $this->em->persist($project);
        $task = (new Task())->setWorkspace($wsA)->setProject($project)->setIdentifier('SP-1')
            ->setTitle('shared task')->setStatus($status)->setPriority(TaskPriority::Normal);
        $this->em->persist($task);

        // Accepted share of A's project into workspace B.
        $this->em->persist((new ProjectShare())
            ->setProject($project)
            ->setSharedWithWorkspace($wsB)
            ->setRole(ProjectMemberRole::Contributor)
            ->setAcceptedBy($userB));

        $this->em->flush();

        return [
            'userB' => $userB,
            'userC' => $userC,
            'wsB' => $wsB->getId()?->toRfc4122() ?? '',
            'wsC' => $wsC->getId()?->toRfc4122() ?? '',
            'projectId' => $project->getId()?->toRfc4122() ?? '',
            'taskId' => $task->getId()?->toRfc4122() ?? '',
        ];
    }

    private function workspace(string $tag): Workspace
    {
        $ws = (new Workspace())
            ->setName("WS $tag")
            ->setSlug('ws-' . strtolower($tag) . '-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings([]);
        $this->em->persist($ws);

        return $ws;
    }

    private function user(string $email): User
    {
        $user = (new User())->setEmail($email)->setFirstName('S')->setLastName('U')->setRoles([]);
        $user->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    /** @return list<string> */
    private function memberIds(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        $members = $data['member'] ?? $data['hydra:member'] ?? [];

        return array_values(array_filter(array_map(static fn (array $r): string => (string) ($r['id'] ?? ''), $members)));
    }

    private function request(string $method, string $uri, string $token, string $wsId): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_X_WORKSPACE_ID' => $wsId,
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
