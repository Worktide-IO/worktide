<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\NewsletterConsentSource;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\NewsletterSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One portal contact's opt-in to one {@see Newsletter} node, plus the consent
 * audit trail. A row with `revokedAt IS NULL` = currently subscribed; a row with
 * `revokedAt` set = withdrawn but retained as proof of when/how the contact once
 * consented and when they opted out. The UNIQUE(newsletter, contact) constraint
 * means there is exactly one row per pair — (un)subscribe toggles `revokedAt`
 * rather than inserting/deleting, so re-subscribing reactivates the same row.
 *
 * `consentedAt`/`consentSource` capture the moment and origin of the (latest)
 * opt-in. Because the withdrawal is soft, this is the record a data-protection
 * request or a "who agreed to receive this" report reads from.
 *
 * Not an API resource — managed only through the portal newsletter action
 * (subscribe/unsubscribe) and the token unsubscribe endpoint, which also enforce
 * that the node is granted to the contact's customer. Kept as a queryable join
 * (rather than a JSON list on Contact) so a future "send this newsletter to its
 * subscribers" job can select active recipients directly.
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

    /** When the (currently effective) opt-in was given. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $consentedAt;

    /** How that opt-in originated. */
    #[ORM\Column(length: 20, enumType: NewsletterConsentSource::class)]
    private NewsletterConsentSource $consentSource = NewsletterConsentSource::Portal;

    /** Set when the contact opts out; null = still subscribed. Row is kept for audit. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct()
    {
        $this->consentedAt = new \DateTimeImmutable();
    }

    public function getConsentedAt(): \DateTimeImmutable
    {
        return $this->consentedAt;
    }

    public function getConsentSource(): NewsletterConsentSource
    {
        return $this->consentSource;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }

    /**
     * Record (or renew) consent: stamps a fresh consentedAt, sets the origin and
     * clears any prior revocation. Used both on first subscribe and re-subscribe.
     */
    public function grantConsent(NewsletterConsentSource $source): self
    {
        $this->consentedAt = new \DateTimeImmutable();
        $this->consentSource = $source;
        $this->revokedAt = null;

        return $this;
    }

    /** Soft opt-out: keep the row, stamp the withdrawal. Idempotent. */
    public function revoke(): self
    {
        if ($this->revokedAt === null) {
            $this->revokedAt = new \DateTimeImmutable();
        }

        return $this;
    }

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
