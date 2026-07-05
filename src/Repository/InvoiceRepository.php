<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Invoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * The customer's invoices, newest first — for the portal "Rechnungen" tab.
     *
     * @return list<Invoice>
     */
    public function findForPortalCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('i.issuedOn', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Existing invoice for a lexoffice voucher id in a workspace, for upsert. */
    public function findOneByLexofficeId(string $lexofficeId): ?Invoice
    {
        return $this->findOneBy(['lexofficeId' => $lexofficeId]);
    }
}
