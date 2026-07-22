<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\Enum\ProductShareStatus;
use App\Entity\Product;
use App\Entity\ProductShare;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles acceptance of a cross-workspace product share. No copy is created —
 * the target workspace simply gains view access to the shared instance. The
 * source workspace retains full ownership and edit rights.
 */
final class ProductShareService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function accept(ProductShare $share): Product
    {
        $share->setStatus(ProductShareStatus::Accepted);
        $this->em->flush();

        return $share->getProduct();
    }
}
