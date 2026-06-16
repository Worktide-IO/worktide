<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DocumentRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentRevision>
 */
final class DocumentRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentRevision::class);
    }

    /** @return DocumentRevision[] */
    public function findForDocument(Document $doc, int $limit = 50): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.document = :d')
            ->setParameter('d', $doc)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
