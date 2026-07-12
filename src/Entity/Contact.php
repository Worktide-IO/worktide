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
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\ExternalReferenceTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TaggableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ContactRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A person at a Customer — the "Ansprechpartner" in German agency parlance.
 *
 * Unlike awork's ProjectContact (which lived only on a Project), in Worktide
 * the Contact belongs to the Customer first and is reachable across all of
 * that customer's projects. This matches how real agencies work: one
 * marketing manager at "Foo GmbH" stays the contact whether they're
 * commissioning website work in project A or a campaign in project B.
 *
 * `workspace` is denormalized from `customer.workspace` so contact-level
 * filtering doesn't always need a join — voters and search filters use it
 * directly.
 *
 * `linkedUser` lets us tie a Contact to an actual Worktide User account when
 * the contact also logs into the (planned) TYPO3 client portal; nullable
 * because most contacts never get a portal account.
 *
 * Primary-contact uniqueness is enforced at the application level via the
 * isPrimary helper — adding a partial unique index on MySQL is non-trivial,
 * and concurrent writes are rare enough that the race is acceptable.
 */
#[ORM\Entity(repositoryClass: ContactRepository::class)]
#[ORM\Table(name: 'contacts')]
#[ORM\Index(name: 'contact_customer_idx', columns: ['customer_id'])]
#[ORM\Index(name: 'contact_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'contact_email_idx', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Contact',
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
    'firstName' => 'partial',
    'lastName' => 'partial',
    'email' => 'partial',
    'tags.id' => 'exact',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isPrimary', 'isActive'])]
#[ApiFilter(OrderFilter::class, properties: ['lastName', 'firstName', 'createdAt'])]
class Contact implements TaggableInterface
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use AuditableTrait;
    use ExternalReferenceTrait;
    use TaggableTrait;

    #[ORM\ManyToOne(inversedBy: 'contacts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    /** Optional salutation: "Mr", "Ms", "Dr", "Prof". Free-form for i18n flexibility. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $salutation = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $firstName;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $lastName;

    /** Academic / nobiliary title prefix ("Dr.", "Prof. Dr."). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $title = null;

    /** Job title at the Customer ("Marketing Manager", "CTO"). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $position = null;

    #[ORM\Column(length: 254, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $mobile = null;

    /**
     * One designated primary contact per customer — the default address-book
     * pick for the agency. The setIsPrimary() helper clears the flag on the
     * customer's other contacts so this stays globally unique. Not enforced
     * at the DB level because MySQL doesn't support partial unique indexes.
     */
    #[ORM\Column]
    private bool $isPrimary = false;

    /** Set false when the person no longer works at the customer; row is kept for history. */
    #[ORM\Column]
    private bool $isActive = true;

    /**
     * Preferred language (a supported-locale code, e.g. "de"/"en") for mail sent
     * TO this contact (newsletter, portal). Null = fall back to the customer's
     * workspace locale, then the app default. Mirrors User.preferredLanguage, but
     * a Contact isn't always a portal User — this closes the recipient-locale gap.
     */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /**
     * Links a Contact to a Worktide User — populated when the contact has
     * portal access. Nullable for the common case of contacts who are only
     * reachable via email.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $linkedUser = null;

    /** When this portal contact last marked their notifications read (drives the unread badge). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $portalNotificationsSeenAt = null;

    /**
     * When the portal invitation email (set-password link + welcome text) was
     * last sent to this contact. null = access provisioned but not yet invited,
     * which the staff UI surfaces as an "offer to send" prompt.
     */
    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $portalInvitedAt = null;

    /**
     * Per-contact feature gating: portal feature keys HIDDEN from this contact,
     * even when the workspace has them on (the Capability×Role matrix, screen 1).
     * Absence = visible; the effective set is workspace-features minus these.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $portalHiddenFeatures = null;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }

    public function getPortalNotificationsSeenAt(): ?\DateTimeImmutable { return $this->portalNotificationsSeenAt; }
    public function setPortalNotificationsSeenAt(?\DateTimeImmutable $t): self { $this->portalNotificationsSeenAt = $t; return $this; }

    public function getPortalInvitedAt(): ?\DateTimeImmutable { return $this->portalInvitedAt; }
    public function markPortalInvited(): self { $this->portalInvitedAt = new \DateTimeImmutable(); return $this; }
    public function clearPortalInvited(): self { $this->portalInvitedAt = null; return $this; }

    /** @return list<string> */
    public function getPortalHiddenFeatures(): array { return $this->portalHiddenFeatures ?? []; }

    /** @param list<string>|null $keys */
    public function setPortalHiddenFeatures(?array $keys): self
    {
        $this->portalHiddenFeatures = $keys === null || $keys === [] ? null : array_values(array_unique($keys));
        return $this;
    }

    public function getCustomer(): Customer { return $this->customer; }
    public function setCustomer(Customer $c): self
    {
        $this->customer = $c;
        // Denormalize workspace so contact-level filters don't always join through customer.
        $this->setWorkspace($c->getWorkspace());
        return $this;
    }

    public function getSalutation(): ?string { return $this->salutation; }
    public function setSalutation(?string $v): self { $this->salutation = $v; return $this; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $v): self { $this->firstName = $v; return $this; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $v): self { $this->lastName = $v; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $v): self { $this->title = $v; return $this; }

    public function getPosition(): ?string { return $this->position; }
    public function setPosition(?string $v): self { $this->position = $v; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $v): self { $this->email = $v === null ? null : mb_strtolower(trim($v)); return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $v): self { $this->phone = $v; return $this; }

    public function getMobile(): ?string { return $this->mobile; }
    public function setMobile(?string $v): self { $this->mobile = $v; return $this; }

    public function isPrimary(): bool { return $this->isPrimary; }

    public function setIsPrimary(bool $v): self
    {
        $this->isPrimary = $v;
        if ($v && isset($this->customer)) {
            // Demote any other contact on this customer that was primary —
            // keeps "at most one primary per customer" without a DB trigger.
            foreach ($this->customer->getContacts() as $sibling) {
                if ($sibling !== $this && $sibling->isPrimary()) {
                    $sibling->isPrimary = false;
                }
            }
        }
        return $this;
    }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): self { $this->isActive = $v; return $this; }

    public function getLocale(): ?string { return $this->locale; }
    public function setLocale(?string $locale): self { $this->locale = $locale; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): self { $this->notes = $v; return $this; }

    public function getLinkedUser(): ?User { return $this->linkedUser; }
    public function setLinkedUser(?User $u): self { $this->linkedUser = $u; return $this; }

    public function getFullName(): string
    {
        return trim(implode(' ', array_filter([$this->title, $this->firstName, $this->lastName])));
    }
}
