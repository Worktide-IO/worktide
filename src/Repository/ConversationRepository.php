<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\Conversation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function findByThreadKey(Channel $channel, string $threadKey): ?Conversation
    {
        return $this->findOneBy(['channel' => $channel, 'threadKey' => $threadKey]);
    }

    /**
     * Every customer-less conversation for a Zabbix host on this channel —
     * threadKey shape is "zabbix:<hostId>:<triggerId>". Used to back-fill the
     * customer onto all of a host's threads the moment it is assigned.
     *
     * @return list<Conversation>
     */
    public function findZabbixByHostWithoutCustomer(Channel $channel, string $hostId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.channel = :channel')
            ->andWhere('c.customer IS NULL')
            ->andWhere('c.threadKey LIKE :prefix')
            ->setParameter('channel', $channel)
            ->setParameter('prefix', 'zabbix:' . $hostId . ':%')
            ->getQuery()
            ->getResult();
    }
}
