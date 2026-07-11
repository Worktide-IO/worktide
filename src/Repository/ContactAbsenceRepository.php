<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\ContactAbsence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactAbsence>
 */
class ContactAbsenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactAbsence::class);
    }

    /**
     * A contact's own absences (newest first) — the portal self-service list.
     *
     * @return list<ContactAbsence>
     */
    public function findForContact(Contact $contact): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.contact = :contact')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('contact', $contact)
            ->orderBy('a.startsOn', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
