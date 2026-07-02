<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncSearchIndexMessage;
use App\Service\Search\SearchDocumentFactory;
use App\Service\Search\SearchProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Applies one search-index change (off the `search` transport). Re-loads the
 * entity by id so it always indexes current state; a missing or soft-deleted
 * entity (build returns null) is removed from the index. In MySQL mode the
 * provider's index/delete are no-ops, so this handler is effectively idle.
 */
#[AsMessageHandler]
final class SyncSearchIndexHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SearchProviderInterface $provider,
        private readonly SearchDocumentFactory $factory,
    ) {}

    public function __invoke(SyncSearchIndexMessage $message): void
    {
        $type = $message->getType();
        $id = $message->getId();

        if ($message->isDelete()) {
            $this->provider->delete($type, $id);

            return;
        }

        $class = $this->factory->classForType($type);
        if ($class === null) {
            return;
        }

        $entity = $this->em->find($class, $id);
        $document = $entity !== null ? $this->factory->build($entity) : null;

        if ($document === null) {
            // Gone or soft-deleted → ensure it is not left in the index.
            $this->provider->delete($type, $id);

            return;
        }

        $this->provider->index($document);
    }
}
