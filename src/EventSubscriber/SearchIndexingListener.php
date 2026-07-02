<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Message\SyncSearchIndexMessage;
use App\Service\Search\SearchDocumentFactory;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Keeps the search index in step with entity changes. Collects affected
 * searchable entities during a flush, then dispatches one
 * {@see SyncSearchIndexMessage} per entity in postFlush (after commit) — so the
 * async worker only ever sees committed state.
 *
 * Idle unless SEARCH_PROVIDER=meilisearch (MySQL reads the DB live and needs no
 * index), which is why the provider name is injected as a scalar rather than the
 * provider service — no dependency cycle with the EntityManager, no queue spam.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
final class SearchIndexingListener
{
    /** @var array<string, array{string, Uuid, bool}> keyed by "type-id" to de-dupe */
    private array $pending = [];

    private readonly bool $enabled;

    public function __construct(
        private readonly SearchDocumentFactory $factory,
        private readonly MessageBusInterface $bus,
        string $searchProvider,
    ) {
        $this->enabled = strtolower(trim($searchProvider)) === 'meilisearch';
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->collect($args->getObject(), false);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->collect($args->getObject(), false);
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->collect($args->getObject(), true);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === []) {
            return;
        }
        $items = $this->pending;
        $this->pending = [];

        foreach ($items as [$type, $id, $delete]) {
            $this->bus->dispatch(new SyncSearchIndexMessage($type, $id, $delete));
        }
    }

    private function collect(object $entity, bool $delete): void
    {
        if (!$this->enabled) {
            return;
        }
        $type = $this->factory->typeForClass($entity::class);
        if ($type === null) {
            return;
        }
        $id = method_exists($entity, 'getId') ? $entity->getId() : null;
        if (!$id instanceof Uuid) {
            return;
        }
        $this->pending[$type . '-' . $id->toRfc4122()] = [$type, $id, $delete];
    }
}
