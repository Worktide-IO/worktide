<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\UserCapacity;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * GET /v1/dashboard/team-availability — cross-workspace team availability widget.
 *
 * Contract:
 *  - only active members of the current workspace appear;
 *  - absences from OTHER workspaces (where the same user is a member) are
 *    included (cross-workspace aggregation);
 *  - members with no absences AND full standard capacity (≥ 2400 min/week)
 *    are excluded (the widget shows limited-availability only);
 *  - the response is workspace-scoped and the X-Workspace-Id header is
 *    membership-checked (no cross-tenant read);
 *  - unauthenticated → 401.
 */
final class DashboardTeamAvailabilityTest extends WebTestCase
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

    public function testShowsAbsencesFromOtherWorkspaces(): void
    {
        $wsA = $this->workspace('avail-a');
        $wsB = $this->workspace('avail-b');
        $alice = $this->user('alice.avail@example.test');
        $this->member($alice, $wsA);
        $this->member($alice, $wsB);
        $this->em->flush();

        // Alice records a vacation in workspace B.
        $this->absence($alice, $wsB, 'vacation', new \DateTimeImmutable('now'), new \DateTimeImmutable('+5 days'), 0);
        $this->em->flush();

        // Viewing workspace A's dashboard — Alice's vacation from workspace B should appear.
        $this->get('/v1/dashboard/team-availability', $this->token($alice), $wsA);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $data = $this->json();
        $members = $data['members'];
        self::assertIsArray($members);
        self::assertGreaterThanOrEqual(1, \count($members), 'Alice should appear (has absence)');

        $aliceRow = $this->findMember($members, $alice->getId()?->toRfc4122() ?? '');
        self::assertNotNull($aliceRow, 'Alice must be in the response');
        self::assertNotEmpty($aliceRow['absences'], 'Alice must have absences');

        $abs = $aliceRow['absences'][0];
        self::assertSame($wsB->getId()?->toRfc4122(), $abs['sourceWorkspace']['id']);
        self::assertSame('vacation', $abs['type']);
        self::assertSame(0, $abs['availabilityPercent']);
    }

    public function testExcludesMembersWithFullCapacityAndNoAbsences(): void
    {
        $ws = $this->workspace('avail-excl');
        $alice = $this->user('alice.excl@example.test');
        $bob = $this->user('bob.excl@example.test');
        $this->member($alice, $ws);
        $this->member($bob, $ws);

        // Bob has full capacity (5×480 = 2400) and no absences → should be excluded.
        $this->capacity($bob, 480, 480, 480, 480, 480, 0, 0);
        $this->em->flush();

        $this->get('/v1/dashboard/team-availability', $this->token($alice), $ws);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $data = $this->json();
        $members = $data['members'];
        $bobRow = $this->findMember($members, $bob->getId()?->toRfc4122() ?? '');
        self::assertNull($bobRow, 'Bob (full capacity, no absence) must not appear');
    }

    public function testIncludesMemberWithReducedCapacity(): void
    {
        $ws = $this->workspace('avail-rc');
        $alice = $this->user('alice.rc@example.test');
        $this->member($alice, $ws);

        // Alice works only 3 days/week (3×480 = 1440 < 2400) → reduced capacity.
        $this->capacity($alice, 480, 0, 480, 0, 480, 0, 0);
        $this->em->flush();

        $this->get('/v1/dashboard/team-availability', $this->token($alice), $ws);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $data = $this->json();
        $members = $data['members'];
        $aliceRow = $this->findMember($members, $alice->getId()?->toRfc4122() ?? '');
        self::assertNotNull($aliceRow, 'Alice (reduced capacity) must appear');
        self::assertSame(480, $aliceRow['capacityMinutes']['mon']);
        self::assertSame(0, $aliceRow['capacityMinutes']['tue']);
        self::assertSame([], $aliceRow['absences'], 'No absences yet');
    }

    public function testRejectsForeignWorkspaceHeader(): void
    {
        $wsA = $this->workspace('avail-own');
        $wsB = $this->workspace('avail-foreign');
        $alice = $this->user('alice.fw@example.test');
        $this->member($alice, $wsA); // NOT a member of wsB
        $this->em->flush();

        $this->get('/v1/dashboard/team-availability', $this->token($alice), $wsA);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/v1/dashboard/team-availability', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($alice),
            'HTTP_X_WORKSPACE_ID' => $wsB->getId()?->toRfc4122() ?? '',
        ]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $this->client->request('GET', '/v1/dashboard/team-availability');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testDaysParameterIsRespected(): void
    {
        $ws = $this->workspace('avail-days');
        $alice = $this->user('alice.days@example.test');
        $this->member($alice, $ws);

        // Absence starts in 60 days — outside default 30-day window.
        $this->absence($alice, $ws, 'vacation', new \DateTimeImmutable('+60 days'), new \DateTimeImmutable('+65 days'), 0);
        $this->em->flush();

        // Default window (30 days) → absence not shown.
        $this->get('/v1/dashboard/team-availability', $this->token($alice), $ws);
        $data = $this->json();
        $aliceRow = $this->findMember($data['members'], $alice->getId()?->toRfc4122() ?? '');
        self::assertNull($aliceRow, 'Far-future absence must not appear in default window');

        // Extended window (90 days) → absence shown.
        $this->get('/v1/dashboard/team-availability?days=90', $this->token($alice), $ws);
        $data = $this->json();
        $aliceRow = $this->findMember($data['members'], $alice->getId()?->toRfc4122() ?? '');
        self::assertNotNull($aliceRow, 'Far-future absence must appear with days=90');
        self::assertNotEmpty($aliceRow['absences']);
    }

    // ---- helpers ----

    private function workspace(string $slugPrefix): Workspace
    {
        $ws = (new Workspace())
            ->setName('Avail ' . $slugPrefix)
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

    private function absence(User $user, Workspace $ws, string $type, \DateTimeImmutable $start, \DateTimeImmutable $end, int $availabilityPercent): Absence
    {
        $a = (new Absence())
            ->setUser($user)
            ->setWorkspace($ws)
            ->setType($type)
            ->setStartsOn($start)
            ->setEndsOn($end)
            ->setAvailabilityPercent($availabilityPercent);
        $this->em->persist($a);

        return $a;
    }

    private function capacity(User $user, int $mon, int $tue, int $wed, int $thu, int $fri, int $sat, int $sun): void
    {
        $cap = (new UserCapacity())
            ->setUser($user)
            ->setMonMinutes($mon)
            ->setTueMinutes($tue)
            ->setWedMinutes($wed)
            ->setThuMinutes($thu)
            ->setFriMinutes($fri)
            ->setSatMinutes($sat)
            ->setSunMinutes($sun);
        $this->em->persist($cap);
    }

    private function get(string $uri, string $token, ?Workspace $ws = null): void
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        if ($ws !== null) {
            $headers['HTTP_X_WORKSPACE_ID'] = $ws->getId()?->toRfc4122() ?? '';
        }
        $this->client->request('GET', $uri, [], [], $headers);
    }

    /** @return array{members: list<array<string, mixed>>, capped: bool} */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string, mixed>> $members */
    private function findMember(array $members, string $userId): ?array
    {
        foreach ($members as $m) {
            if (($m['user']['id'] ?? '') === $userId) {
                return $m;
            }
        }

        return null;
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
