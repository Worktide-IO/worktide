<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserChatWebhook;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserChatWebhook>
 */
class UserChatWebhookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserChatWebhook::class);
    }

    public function findOneByUser(User $user): ?UserChatWebhook
    {
        return $this->findOneBy(['user' => $user]);
    }
}
