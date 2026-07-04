<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgreementType;
use App\Entity\Customer;
use App\Entity\CustomerAgreement;
use App\Entity\Enum\AgreementStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomerAgreement>
 */
class CustomerAgreementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerAgreement::class);
    }

    public function findOneForType(Customer $customer, AgreementType $type): ?CustomerAgreement
    {
        return $this->findOneBy(['customer' => $customer, 'type' => $type]);
    }

    /**
     * Agreements visible in a customer's portal: their non-deleted agreements
     * that have progressed past the empty `None` placeholder (drafts, offers,
     * signed contracts, expired/terminated). Signed first, then by type.
     * The caller passes the portal contact's OWN customer (authorization there).
     *
     * @return list<CustomerAgreement>
     */
    public function findForPortalCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.customer = :customer')
            ->andWhere('a.status != :none')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('customer', $customer)
            ->setParameter('none', AgreementStatus::None)
            ->orderBy('a.signedOn', 'DESC')
            ->addOrderBy('a.typeSlug', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Heads whose in-force version has lapsed — used by the expiry command.
     *
     * @return list<CustomerAgreement>
     */
    public function findExpiring(\DateTimeImmutable $asOf): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status = :signed')
            ->andWhere('a.validUntil IS NOT NULL')
            ->andWhere('a.validUntil < :asOf')
            ->setParameter('signed', AgreementStatus::Signed)
            ->setParameter('asOf', $asOf)
            ->getQuery()
            ->getResult();
    }
}
