<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PersonalAccessToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersonalAccessToken>
 */
class PersonalAccessTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonalAccessToken::class);
    }

    public function findByPlaintext(string $plaintext): ?PersonalAccessToken
    {
        return $this->findOneBy(['tokenHash' => hash('sha256', $plaintext)]);
    }
}
