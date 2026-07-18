<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\ApiPlatform\Filter\UuidExactFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\ExternalReferenceTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\CustomerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A workspace-scoped customer record — the agency's view of one client.
 *
 * This is *deliberately* not modeled like awork's `Company` entity, which
 * existed only as an FK on Project (no first-class customer DB). In Worktide
 * each Workspace owns a full CRM of Customers; Projects optionally link to a
 * Customer, ServiceSubscriptions hang off CustomerSystems (Phase 3 block 2),
 * and the TYPO3 client portal will read this same table.
 *
 * `isCompany` flips the schema between "company with VAT and legalName" and
 * "private person with first/last name". The flat `addressLine1/2/zip/city/
 * country` fields are good enough for the MVP — a future block can move
 * addresses into a polymorphic Address entity if we need multi-address
 * (billing / shipping / visit).
 *
 * Lifecycle: Prospect → Active → Inactive | Churned → Archived. The status
 * field is informational — no automated transitions yet; an operator
 * patches it as the relationship evolves.
 */
#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customers')]
#[ORM\Index(name: 'customer_workspace_status_idx', columns: ['workspace_id', 'status'])]
#[ORM\Index(name: 'customer_name_idx', columns: ['workspace_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Customer',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    // Auto-publish create/update/delete to the Worktide Mercure hub so the
    // SPA can live-update customer lists without polling. Topic is the
    // IRI of the changed resource; clients subscribe to either the
    // collection IRI for a wildcard match or to a single-customer IRI.
    mercure: true,
)]
#[ApiFilter(UuidExactFilter::class, properties: ['id'])]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'name' => 'partial',
    'legalName' => 'partial',
    'email' => 'partial',
    'status' => 'exact',
    'industry' => 'exact',
    'tags.id' => 'exact',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isCompany', 'isCustomer', 'isVendor', 'portalEnabled'])]
#[ApiFilter(ExistsFilter::class, properties: ['deletedAt'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt', 'updatedAt', 'status'])]
class Customer
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;
    use ExternalReferenceTrait;

    /** Display name — usually the company name; for individuals, "Lastname, Firstname". */
    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $name;

    /** Formal company name for invoices ("Foo GmbH & Co. KG") — only meaningful when isCompany. */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $legalName = null;

    /** Given name — only meaningful when !isCompany (private person). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $firstName = null;

    /** Family name — only meaningful when !isCompany. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column]
    private bool $isCompany = true;

    /**
     * Business-relationship type, mirrored from external systems (e.g. lexoffice
     * roles customer/vendor). Orthogonal to {@see $isCompany} (Firma/Person): a
     * record can be a customer, a vendor, or both. Existing/awork records default
     * to customer.
     */
    #[ORM\Column]
    private bool $isCustomer = true;

    #[ORM\Column]
    private bool $isVendor = false;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $vatId = null;

    /** Human customer number, synced from lexoffice (roles.customer.number). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $customerNumber = null;

    #[ORM\Column(length: 254, nullable: true)]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    private ?string $email = null;

    /**
     * Dedicated address for invoices/billing correspondence, separate from the
     * general contact email above. Null = fall back to the general email.
     */
    #[ORM\Column(length: 254, nullable: true)]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    private ?string $invoiceEmail = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 2048, nullable: true)]
    #[Assert\Url(requireTld: true)]
    private ?string $website = null;

    #[ORM\ManyToOne(targetEntity: Industry::class)]
    #[ORM\JoinColumn(name: 'industry_id', nullable: true, onDelete: 'SET NULL')]
    private ?Industry $industry = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $addressLine1 = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $addressLine2 = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $zip = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    /** ISO 3166-1 alpha-2 (e.g. "DE", "AT") — optional, stored uppercase. */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(length: 16, enumType: CustomerStatus::class)]
    private CustomerStatus $status = CustomerStatus::Active;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /**
     * Trailing-12-months invoiced revenue in cents, synced from lexoffice
     * ({@see \App\Command\LexofficeSyncRevenueCommand}). Feeds the customer-value
     * component of the internal priority score. Null = not synced yet.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $revenueCents = null;

    /**
     * Per-customer portal SLA override ({priority: {response, resolution}} hours),
     * layered over the workspace default. Null = inherit workspace/defaults.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $slaPolicy = null;

    /** @return array<string, mixed> */
    public function getSlaPolicy(): array { return $this->slaPolicy ?? []; }

    /** @param array<string, mixed>|null $p */
    public function setSlaPolicy(?array $p): self { $this->slaPolicy = $p === null || $p === [] ? null : $p; return $this; }

    /**
     * Per-customer portal "Freischaltung". The customer's portal contacts may
     * only obtain a login (and keep a live session) while this is true — enforced
     * at the firewall by {@see \App\Security\PortalUserChecker}, which blocks JWT
     * issuance and every authenticated request for a ROLE_PORTAL user whose
     * customer is not enabled. Opt-in: default false, flipped by staff from the
     * customer detail view (Patch is guarded by EDIT on the workspace).
     *
     * Orthogonal to the workspace-wide `settings.portal.enabled` toggle, which
     * gates the /v1/portal/* endpoints themselves (see {@see \App\Service\Portal\PortalAccessResolver}).
     */
    #[ORM\Column]
    private bool $portalEnabled = false;

    public function isPortalEnabled(): bool { return $this->portalEnabled; }
    public function setPortalEnabled(bool $v): self { $this->portalEnabled = $v; return $this; }

    /**
     * UUIDs of the Newsletter tree nodes this customer is granted ("einzeln
     * freigeschaltet"). Only these appear in the customer's portal newsletter
     * tree, where its contacts opt in/out per node. Mirrors the
     * {@see Contact::$portalHiddenFeatures} JSON-list convention (null when empty).
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $enabledNewsletterIds = null;

    /** @return list<string> */
    public function getEnabledNewsletterIds(): array { return $this->enabledNewsletterIds ?? []; }

    /** @param list<string>|null $ids */
    public function setEnabledNewsletterIds(?array $ids): self
    {
        $this->enabledNewsletterIds = $ids === null || $ids === []
            ? null
            : array_values(array_unique($ids));

        return $this;
    }

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revenueSyncedAt = null;

    /** Picks the primary engagement owner / account manager for this customer. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $accountManager = null;

    /**
     * Customers may be tagged ("retainer", "vip", "ngo") for filtering. We reuse
     * the workspace-wide Tag entity (scope=customer) rather than a Customer-only
     * tag schema; that way Project / Task / Customer share one tag pool.
     *
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'customer_tag_map')]
    private Collection $tags;

    /** @var Collection<int, Contact> */
    #[ORM\OneToMany(targetEntity: Contact::class, mappedBy: 'customer', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $contacts;

    /** @var Collection<int, Project> */
    #[ORM\OneToMany(targetEntity: Project::class, mappedBy: 'customer')]
    private Collection $projects;

    /**
     * Concluded/ongoing contracts per type (SLA, AV, NDA, …). Read-only here —
     * managed via the CustomerAgreement resource and the slug convenience
     * endpoint; exposed so a single Customer GET surfaces the agreement overview.
     *
     * @var Collection<int, CustomerAgreement>
     */
    #[ORM\OneToMany(targetEntity: CustomerAgreement::class, mappedBy: 'customer')]
    private Collection $agreements;

    /** @var Collection<int, SocialProfile> */
    #[ORM\OneToMany(targetEntity: SocialProfile::class, mappedBy: 'customer', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $socialProfiles;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
        $this->contacts = new ArrayCollection();
        $this->socialProfiles = new ArrayCollection();
        $this->projects = new ArrayCollection();
        $this->agreements = new ArrayCollection();
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getLegalName(): ?string { return $this->legalName; }
    public function setLegalName(?string $v): self { $this->legalName = $v; return $this; }

    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(?string $v): self { $this->firstName = $v !== null ? trim($v) : null; return $this; }

    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $v): self { $this->lastName = $v !== null ? trim($v) : null; return $this; }

    /**
     * Keep the display {@see self::$name} consistent for private persons:
     * "Lastname, Firstname" derived from the structured fields. Companies keep
     * their explicitly-set name. Runs on write.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function syncDisplayName(): void
    {
        if ($this->isCompany) {
            return;
        }
        $parts = array_filter([trim((string) $this->lastName), trim((string) $this->firstName)], static fn (string $s) => $s !== '');
        if ($parts !== []) {
            $this->name = implode(', ', $parts);
        }
    }

    public function isCompany(): bool { return $this->isCompany; }
    public function setIsCompany(bool $v): self { $this->isCompany = $v; return $this; }

    public function isCustomer(): bool { return $this->isCustomer; }
    public function setIsCustomer(bool $v): self { $this->isCustomer = $v; return $this; }

    public function isVendor(): bool { return $this->isVendor; }
    public function setIsVendor(bool $v): self { $this->isVendor = $v; return $this; }

    public function getVatId(): ?string { return $this->vatId; }
    public function setVatId(?string $v): self { $this->vatId = $v; return $this; }

    public function getCustomerNumber(): ?string { return $this->customerNumber; }
    public function setCustomerNumber(?string $v): self { $this->customerNumber = $v; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $v): self { $this->email = $v === null ? null : mb_strtolower(trim($v)); return $this; }

    public function getInvoiceEmail(): ?string { return $this->invoiceEmail; }
    public function setInvoiceEmail(?string $v): self { $this->invoiceEmail = $v === null ? null : mb_strtolower(trim($v)); return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $v): self { $this->phone = $v; return $this; }

    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $v): self { $this->website = $v; return $this; }

    public function getIndustry(): ?Industry { return $this->industry; }
    public function setIndustry(?Industry $v): self { $this->industry = $v; return $this; }

    public function getAddressLine1(): ?string { return $this->addressLine1; }
    public function setAddressLine1(?string $v): self { $this->addressLine1 = $v; return $this; }

    public function getAddressLine2(): ?string { return $this->addressLine2; }
    public function setAddressLine2(?string $v): self { $this->addressLine2 = $v; return $this; }

    public function getZip(): ?string { return $this->zip; }
    public function setZip(?string $v): self { $this->zip = $v; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $v): self { $this->city = $v; return $this; }

    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $v): self { $this->country = $v === null ? null : strtoupper($v); return $this; }

    public function getStatus(): CustomerStatus { return $this->status; }
    public function setStatus(CustomerStatus $s): self { $this->status = $s; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): self { $this->notes = $v; return $this; }

    public function getRevenueCents(): ?int { return $this->revenueCents; }
    public function setRevenueCents(?int $v): self { $this->revenueCents = $v; return $this; }

    public function getRevenueSyncedAt(): ?\DateTimeImmutable { return $this->revenueSyncedAt; }
    public function setRevenueSyncedAt(?\DateTimeImmutable $v): self { $this->revenueSyncedAt = $v; return $this; }

    public function getAccountManager(): ?User { return $this->accountManager; }
    public function setAccountManager(?User $u): self { $this->accountManager = $u; return $this; }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection { return $this->tags; }
    public function addTag(Tag $tag): self
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
        return $this;
    }
    public function removeTag(Tag $tag): self { $this->tags->removeElement($tag); return $this; }

    /** @return Collection<int, Contact> */
    public function getContacts(): Collection { return $this->contacts; }

    /** @return Collection<int, SocialProfile> */
    public function getSocialProfiles(): Collection { return $this->socialProfiles; }

    public function addSocialProfile(SocialProfile $profile): self
    {
        if (!$this->socialProfiles->contains($profile)) {
            $this->socialProfiles->add($profile);
            $profile->setCustomer($this);
        }
        return $this;
    }

    public function removeSocialProfile(SocialProfile $profile): self
    {
        $this->socialProfiles->removeElement($profile);
        return $this;
    }

    /** @return Collection<int, Project> */
    public function getProjects(): Collection { return $this->projects; }

    /** @return Collection<int, CustomerAgreement> */
    public function getAgreements(): Collection { return $this->agreements; }
}
