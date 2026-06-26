<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    /**
     * Active contact in the workspace whose email matches (case-insensitive), or
     * null. Used by {@see \App\Service\Inbound\ContactResolver} to auto-resolve
     * an inbound sender onto a known customer contact.
     */
    public function findOneByWorkspaceAndEmail(Workspace $workspace, string $email): ?Contact
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.workspace = :workspace')->setParameter('workspace', $workspace)
            ->andWhere('LOWER(c.email) = LOWER(:email)')->setParameter('email', $email)
            ->andWhere('c.deletedAt IS NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
