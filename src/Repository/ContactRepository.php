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
     *
     * Matches across ALL of a contact's {@see \App\Entity\ContactEmail} rows
     * (not just the primary), so a sender using a secondary address still
     * resolves. The legacy `Contact.email` column is covered too, since it
     * mirrors the primary — but a LEFT JOIN keeps contacts that predate the
     * multi-email backfill matchable via the column.
     */
    public function findOneByWorkspaceAndEmail(Workspace $workspace, string $email): ?Contact
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.emails', 'ce')
            ->andWhere('c.workspace = :workspace')->setParameter('workspace', $workspace)
            ->andWhere('LOWER(c.email) = LOWER(:email) OR LOWER(ce.address) = LOWER(:email)')
            ->setParameter('email', $email)
            ->andWhere('c.deletedAt IS NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
