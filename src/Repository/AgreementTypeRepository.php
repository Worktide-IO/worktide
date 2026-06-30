<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgreementType;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgreementType>
 */
class AgreementTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgreementType::class);
    }

    public function findOneBySlug(Workspace $workspace, string $slug): ?AgreementType
    {
        return $this->findOneBy(['workspace' => $workspace, 'slug' => strtolower(trim($slug))]);
    }
}
