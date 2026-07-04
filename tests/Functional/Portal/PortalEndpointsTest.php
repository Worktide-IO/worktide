<?php

declare(strict_types=1);

namespace App\Tests\Functional\Portal;

use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\TaskPriority;
use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Functional smoke tests for the customer portal — exercises the real routing,
 * the ROLE_PORTAL firewall, per-feature flags and customer scoping end-to-end.
 *
 * Isolation: each test runs inside a DB transaction that is rolled back in
 * tearDown, so the shared dev database is left untouched. Portal routes require
 * the api host, so every request sets HTTP_HOST.
 */
final class PortalEndpointsTest extends WebTestCase
{
    private const HOST = 'api.worktide.ddev.site';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // keep one kernel/connection so the tx holds across the request
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

    public function testUnauthenticatedIsRejected(): void
    {
        $this->request('GET', '/v1/portal/me');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testStaffTokenCannotAccessPortal(): void
    {
        $ctx = $this->seed();
        $this->request('GET', '/v1/portal/me', $this->token($ctx['staff']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testPortalTokenCannotAccessStaffEndpoint(): void
    {
        $ctx = $this->seed();
        $this->request('GET', '/v1/tasks', $this->token($ctx['portalUser']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testMeReturnsCuratedContact(): void
    {
        $ctx = $this->seed();
        $this->request('GET', '/v1/portal/me', $this->token($ctx['portalUser']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = $this->json();
        self::assertSame('portal.contact@example.test', $data['contact']['email']);
        self::assertTrue($data['features']['tickets']);
        self::assertFalse($data['features']['monitoring']);
    }

    public function testTicketsAreScopedAndHiddenExcluded(): void
    {
        $ctx = $this->seed();
        $this->request('GET', '/v1/portal/tickets', $this->token($ctx['portalUser']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $ids = array_column($this->json()['tickets'], 'identifier');

        self::assertContains('OWN-1', $ids);          // own visible ticket
        self::assertNotContains('OWN-HIDDEN', $ids);  // isHiddenForConnectUsers
        self::assertNotContains('FOR-1', $ids);       // another customer's ticket
    }

    public function testForeignTicketIs404(): void
    {
        $ctx = $this->seed();
        $this->request('GET', '/v1/portal/tickets/' . $ctx['foreignTaskId'], $this->token($ctx['portalUser']));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDisabledFeatureIs403(): void
    {
        $ctx = $this->seed(); // monitoring is OFF in the seed
        $this->request('GET', '/v1/portal/systems', $this->token($ctx['portalUser']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    // --- helpers ----------------------------------------------------

    private function request(string $method, string $uri, ?string $token = null): void
    {
        $server = ['HTTP_HOST' => self::HOST, 'CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request($method, $uri, [], [], $server);
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

    /**
     * Build an isolated portal world: a workspace (portal on, only `tickets`
     * feature enabled), a customer with an external project holding a visible
     * + a hidden ticket, a linked portal user + a staff user, and a SECOND
     * customer whose ticket must stay invisible.
     *
     * @return array{portalUser: User, staff: User, foreignTaskId: string}
     */
    private function seed(): array
    {
        $ws = (new Workspace())
            ->setName('Test WS')
            ->setSlug('test-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['tickets' => true, 'monitoring' => false]]]);
        $this->em->persist($ws);

        $status = (new TaskStatus())->setWorkspace($ws)->setName('Offen')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsDefault(true);
        $this->em->persist($status);

        // One shared project status (unique per workspace+name).
        $projectStatus = (new ProjectStatus())->setWorkspace($ws)->setName('Aktiv')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsArchived(false);
        $this->em->persist($projectStatus);

        $portalUser = $this->user('portal.contact@example.test', ['ROLE_PORTAL']);
        $staff = $this->user('portal.staff@example.test', []);

        // Own customer + external project + contact linked to the portal user.
        $customer = $this->customer($ws, 'Own GmbH');
        $contact = (new Contact())->setCustomer($customer)->setFirstName('Paula')->setLastName('Portal')
            ->setEmail('portal.contact@example.test')->setLinkedUser($portalUser);
        $this->em->persist($contact);
        $project = $this->project($ws, $customer, 'OWN', $projectStatus);
        $this->task($ws, $project, $status, 'OWN-1', false);
        $this->task($ws, $project, $status, 'OWN-HIDDEN', true);

        // Foreign customer + project + ticket — must never be visible.
        $foreignCustomer = $this->customer($ws, 'Foreign GmbH');
        $foreignProject = $this->project($ws, $foreignCustomer, 'FOR', $projectStatus);
        $foreignTask = $this->task($ws, $foreignProject, $status, 'FOR-1', false);

        $this->em->flush();
        // Detach the seed graph so the request reloads from DB (with working
        // lazy inverse-collections), like a real request would — otherwise the
        // in-memory Customer keeps its empty projects collection.
        $this->em->clear();

        return [
            'portalUser' => $portalUser,
            'staff' => $staff,
            'foreignTaskId' => $foreignTask->getId()?->toRfc4122() ?? '',
        ];
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles): User
    {
        $user = (new User())->setEmail($email)->setFirstName('T')->setLastName('U')->setRoles($roles);
        $user->setPassword('noop'); // JWT auth never checks it in these tests
        $this->em->persist($user);
        return $user;
    }

    private function customer(Workspace $ws, string $name): Customer
    {
        $customer = (new Customer())->setWorkspace($ws)->setName($name)->setIsCompany(true)->setStatus(CustomerStatus::Active);
        $this->em->persist($customer);
        return $customer;
    }

    private function project(Workspace $ws, Customer $customer, string $key, ProjectStatus $status): Project
    {
        $project = (new Project())->setWorkspace($ws)->setCustomer($customer)->setName($key . ' Projekt')
            ->setKey($key)->setColor('#000000')->setStatus($status)->setIsExternal(true);
        $this->em->persist($project);
        return $project;
    }

    private function task(Workspace $ws, Project $project, TaskStatus $status, string $identifier, bool $hidden): Task
    {
        $task = (new Task())->setWorkspace($ws)->setProject($project)->setIdentifier($identifier)
            ->setTitle($identifier)->setStatus($status)->setPriority(TaskPriority::Normal)->setIsHiddenForConnectUsers($hidden);
        $this->em->persist($task);
        return $task;
    }
}
