<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\BillingCycle;
use App\Entity\Enum\SubscriptionStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\ExternalReferenceTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ServiceSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * LEGACY — superseded by the {@see Service} / {@see ServiceVersion} /
 * {@see ServiceAssignment} catalogue. This entity is retained as a plain,
 * API-less Doctrine mapping ONLY so the one-off data migration
 * (app:oneoff:migrate-subscriptions-to-services) can read the old rows on each
 * environment. Once stage + prod have migrated, a follow-up drops the
 * service_subscriptions table and deletes this class + its repository.
 *
 * Do NOT write to this entity — the app reads and writes ServiceAssignment now.
 */
#[ORM\Entity(repositoryClass: ServiceSubscriptionRepository::class)]
#[ORM\Table(name: 'service_subscriptions')]
#[ORM\Index(name: 'svc_sub_customer_idx', columns: ['customer_id'])]
#[ORM\Index(name: 'svc_sub_system_idx', columns: ['system_id'])]
#[ORM\Index(name: 'svc_sub_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'svc_sub_status_idx', columns: ['status'])]
#[ORM\Index(name: 'svc_sub_next_billing_idx', columns: ['next_billing_on'])]
#[ORM\HasLifecycleCallbacks]
class ServiceSubscription
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;
    use ExternalReferenceTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    /**
     * Optional — when null, the subscription belongs to the customer as a
     * whole rather than one specific system. Use cases:
     *   - system-bound:   hosting for the Acme TYPO3 instance
     *   - customer-wide:  monthly support retainer covering every Acme site
     */
    // Inverse side dropped (CustomerSystem now exposes ServiceAssignments). This
    // legacy entity is retained read-only until the data migration to
    // ServiceAssignment has run, then removed.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CustomerSystem $system = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Price for ONE billing cycle, in the smallest currency unit (cents/Rappen/etc). */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $priceCents = 0;

    /** ISO 4217 alpha-3 in lowercase ("eur", "chf", "usd"). */
    #[ORM\Column(length: 3)]
    #[Assert\Length(min: 3, max: 3)]
    private string $currency = 'eur';

    #[ORM\Column(length: 16, enumType: BillingCycle::class)]
    private BillingCycle $billingCycle = BillingCycle::Monthly;

    #[ORM\Column(length: 16, enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status = SubscriptionStatus::Active;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startedOn;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedOn = null;

    #[ORM\Column]
    private bool $autoRenew = true;

    /**
     * Computed by computeNextBilling() in prePersist / preUpdate. Stored so
     * the upcoming-billing queries (and the planned dunning command) can
     * index this column instead of recomputing on every row.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextBillingOn = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->startedOn = new \DateTimeImmutable('today');
    }

    public function getCustomer(): Customer { return $this->customer; }
    public function setCustomer(Customer $c): self
    {
        $this->customer = $c;
        $this->setWorkspace($c->getWorkspace());
        return $this;
    }

    public function getSystem(): ?CustomerSystem { return $this->system; }
    public function setSystem(?CustomerSystem $s): self { $this->system = $s; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $n): self { $this->name = $n; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }

    public function getPriceCents(): int { return $this->priceCents; }
    public function setPriceCents(int $c): self { $this->priceCents = $c; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $c): self { $this->currency = strtolower(trim($c)); return $this; }

    public function getBillingCycle(): BillingCycle { return $this->billingCycle; }
    public function setBillingCycle(BillingCycle $c): self { $this->billingCycle = $c; return $this; }

    public function getStatus(): SubscriptionStatus { return $this->status; }
    public function setStatus(SubscriptionStatus $s): self
    {
        $previous = $this->status ?? null;
        $this->status = $s;
        // Auto-stamp endedOn when the operator cancels — keeps the timeline
        // consistent without forcing them to also flip a second field.
        if ($s === SubscriptionStatus::Cancelled && $this->endedOn === null) {
            $this->endedOn = new \DateTimeImmutable('today');
        }
        return $this;
    }

    public function getStartedOn(): \DateTimeImmutable { return $this->startedOn; }
    public function setStartedOn(\DateTimeImmutable $d): self { $this->startedOn = $d; return $this; }

    public function getEndedOn(): ?\DateTimeImmutable { return $this->endedOn; }
    public function setEndedOn(?\DateTimeImmutable $d): self { $this->endedOn = $d; return $this; }

    public function isAutoRenew(): bool { return $this->autoRenew; }
    public function setAutoRenew(bool $v): self { $this->autoRenew = $v; return $this; }

    public function getNextBillingOn(): ?\DateTimeImmutable { return $this->nextBillingOn; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): self { $this->notes = $v; return $this; }

    /**
     * Annualised price in cents — Yearly * 1, HalfYearly * 2, Monthly * 12,
     * Once * 1. Convenient for revenue dashboards that want MRR / ARR.
     */
    public function annualPriceCents(): int
    {
        return $this->priceCents * $this->billingCycle->annualMultiplier();
    }

    /**
     * Refreshes nextBillingOn from the current (startedOn, cycle, status,
     * endedOn) tuple. Idempotent — Doctrine prePersist / preUpdate runs
     * this on every save.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function computeNextBilling(): void
    {
        if (
            $this->status !== SubscriptionStatus::Active
            && $this->status !== SubscriptionStatus::Trial
        ) {
            $this->nextBillingOn = null;
            return;
        }
        if (!$this->billingCycle->isRecurring()) {
            // One-off — bills exactly once, on startedOn (if not yet past).
            $today = new \DateTimeImmutable('today');
            $this->nextBillingOn = $this->startedOn >= $today ? $this->startedOn : null;
            return;
        }

        // Recurring: walk forward from startedOn in cycle-sized steps until
        // we pass "today". DateInterval addition handles month-end edge
        // cases ("Jan 31 + 1 month = Feb 28/29") the way PHP normally does.
        $today = new \DateTimeImmutable('today');
        $cursor = $this->startedOn;
        $interval = $this->billingCycle->dateInterval();
        $maxSteps = 1200; // ~100 years monthly — defensive guard
        while ($cursor < $today && $maxSteps-- > 0) {
            $cursor = $cursor->add($interval);
        }

        if ($this->endedOn !== null && $cursor > $this->endedOn) {
            $this->nextBillingOn = null;
            return;
        }
        $this->nextBillingOn = $cursor;
    }
}
