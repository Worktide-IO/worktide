<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Enum\AgreementStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\CustomerAgreementRevisionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One version of a {@see CustomerAgreement} — an immutable history row. A new
 * revision is recorded each time the agreement is drafted, negotiated, signed,
 * or terminated; the parent head's effective state is recomputed from its
 * revisions by {@see \App\Service\Crm\AgreementService}.
 *
 * Read-only over the API: revisions are created through the agreement's
 * lifecycle (the convenience endpoint / AgreementService), never PATCHed in
 * place, so the history stays trustworthy.
 */
#[ORM\Entity(repositoryClass: CustomerAgreementRevisionRepository::class)]
#[ORM\Table(name: 'customer_agreement_revisions')]
#[ORM\Index(name: 'agr_rev_agreement_idx', columns: ['agreement_id'])]
#[ORM\Index(name: 'agr_rev_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'CustomerAgreementRevision',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'agreement' => 'exact',
    'status' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['versionNo', 'signedOn', 'createdAt'])]
class CustomerAgreementRevision
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne(inversedBy: 'revisions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomerAgreement $agreement;

    #[ORM\Column(options: ['default' => 1])]
    private int $versionNo = 1;

    #[ORM\Column(length: 16, enumType: AgreementStatus::class)]
    private AgreementStatus $status = AgreementStatus::Draft;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $signedOn = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    /** Contract / file number or any external reference for this version. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reference = null;

    /** The signed document for this version, stored in the customer's file store. */
    #[ORM\ManyToOne(targetEntity: File::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?File $file = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /** @var Collection<int, AgreementLineItem> */
    #[ORM\OneToMany(mappedBy: 'revision', targetEntity: AgreementLineItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lineItems;

    public function __construct()
    {
        $this->lineItems = new ArrayCollection();
    }

    public function getAgreement(): CustomerAgreement { return $this->agreement; }
    public function setAgreement(CustomerAgreement $a): self
    {
        $this->agreement = $a;
        $this->setWorkspace($a->getWorkspace());
        return $this;
    }

    public function getVersionNo(): int { return $this->versionNo; }
    public function setVersionNo(int $n): self { $this->versionNo = $n; return $this; }

    public function getStatus(): AgreementStatus { return $this->status; }
    public function setStatus(AgreementStatus $s): self { $this->status = $s; return $this; }

    public function getSignedOn(): ?\DateTimeImmutable { return $this->signedOn; }
    public function setSignedOn(?\DateTimeImmutable $d): self { $this->signedOn = $d; return $this; }

    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }
    public function setValidUntil(?\DateTimeImmutable $d): self { $this->validUntil = $d; return $this; }

    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $r): self { $this->reference = $r; return $this; }

    public function getFile(): ?File { return $this->file; }
    public function setFile(?File $f): self { $this->file = $f; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }

    /** @return Collection<int, AgreementLineItem> */
    public function getLineItems(): Collection { return $this->lineItems; }

    public function addLineItem(AgreementLineItem $item): self
    {
        if (!$this->lineItems->contains($item)) {
            $this->lineItems->add($item);
            $item->setRevision($this);
        }
        return $this;
    }

    public function removeLineItem(AgreementLineItem $item): self
    {
        $this->lineItems->removeElement($item);
        return $this;
    }

    /** Convenience read-only IRI-free flag for clients. */
    #[ApiProperty(writable: false)]
    public function getIsSigned(): bool { return $this->status === AgreementStatus::Signed; }
}
