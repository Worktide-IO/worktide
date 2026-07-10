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
use Symfony\Component\Uid\Uuid;

/**
 * The bulk import endpoint (POST /v1/imports/{resource}) is an admin-level
 * operation: it must require workspace EDIT (owner/admin), matching the
 * Customer/Contact mutation security. A read-mostly member/guest must not be
 * able to bulk-create records.
 */
final class ImportCapabilityTest extends WebTestCase
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

    public function testGuestCannotImport(): void
    {
        $ctx = $this->seed();
        $this->import($ctx['ws'], $this->token($ctx['guest']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'a guest must not be able to bulk-import');
    }

    public function testMemberCannotImport(): void
    {
        $ctx = $this->seed();
        $this->import($ctx['ws'], $this->token($ctx['member']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'a non-admin member must not be able to bulk-import');
    }

    public function testAdminCanImport(): void
    {
        $ctx = $this->seed();
        $this->import($ctx['ws'], $this->token($ctx['admin']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'a workspace admin may import');
    }

    private function import(Workspace $ws, string $token): void
    {
        $this->client->request(
            'POST',
            '/v1/imports/customers',
            [], [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'HTTP_X_WORKSPACE_ID' => $ws->getId()?->toRfc4122() ?? '',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['rows' => [['name' => 'Imported GmbH']], 'dryRun' => true], \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{ws: Workspace, admin: User, member: User, guest: User} */
    private function seed(): array
    {
        $ws = (new Workspace())
            ->setName('Import WS')
            ->setSlug('import-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings([]);
        $this->em->persist($ws);

        $admin = $this->user('admin.import@example.test');
        $member = $this->user('member.import@example.test');
        $guest = $this->user('guest.import@example.test');
        $this->member($admin, $ws, WorkspaceMemberRole::Admin);
        $this->member($member, $ws, WorkspaceMemberRole::Member);
        $this->member($guest, $ws, WorkspaceMemberRole::Guest);

        $this->em->flush();

        return ['ws' => $ws, 'admin' => $admin, 'member' => $member, 'guest' => $guest];
    }

    private function user(string $email): User
    {
        $user = (new User())->setEmail($email)->setFirstName('T')->setLastName('User')->setRoles([]);
        $user->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function member(User $user, Workspace $ws, WorkspaceMemberRole $role): void
    {
        $this->em->persist((new WorkspaceMember())->setUser($user)->setWorkspace($ws)->setRole($role));
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
