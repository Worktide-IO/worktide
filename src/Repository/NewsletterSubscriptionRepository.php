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

    /**
     * Recipient list for a newsletter send: active, emailable contacts opted
     * into `$newsletter` whose customer STILL has the node granted (the grant
     * lives in the JSON `Customer.enabledNewsletterIds`, so that leg is filtered
     * in PHP — recipient counts are modest).
     *
     * @return list<Contact>
     */
    public function findActiveRecipientsForNewsletter(Newsletter $newsletter): array
    {
        $nodeId = $newsletter->getId()?->toRfc4122();
        if ($nodeId === null) {
            return [];
        }

        /** @var list<NewsletterSubscription> $subs */
        $subs = $this->createQueryBuilder('s')
            ->join('s.contact', 'c')->addSelect('c')
            ->join('c.customer', 'cu')->addSelect('cu')
            ->andWhere('s.newsletter = :nl')
            ->andWhere('c.isActive = true')
            ->andWhere("c.email IS NOT NULL AND c.email != ''")
            ->setParameter('nl', $newsletter)
            ->getQuery()
            ->getResult();

        $recipients = [];
        foreach ($subs as $sub) {
            $contact = $sub->getContact();
            if (\in_array($nodeId, $contact->getCustomer()->getEnabledNewsletterIds(), true)) {
                $recipients[] = $contact;
            }
        }

        return $recipients;
    }
}
