<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\ExternalIdentity;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalIdentity>
 */
class ExternalIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalIdentity::class);
    }

    /**
     * Resolve the Worktide user explicitly mapped to an external account id on
     * this channel, or null when there is no explicit mapping.
     */
    public function findUserByExternalUserId(Channel $channel, string $externalUserId): ?User
    {
        $identity = $this->findOneBy([
            'channel' => $channel,
            'externalUserId' => $externalUserId,
        ]);

        return $identity?->getUser();
    }
}
