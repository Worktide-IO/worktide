<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Entity\Enum\OfferStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ProjectOfferRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A lightweight offer generated when a customer accepts a {@see ProjectProposal}
 * in the portal ("Annehmen → Angebot"). Unlike {@see CustomerAgreement} (which
 * is type-keyed: one head per customer+type, with revisions), a ProjectOffer is
 * per-proposal — arbitrarily many per customer — so it fits the pitch→offer flow.
 *
 * It carries the agreed scope snapshot (title + amount) and shows up in the
 * portal "Angebote & Verträge" screen. Read-only over the API; created by the
 * proposal-accept action.
 */
#[ORM\Entity(repositoryClass: ProjectOfferRepository::class)]
#[ORM\Table(name: 'project_offers')]
#[ORM\Index(name: 'project_offer_customer_idx', columns: ['customer_id'])]
#[ORM\Index(name: 'project_offer_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ProjectOffer',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'customer' => 'exact', 'status' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'status'])]
class ProjectOffer
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    /** The proposal this offer was generated from (kept for traceability). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProjectProposal $sourceProposal = null;

    /** Human offer number, e.g. "A-2026-3fd7". */
    #[ORM\Column(length: 32)]
    private string $reference;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column]
    private int $amountCents = 0;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(length: 16, enumType: OfferStatus::class, options: ['default' => 'open'])]
    private OfferStatus $status = OfferStatus::Open;

    public function getCustomer(): Customer { return $this->customer; }
    public function setCustomer(Customer $customer): self
    {
        $this->customer = $customer;
        $this->setWorkspace($customer->getWorkspace());
        return $this;
    }

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $project): self { $this->project = $project; return $this; }

    public function getSourceProposal(): ?ProjectProposal { return $this->sourceProposal; }
    public function setSourceProposal(?ProjectProposal $p): self { $this->sourceProposal = $p; return $this; }

    public function getReference(): string { return $this->reference; }
    public function setReference(string $reference): self { $this->reference = $reference; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    public function getAmountCents(): int { return $this->amountCents; }
    public function setAmountCents(int $cents): self { $this->amountCents = $cents; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): self { $this->currency = $currency; return $this; }

    public function getStatus(): OfferStatus { return $this->status; }
    public function setStatus(OfferStatus $status): self { $this->status = $status; return $this; }
}
