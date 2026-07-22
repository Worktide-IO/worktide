<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Prevents modification or deletion of Product entities that were shared
 * from another workspace. Only the source (owning) workspace may edit/delete.
 */
#[AsDoctrineListener(Events::preUpdate)]
#[AsDoctrineListener(Events::preRemove)]
final class SharedProductWriteGuard
{
    public function __construct() {}

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Product) {
            return;
        }
        $this->guard($entity);
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Product) {
            return;
        }
        $this->guard($entity);
    }

    private function guard(Product $product): void
    {
        $sourceWs = $product->getSourceWorkspace();
        if ($sourceWs === null) {
            return;
        }

        $ownerWs = $product->getWorkspace();
        if ($ownerWs->getId()->equals($sourceWs->getId())) {
            return;
        }

        throw new AccessDeniedHttpException('Shared products can only be edited by the owning workspace.');
    }
}
