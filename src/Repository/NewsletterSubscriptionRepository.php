<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\Newsletter;
use App\Entity\NewsletterSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsletterSubscription>
 */
class NewsletterSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsletterSubscription::class);
    }

    /**
     * UUID strings of the newsletters this contact is subscribed to — the set
     * the portal marks as "on".
     *
     * @return list<string>
     */
    public function subscribedNewsletterIds(Contact $contact): array
    {
        $ids = [];
        foreach ($this->findBy(['contact' => $contact]) as $sub) {
            $id = $sub->getNewsletter()->getId()?->toRfc4122();
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function findOneForContact(Newsletter $newsletter, Contact $contact): ?NewsletterSubscription
    {
        return $this->findOneBy(['newsletter' => $newsletter, 'contact' => $contact]);
    }
}
