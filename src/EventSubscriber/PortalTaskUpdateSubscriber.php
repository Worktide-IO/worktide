<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes a Mercure update to `{ws}/portal/tickets` whenever a Task
 * belonging to an external (customer-visible) project is created, updated,
 * or removed — so the portal SPA can auto-refresh its ticket list.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class PortalTaskUpdateSubscriber
{
    /** @var list<string> */
    private array $dirty = [];

    public function __construct(
        private readonly HubInterface $hub,
    ) {}

    public function onFlush(OnFlushEventArgs $event): void
    {
        $uow = $event->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Task) {
                $this->collect($entity);
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Task) {
                $this->collect($entity);
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Task) {
                $this->collect($entity);
            }
        }
    }

    private function collect(Task $task): void
    {
        $wsId = $task->getWorkspace()?->getId()?->toRfc4122();
        if ($wsId !== null && $task->getProject()?->isExternal()) {
            $this->dirty[] = $wsId;
        }
    }

    public function postFlush(): void
    {
        if ($this->dirty === []) {
            return;
        }

        $seen = [];
        foreach (\array_unique($this->dirty) as $wsId) {
            if (isset($seen[$wsId])) {
                continue;
            }
            $seen[$wsId] = true;
            $this->hub->publish(new Update(
                topics: [$wsId . '/portal/tickets'],
                data: json_encode(['action' => 'updated']) ?: '{}',
                private: true,
            ));
        }

        $this->dirty = [];
    }
}
