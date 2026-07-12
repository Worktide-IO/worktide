<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\TranslatableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\AgreementLineItemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One priced position of a {@see CustomerAgreementRevision} — the line items
 * that make up an offer/contract and sum to its total (portal screen 4). Lines
 * belong to a specific revision so the offered terms stay accurate across
 * re-negotiations. `isRecurring` distinguishes monthly retainer positions
 * ("Summe / Monat") from one-off charges.
 */
#[ORM\Entity(repositoryClass: AgreementLineItemRepository::class)]
#[ORM\Table(name: 'agreement_line_items')]
#[ORM\Index(name: 'agr_line_revision_idx', columns: ['revision_id'])]
#[ORM\HasLifecycleCallbacks]
class AgreementLineItem implements TranslatableInterface
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use TranslatableTrait;

    #[ORM\ManyToOne(inversedBy: 'lineItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomerAgreementRevision $revision;

    #[ORM\Column(length: 200)]
    private string $description;

    #[ORM\Column(type: 'float', options: ['default' => 1])]
    private float $quantity = 1.0;

    /** Price per unit in minor units (cents). Line total = quantity × this. */
    #[ORM\Column]
    private int $unitAmountCents = 0;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    /** Monthly/recurring position (vs. a one-off charge). */
    #[ORM\Column(options: ['default' => false])]
    private bool $isRecurring = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function getRevision(): CustomerAgreementRevision { return $this->revision; }
    public function setRevision(CustomerAgreementRevision $r): self
    {
        $this->revision = $r;
        $this->setWorkspace($r->getWorkspace());
        return $this;
    }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $d): self { $this->description = $d; return $this; }

    public function getQuantity(): float { return $this->quantity; }
    public function setQuantity(float $q): self { $this->quantity = $q; return $this; }

    public function getUnitAmountCents(): int { return $this->unitAmountCents; }
    public function setUnitAmountCents(int $c): self { $this->unitAmountCents = $c; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $c): self { $this->currency = $c; return $this; }

    public function isRecurring(): bool { return $this->isRecurring; }
    public function setIsRecurring(bool $v): self { $this->isRecurring = $v; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): self { $this->position = $p; return $this; }

    /** Line total in cents (quantity × unit). */
    public function getAmountCents(): int
    {
        return (int) round($this->quantity * $this->unitAmountCents);
    }

    /**
     * @return list<string>
     */
    public static function translatableFields(): array
    {
        return ['description'];
    }
}
