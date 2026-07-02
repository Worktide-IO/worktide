<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Customer;
use App\Entity\Task;
use App\Entity\Workspace;
use App\Service\Search\SearchDocumentFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SearchDocumentFactoryTest extends TestCase
{
    private SearchDocumentFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new SearchDocumentFactory();
    }

    public function testBuildsTaskDocument(): void
    {
        $ws = $this->workspace();
        $wsId = Uuid::v7();
        $this->assignId($ws, $wsId);

        $task = (new Task())
            ->setTitle('Login broken')
            ->setIdentifier('WT-42')
            ->setDescription('500 on submit')
            ->setWorkspace($ws);
        $task->onPrePersistTimestamps();
        $this->assignId($task, Uuid::v7());

        $doc = $this->factory->build($task);

        self::assertNotNull($doc);
        self::assertSame('task', $doc->type);
        self::assertSame('Login broken', $doc->title);
        self::assertStringContainsString('500 on submit', $doc->body);
        self::assertStringContainsString('WT-42', $doc->body);
        self::assertStringStartsWith('/v1/tasks/', $doc->iri);
        self::assertSame($wsId->toRfc4122(), $doc->workspaceId->toRfc4122());
        self::assertStringStartsWith('task-', $doc->meiliId());
    }

    public function testBuildsCustomerDocument(): void
    {
        $ws = $this->workspace();
        $this->assignId($ws, Uuid::v7());

        $customer = (new Customer())->setName('Stadtwerke Kulmbach')->setWorkspace($ws);
        $customer->onPrePersistTimestamps();
        $this->assignId($customer, Uuid::v7());

        $doc = $this->factory->build($customer);

        self::assertNotNull($doc);
        self::assertSame('customer', $doc->type);
        self::assertSame('Stadtwerke Kulmbach', $doc->title);
        self::assertStringStartsWith('/v1/customers/', $doc->iri);
    }

    public function testNonSearchableEntityReturnsNull(): void
    {
        self::assertNull($this->factory->build(new \stdClass()));
    }

    public function testTypeMappingRoundTrip(): void
    {
        self::assertSame('task', $this->factory->typeForClass(Task::class));
        self::assertSame(Task::class, $this->factory->classForType('task'));
        self::assertNull($this->factory->classForType('nope'));
        self::assertContains(Task::class, $this->factory->searchableClasses());
        self::assertContains('conversation', $this->factory->typeSlugs());
    }

    private function workspace(): Workspace
    {
        return new Workspace();
    }

    private function assignId(object $entity, Uuid $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }
}
