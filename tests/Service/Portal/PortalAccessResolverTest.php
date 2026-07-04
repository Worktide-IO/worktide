<?php

declare(strict_types=1);

namespace App\Tests\Service\Portal;

use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ContactRepository;
use App\Repository\TaskRepository;
use App\Service\Portal\PortalAccessResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Guards the portal authorization choke-point: identity chain, project
 * scoping, and the security-critical `isHiddenForConnectUsers` gate that
 * nothing else in the codebase enforces for tasks.
 */
final class PortalAccessResolverTest extends TestCase
{
    public function testContactThrowsWhenUserHasNoLinkedContact(): void
    {
        $user = new User();
        $resolver = $this->resolver($user, contact: null);

        $this->expectException(AccessDeniedHttpException::class);
        $resolver->contact();
    }

    public function testAllowedProjectsReturnsOnlyExternalNonArchivedProjects(): void
    {
        $customer = new Customer();
        $external = $this->project($customer, key: 'EXT', external: true, archived: false);
        $this->project($customer, key: 'INT', external: false, archived: false); // internal → excluded
        $this->project($customer, key: 'OLD', external: true, archived: true);    // archived → excluded

        $resolver = $this->resolver(...$this->linked($customer));

        $allowed = $resolver->allowedProjects();
        self::assertCount(1, $allowed);
        self::assertSame($external, $allowed[0]);
    }

    public function testFindTicketReturnsTaskInAllowedProject(): void
    {
        $customer = new Customer();
        $project = $this->project($customer, key: 'EXT', external: true, archived: false);
        $task = $this->task($project, hidden: false);

        [$user, $contact] = $this->linked($customer);
        $resolver = $this->resolver($user, $contact, task: $task);

        self::assertSame($task, $resolver->findTicketOr404($task->getId()));
    }

    public function testFindTicketHidesConnectHiddenTask(): void
    {
        $customer = new Customer();
        $project = $this->project($customer, key: 'EXT', external: true, archived: false);
        $task = $this->task($project, hidden: true); // hidden from portal users

        [$user, $contact] = $this->linked($customer);
        $resolver = $this->resolver($user, $contact, task: $task);

        $this->expectException(NotFoundHttpException::class);
        $resolver->findTicketOr404($task->getId());
    }

    public function testFindTicketRejectsTaskFromForeignProject(): void
    {
        // The contact's own (empty) customer, plus a task living under a
        // DIFFERENT customer's project — must not be reachable.
        $ownCustomer = new Customer();
        $foreignCustomer = new Customer();
        $foreignProject = $this->project($foreignCustomer, key: 'FOR', external: true, archived: false);
        $foreignTask = $this->task($foreignProject, hidden: false);

        [$user, $contact] = $this->linked($ownCustomer);
        $resolver = $this->resolver($user, $contact, task: $foreignTask);

        $this->expectException(NotFoundHttpException::class);
        $resolver->findTicketOr404($foreignTask->getId());
    }

    public function testFeaturesDefaultsTicketsOnEverythingElseOff(): void
    {
        $resolver = $this->resolverForWorkspace(new Workspace()); // no settings

        $features = $resolver->features();
        self::assertTrue($features['tickets']);
        self::assertFalse($features['monitoring']);
        self::assertFalse($features['proposals']);
        // Every canonical key is present.
        self::assertSame(PortalAccessResolver::FEATURE_KEYS, array_keys($features));
    }

    public function testFeaturesReflectWorkspaceSettings(): void
    {
        $ws = (new Workspace())->setSettings(['portal' => ['features' => ['monitoring' => true]]]);
        $resolver = $this->resolverForWorkspace($ws);

        self::assertTrue($resolver->features()['monitoring']);
        self::assertFalse($resolver->features()['social']);
    }

    public function testAssertPortalEnabledThrowsWhenDisabled(): void
    {
        $resolver = $this->resolverForWorkspace(new Workspace()); // enabled not set

        $this->expectException(AccessDeniedHttpException::class);
        $resolver->assertPortalEnabled();
    }

    public function testAssertFeatureEnabledGate(): void
    {
        $ws = (new Workspace())->setSettings(['portal' => ['enabled' => true, 'features' => ['monitoring' => true]]]);
        $resolver = $this->resolverForWorkspace($ws);

        $resolver->assertFeatureEnabled('monitoring'); // enabled → no throw

        $this->expectException(AccessDeniedHttpException::class);
        $resolver->assertFeatureEnabled('social'); // off → 403
    }

    public function testIsPortalEnabledStatic(): void
    {
        self::assertFalse(PortalAccessResolver::isPortalEnabled(new Workspace()));
        self::assertTrue(PortalAccessResolver::isPortalEnabled(
            (new Workspace())->setSettings(['portal' => ['enabled' => true]]),
        ));
    }

    // --- helpers ----------------------------------------------------

    private function resolverForWorkspace(Workspace $workspace): PortalAccessResolver
    {
        $customer = (new Customer())->setWorkspace($workspace);
        $user = new User();
        $contact = (new Contact())->setCustomer($customer)->setLinkedUser($user);
        return $this->resolver($user, $contact);
    }

    /**
     * @return array{0: User, 1: Contact}
     */
    private function linked(Customer $customer): array
    {
        // Customer is workspace-scoped and Contact::setCustomer denormalizes
        // the workspace, so the customer needs one.
        $customer->setWorkspace(new Workspace());
        $user = new User();
        $contact = (new Contact())->setCustomer($customer)->setLinkedUser($user);
        return [$user, $contact];
    }

    private function project(Customer $customer, string $key, bool $external, bool $archived): Project
    {
        $project = (new Project())
            ->setKey($key)
            ->setName($key)
            ->setIsExternal($external)
            ->setIsArchived($archived);
        self::setId($project, Uuid::v7());
        $customer->getProjects()->add($project);
        return $project;
    }

    private function task(Project $project, bool $hidden): Task
    {
        $task = (new Task())
            ->setWorkspace(new Workspace())
            ->setProject($project)
            ->setIdentifier($project->getKey() . '-1')
            ->setIsHiddenForConnectUsers($hidden);
        self::setId($task, Uuid::v7());
        return $task;
    }

    private function resolver(User $user, ?Contact $contact, ?Task $task = null): PortalAccessResolver
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('findOneBy')->willReturn($contact);

        $tasks = $this->createStub(TaskRepository::class);
        $tasks->method('find')->willReturn($task);

        return new PortalAccessResolver($security, $contacts, $tasks);
    }

    private static function setId(object $entity, Uuid $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
