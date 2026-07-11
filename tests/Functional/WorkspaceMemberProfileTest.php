<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin edit of a member's name/email via PATCH /v1/workspace_members/{id}/profile:
 * a workspace owner may edit a colleague; email stays unique; a non-manager is
 * refused.
 */
final class WorkspaceMemberProfileTest extends WebTestCase
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

    public function testOwnerEditsMemberNameAndEmailButUniquenessAndAuthzHold(): void
    {
        $ws = $this->workspace();
        $owner = $this->user('owner.mp@example.test');
        $member = $this->user('member.mp@example.test');
        $outsider = $this->user('outsider.mp@example.test');
        $this->member($ws, $owner, WorkspaceMemberRole::Owner);
        $memberMembership = $this->member($ws, $member, WorkspaceMemberRole::Member);
        $this->em->flush();

        $memberId = $memberMembership->getId()?->toRfc4122();
        $path = "/v1/workspace_members/{$memberId}/profile";

        // Owner edits the member's name + email → 200, persisted.
        $this->patch($path, $owner, ['firstName' => 'Renamed', 'email' => 'renamed.mp@example.test']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($member->getId());
        self::assertSame('Renamed', $reloaded?->getFirstName());
        self::assertSame('renamed.mp@example.test', $reloaded?->getEmail());

        // Email already used by the owner → 409.
        $this->patch($path, $owner, ['email' => 'owner.mp@example.test']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());

        // A non-manager (outsider, not even a member) → denied (403/404), no change.
        $this->patch($path, $outsider, ['firstName' => 'Hacked']);
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
        $this->em->clear();
        self::assertSame('Renamed', $this->em->getRepository(User::class)->find($member->getId())?->getFirstName());
    }

    private function workspace(): Workspace
    {
        $ws = (new Workspace())
            ->setName('WS MP')
            ->setSlug('ws-mp-' . substr(bin2hex(random_bytes(4)), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings([]);
        $this->em->persist($ws);

        return $ws;
    }

    private function user(string $email): User
    {
        $u = (new User())->setEmail($email)->setFirstName('F')->setLastName('L')->setRoles([]);
        $u->setPassword('x');
        $this->em->persist($u);

        return $u;
    }

    private function member(Workspace $ws, User $u, WorkspaceMemberRole $role): WorkspaceMember
    {
        $m = (new WorkspaceMember())->setWorkspace($ws)->setUser($u)->setRole($role);
        $this->em->persist($m);

        return $m;
    }

    /** @param array<string, mixed> $body */
    private function patch(string $path, User $as, array $body): void
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($as);
        $this->client->request('PATCH', $path, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
