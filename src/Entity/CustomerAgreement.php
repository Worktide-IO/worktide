<?php

declare(strict_types=1);

namespace App\Entity;

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
use App\Entity\Enum\AgreementStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\CustomerAgreementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks, per Customer and {@see AgreementType}, whether a contract has been
 * concluded — the "head" carrying the *current* effective state for the quick
 * CRM overview, with the full version history hanging off it as
 * {@see CustomerAgreementRevision} rows.
 *
 * One head per (customer, type). The effective {@see self::$status},
 * {@see self::$signedOn} and {@see self::$validUntil} are denormalised from
 * {@see self::$currentRevision} (the in-force signed version) by
 * {@see \App\Service\Crm\AgreementService} so overview queries
 * ("which customers have an expired SLA?") need no joins.
 * {@see self::$pendingRevision} points at a version still "in Abstimmung"
 * (in negotiation), which may coexist with an already-signed currentRevision.
 *
 * {@see self::$typeSlug} mirrors the type's slug so clients can read/filter by
 * a simple key without resolving the type IRI.
 */
#[ORM\Entity(repositoryClass: CustomerAgreementRepository::class)]
#[ORM\Table(name: 'customer_agreements')]
#[ORM\UniqueConstraint(name: 'customer_agreement_customer_type_uniq', columns: ['customer_id', 'type_id'])]
#[ORM\Index(name: 'customer_agreement_customer_idx', columns: ['customer_id'])]
#[ORM\Index(name: 'customer_agreement_type_idx', columns: ['type_id'])]
#[ORM\Index(name: 'customer_agreement_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'customer_agreement_status_idx', columns: ['status'])]
#[ORM\Index(name: 'customer_agreement_slug_idx', columns: ['type_slug'])]
#[ORM\Index(name: 'customer_agreement_valid_until_idx', columns: ['valid_until'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'CustomerAgreement',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'customer' => 'exact',
    'type' => 'exact',
    'typeSlug' => 'exact',
    'status' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['typeSlug', 'status', 'signedOn', 'validUntil'])]
class CustomerAgreement
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne(inversedBy: 'agreements')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private AgreementType $type;

    /** Mirror of {@see AgreementType::$slug} — the simple read/filter key. */
    #[ApiProperty(writable: false)]
    #[ORM\Column(length: 64)]
    private string $typeSlug = '';

    /** Effective state — maintained by AgreementService / the expiry command. */
    #[ApiProperty(writable: false)]
    #[ORM\Column(length: 16, enumType: AgreementStatus::class, options: ['default' => 'none'])]
    private AgreementStatus $status = AgreementStatus::None;

    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $signedOn = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ApiProperty(writable: false)]
    #[ORM\ManyToOne(targetEntity: CustomerAgreementRevision::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CustomerAgreementRevision $currentRevision = null;

    #[ApiProperty(writable: false)]
    #[ORM\ManyToOne(targetEntity: CustomerAgreementRevision::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CustomerAgreementRevision $pendingRevision = null;

    /** @var Collection<int, CustomerAgreementRevision> */
    #[ORM\OneToMany(mappedBy: 'agreement', targetEntity: CustomerAgreementRevision::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['versionNo' => 'ASC'])]
    private Collection $revisions;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->revisions = new ArrayCollection();
    }

    public function getCustomer(): Customer { return $this->customer; }
    public function setCustomer(Customer $c): self
    {
        $this->customer = $c;
        $this->setWorkspace($c->getWorkspace());
        return $this;
    }

    public function getType(): AgreementType { return $this->type; }
    public function setType(AgreementType $t): self
    {
        $this->type = $t;
        $this->typeSlug = $t->getSlug();
        return $this;
    }

    public function getTypeSlug(): string { return $this->typeSlug; }

    public function getStatus(): AgreementStatus { return $this->status; }
    public function setStatus(AgreementStatus $s): self { $this->status = $s; return $this; }

    public function getSignedOn(): ?\DateTimeImmutable { return $this->signedOn; }
    public function setSignedOn(?\DateTimeImmutable $d): self { $this->signedOn = $d; return $this; }

    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }
    public function setValidUntil(?\DateTimeImmutable $d): self { $this->validUntil = $d; return $this; }

    public function getCurrentRevision(): ?CustomerAgreementRevision { return $this->currentRevision; }
    public function setCurrentRevision(?CustomerAgreementRevision $r): self { $this->currentRevision = $r; return $this; }

    public function getPendingRevision(): ?CustomerAgreementRevision { return $this->pendingRevision; }
    public function setPendingRevision(?CustomerAgreementRevision $r): self { $this->pendingRevision = $r; return $this; }

    /** @return Collection<int, CustomerAgreementRevision> */
    public function getRevisions(): Collection { return $this->revisions; }

    public function addRevision(CustomerAgreementRevision $r): self
    {
        if (!$this->revisions->contains($r)) {
            $this->revisions->add($r);
            $r->setAgreement($this);
        }
        return $this;
    }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }

    /** Read-only convenience flag for the overview. */
    #[ApiProperty(writable: false)]
    public function getIsSigned(): bool { return $this->status === AgreementStatus::Signed; }
}
