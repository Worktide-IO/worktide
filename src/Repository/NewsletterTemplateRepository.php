<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NewsletterTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsletterTemplate>
 */
class NewsletterTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsletterTemplate::class);
    }
}
