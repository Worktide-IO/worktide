<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\NewsletterSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One portal contact's opt-in to one {@see Newsletter} node. Presence of a row =
 * subscribed; absence = not subscribed (opt-in by default). The
 * UNIQUE(newsletter, contact) constraint makes subscribe idempotent.
 *
 * Not an API resource — managed only through the portal newsletter action
 * (subscribe/unsubscribe), which also enforces that the node is granted to the
 * contact's customer. Kept as a queryable join (rather than a JSON list on
 * Contact) so a future "send this newsletter to its subscribers" job can select
 * recipients directly.
 */
#[ORM\Entity(repositoryClass: NewsletterSubscriptionRepository::class)]
#[ORM\Table(name: 'newsletter_subscriptions')]
#[ORM\UniqueConstraint(name: 'newsletter_sub_newsletter_contact_uniq', columns: ['newsletter_id', 'contact_id'])]
#[ORM\Index(name: 'newsletter_sub_newsletter_idx', columns: ['newsletter_id'])]
#[ORM\Index(name: 'newsletter_sub_contact_idx', columns: ['contact_id'])]
#[ORM\HasLifecycleCallbacks]
class NewsletterSubscription
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Newsletter $newsletter;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Contact $contact;

    public function getNewsletter(): Newsletter
    {
        return $this->newsletter;
    }

    public function setNewsletter(Newsletter $newsletter): self
    {
        $this->newsletter = $newsletter;
        // Subscription lives in the newsletter's workspace.
        $this->setWorkspace($newsletter->getWorkspace());

        return $this;
    }

    public function getContact(): Contact
    {
        return $this->contact;
    }

    public function setContact(Contact $contact): self
    {
        $this->contact = $contact;

        return $this;
    }
}
