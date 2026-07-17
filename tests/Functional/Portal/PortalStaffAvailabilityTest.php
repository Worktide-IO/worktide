<?php

declare(strict_types=1);

namespace App\Tests\Functional\Portal;

use App\Entity\Absence;
use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\TaskPriority;
use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Entity\Task;
use App\Entity\TaskAssignee;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Portal staff-availability: a customer sees the limited availability of the
 * staff they work with — the owner of their projects and the assignees of their
 * visible tickets — but NOT the absence type (medical privacy), and NOT full
 * (0 %) absences.
 */
final class PortalStaffAvailabilityTest extends WebTestCase
{
    private const HOST = 'api.worktide.ddev.site';

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

    public function testShowsLimitedAvailabilityForOwnerAndAssigneeOnly(): void
    {
        $portalUser = $this->seed();
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($portalUser);

        $this->client->request('GET', '/v1/portal/staff-availability', [], [], [
            'HTTP_HOST' => self::HOST,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $staff = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR)['staff'];

        $names = array_column($staff, 'staffName');
        sort($names);
        self::assertSame(['Ann Assignee', 'Otto Owner'], $names, 'owner + assignee with limited availability appear; full-absence staff does not');

        foreach ($staff as $row) {
            self::assertArrayNotHasKey('type', $row, 'the medical absence type must never be exposed to the portal');
            self::assertGreaterThan(0, $row['availabilityPercent']);
        }
    }

    private function seed(): User
    {
        $ws = (new Workspace())
            ->setName('WS')->setSlug('ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['tickets' => true]]]);
        $this->em->persist($ws);

        $projectStatus = (new ProjectStatus())->setWorkspace($ws)->setName('Aktiv')->setColor('#111111')->setPosition(1);
        $this->em->persist($projectStatus);
        $taskStatus = (new TaskStatus())->setWorkspace($ws)->setName('Offen')->setColor('#222222')->setPosition(1);
        $this->em->persist($taskStatus);

        $portalUser = $this->user('portal.avail@example.test', ['ROLE_PORTAL']);
        $customer = (new Customer())->setWorkspace($ws)->setName('Own GmbH')->setIsCompany(true)
            ->setStatus(CustomerStatus::Active)->setPortalEnabled(true);
        $this->em->persist($customer);
        $contact = (new Contact())->setCustomer($customer)->setFirstName('Paula')->setLastName('Portal')
            ->setEmail('portal.avail@example.test')->setLinkedUser($portalUser);
        $this->em->persist($contact);

        // Owner of the customer's external project — has a 50 % absence → shown.
        $owner = $this->user('otto.owner@example.test', []);
        $owner->setFirstName('Otto')->setLastName('Owner');
        $this->absence($ws, $owner, 50);

        $project = (new Project())->setWorkspace($ws)->setCustomer($customer)->setName('P Projekt')
            ->setKey('P')->setColor('#000000')->setStatus($projectStatus)->setIsExternal(true)->setOwner($owner);
        $this->em->persist($project);

        // Assignee of a visible ticket — has a 25 % absence → shown.
        $assignee = $this->user('ann.assignee@example.test', []);
        $assignee->setFirstName('Ann')->setLastName('Assignee');
        $this->absence($ws, $assignee, 25);

        $task = (new Task())->setWorkspace($ws)->setProject($project)->setIdentifier('P-1')
            ->setTitle('P-1')->setStatus($taskStatus)->setPriority(TaskPriority::Normal)->setIsHiddenForConnectUsers(false);
        $assignment = (new TaskAssignee())->setPrincipalType(AssigneePrincipalType::User)->setPrincipalId($assignee->getId());
        $task->addAssignedPrincipal($assignment);
        $this->em->persist($task);
        $this->em->persist($assignment);

        // Unrelated staff with a limited absence but no link to this customer → hidden.
        $stranger = $this->user('sam.stranger@example.test', []);
        $stranger->setFirstName('Sam')->setLastName('Stranger');
        $this->absence($ws, $stranger, 50);

        // Owner also has a FULL absence (0 %) — must NOT surface.
        $this->absence($ws, $owner, 0);

        $this->em->flush();
        $this->em->clear();

        return $this->em->find(User::class, $portalUser->getId());
    }

    private function absence(Workspace $ws, User $user, int $availabilityPercent): void
    {
        $absence = (new Absence())
            ->setUser($user)
            ->setStartsOn(new \DateTimeImmutable('+2 days 12:00'))
            ->setEndsOn(new \DateTimeImmutable('+4 days 12:00'))
            ->setType($availabilityPercent > 0 ? 'child_sick' : 'vacation')
            ->setAvailabilityPercent($availabilityPercent);
        $absence->setWorkspace($ws);
        $this->em->persist($absence);
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles): User
    {
        $user = (new User())->setEmail($email)->setFirstName('T')->setLastName('U')->setRoles($roles);
        $user->setPassword('noop');
        $this->em->persist($user);

        return $user;
    }
}
