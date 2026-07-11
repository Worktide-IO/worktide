<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A deactivated workspace member (isActive=false) must be treated as a
 * non-member: they can no longer list or open the workspace's resources, even
 * though the membership row still exists. Guards the scope extension + voters.
 */
final class MemberDeactivationTest extends WebTestCase
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

    public function testDeactivatedMemberLosesAccessActiveMemberKeepsIt(): void
    {
        $ws = (new Workspace())->setName('WS deact')->setSlug('ws-deact-' . substr(bin2hex(random_bytes(4)), 0, 8))
            ->setLocale('de')->setTimezone('Europe/Berlin')->setSettings([]);
        $this->em->persist($ws);

        $active = $this->user('active.deact@example.test');
        $blocked = $this->user('blocked.deact@example.test');
        $this->em->persist((new WorkspaceMember())->setWorkspace($ws)->setUser($active)->setRole(WorkspaceMemberRole::Owner));
        $this->em->persist((new WorkspaceMember())->setWorkspace($ws)->setUser($blocked)->setRole(WorkspaceMemberRole::Member)->setIsActive(false));

        $pStatus = (new ProjectStatus())->setWorkspace($ws)->setName('Aktiv')->setColor('#888')
            ->setPosition(0)->setIsCompleted(false)->setIsArchived(false);
        $this->em->persist($pStatus);
        $project = (new Project())->setWorkspace($ws)->setName('Deact P')->setKey('DP')->setColor('#000000')->setStatus($pStatus);
        $this->em->persist($project);
        $this->em->flush();

        $wsId = $ws->getId()?->toRfc4122() ?? '';
        $projectPath = '/v1/projects/' . ($project->getId()?->toRfc4122() ?? '');

        // Active owner sees the project + can open it.
        $this->get('/v1/projects', $active, $wsId);
        self::assertContains($project->getId()?->toRfc4122(), $this->memberIds(), 'active member lists the project');
        $this->get($projectPath, $active, $wsId);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'active member opens the project');

        // Deactivated member is blocked: empty list + 404 on the item.
        $this->get('/v1/projects', $blocked, $wsId);
        self::assertNotContains($project->getId()?->toRfc4122(), $this->memberIds(), 'deactivated member does not list the project');
        $this->get($projectPath, $blocked, $wsId);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'deactivated member cannot open the project');
    }

    private function user(string $email): User
    {
        $u = (new User())->setEmail($email)->setFirstName('D')->setLastName('U')->setRoles([]);
        $u->setPassword('x');
        $this->em->persist($u);

        return $u;
    }

    /** @return list<string> */
    private function memberIds(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        $members = $data['member'] ?? $data['hydra:member'] ?? [];

        return array_values(array_filter(array_map(static fn (array $r): string => (string) ($r['id'] ?? ''), $members)));
    }

    private function get(string $uri, User $as, string $wsId): void
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($as);
        $this->client->request('GET', $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_X_WORKSPACE_ID' => $wsId,
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
    }
}
