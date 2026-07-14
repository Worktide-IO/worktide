<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\SubscriptionStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\ExternalReferenceTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ServiceAssignmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer's subscription to one {@see ServiceVersion} — the many-to-many
 * link (a version is assignable to many customers) carrying the per-link terms:
 * start/end, status, notes, and an optional net-price override.
 *
 * Price defaults to the version's {@see ServiceVersion::$netPriceCents}; the
 * backend prefills it from the version so operators rarely type it. Set
 * {@see self::$netPriceOverrideCents} only for a customer-specific price;
 * {@see self::effectivePriceCents()} resolves the two.
 *
 * `nextBillingOn` is auto-recomputed (prePersist/preUpdate) from
 * (startedOn, version billing cycle, status, endedOn). Replaces the former
 * flat ServiceSubscription entity.
 */
#[ORM\Entity(repositoryClass: ServiceAssignmentRepository::class)]
#[ORM\Table(name: 'service_assignments')]
#[ORM\Index(name: 'svc_assign_customer_idx', columns: ['customer_id'])]
#[ORM\Index(name: 'svc_assign_system_idx', columns: ['system_id'])]
#[ORM\Index(name: 'svc_assign_version_idx', columns: ['service_version_id'])]
#[ORM\Index(name: 'svc_assign_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'svc_assign_status_idx', columns: ['status'])]
#[ORM\Index(name: 'svc_assign_next_billing_idx', columns: ['next_billing_on'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ServiceAssignment',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'customer' => 'exact',
    'system' => 'exact',
    'serviceVersion' => 'exact',
    'serviceVersion.service' => 'exact',
    'status' => 'exact',
])]
#[ApiFilter(BooleanFilter::class, properties: ['autoRenew'])]
#[ApiFilter(OrderFilter::class, properties: ['startedOn', 'nextBillingOn'])]
class ServiceAssignment
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
     * Optional — null means the assignment belongs to the customer as a whole
     * rather than one specific system.
     */
    #[ORM\ManyToOne(inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CustomerSystem $system = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'service_version_id', nullable: false, onDelete: 'RESTRICT')]
    private ServiceVersion $serviceVersion;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startedOn;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedOn = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 16, enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status = SubscriptionStatus::Active;

    #[ORM\Column]
    private bool $autoRenew = true;

    /** Customer-specific net price in cents; null inherits the version's price. */
    #[ORM\Column(nullable: true)]
    private ?int $netPriceOverrideCents = null;

    /**
     * Computed by computeNextBilling() in prePersist / preUpdate. Stored so the
     * upcoming-billing queries can index this column.
     */
    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextBillingOn = null;

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

    public function getServiceVersion(): ServiceVersion { return $this->serviceVersion; }
    public function setServiceVersion(ServiceVersion $v): self { $this->serviceVersion = $v; return $this; }

    public function getStartedOn(): \DateTimeImmutable { return $this->startedOn; }
    public function setStartedOn(\DateTimeImmutable $d): self { $this->startedOn = $d; return $this; }

    public function getEndedOn(): ?\DateTimeImmutable { return $this->endedOn; }
    public function setEndedOn(?\DateTimeImmutable $d): self { $this->endedOn = $d; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }

    public function getStatus(): SubscriptionStatus { return $this->status; }
    public function setStatus(SubscriptionStatus $s): self
    {
        $this->status = $s;
        // Auto-stamp endedOn on cancellation — keeps the timeline consistent
        // without forcing a second field flip.
        if ($s === SubscriptionStatus::Cancelled && $this->endedOn === null) {
            $this->endedOn = new \DateTimeImmutable('today');
        }
        return $this;
    }

    public function isAutoRenew(): bool { return $this->autoRenew; }
    public function setAutoRenew(bool $v): self { $this->autoRenew = $v; return $this; }

    public function getNetPriceOverrideCents(): ?int { return $this->netPriceOverrideCents; }
    public function setNetPriceOverrideCents(?int $c): self { $this->netPriceOverrideCents = $c; return $this; }

    public function getNextBillingOn(): ?\DateTimeImmutable { return $this->nextBillingOn; }

    /** The net price actually charged: the override if set, else the version's price. */
    #[ApiProperty(writable: false)]
    public function getEffectivePriceCents(): int
    {
        return $this->netPriceOverrideCents ?? $this->serviceVersion->getNetPriceCents();
    }

    /** Annualised effective net price in cents — for MRR / ARR dashboards. */
    public function annualPriceCents(): int
    {
        return $this->getEffectivePriceCents() * $this->serviceVersion->getBillingCycle()->annualMultiplier();
    }

    /**
     * Refreshes nextBillingOn from the current (startedOn, cycle, status,
     * endedOn) tuple. Idempotent — runs on every save.
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

        $cycle = $this->serviceVersion->getBillingCycle();
        $today = new \DateTimeImmutable('today');

        if (!$cycle->isRecurring()) {
            // One-off — bills exactly once, on startedOn (if not yet past).
            $this->nextBillingOn = $this->startedOn >= $today ? $this->startedOn : null;
            return;
        }

        // Recurring: walk forward from startedOn in cycle-sized steps until we
        // pass "today". DateInterval addition handles month-end edge cases.
        $cursor = $this->startedOn;
        $interval = $cycle->dateInterval();
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
