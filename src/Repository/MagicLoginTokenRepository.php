<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MagicLoginToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MagicLoginToken>
 */
class MagicLoginTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagicLoginToken::class);
    }

    /**
     * Find a usable token by its SHA-256 hash: unused and not yet expired.
     */
    public function findValidByHash(string $tokenHash, \DateTimeImmutable $now): ?MagicLoginToken
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.tokenHash = :hash')
            ->andWhere('t.usedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('hash', $tokenHash)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Drop any outstanding tokens for a user — called before issuing a fresh
     * one so a user only ever has a single live magic-login link.
     */
    public function deleteForUser(User $user): void
    {
        $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
