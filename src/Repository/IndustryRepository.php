<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Industry;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Industry>
 */
class IndustryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Industry::class);
    }

    public function findOneByName(Workspace $workspace, string $name): ?Industry
    {
        return $this->findOneBy(['workspace' => $workspace, 'name' => trim($name)]);
    }

    /** Find an industry by name in the workspace, creating it if missing (not flushed). */
    public function findOrCreate(Workspace $workspace, string $name): Industry
    {
        $name = trim($name);
        $existing = $this->findOneByName($workspace, $name);
        if ($existing !== null) {
            return $existing;
        }
        $industry = (new Industry())->setName($name);
        $industry->setWorkspace($workspace);
        $this->getEntityManager()->persist($industry);

        return $industry;
    }
}
