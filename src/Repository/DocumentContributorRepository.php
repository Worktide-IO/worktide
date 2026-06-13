<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentContributor;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentContributor>
 */
class DocumentContributorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentContributor::class);
    }

    public function findContribution(Document $document, User $user): ?DocumentContributor
    {
        return $this->findOneBy(['document' => $document, 'user' => $user]);
    }
}
