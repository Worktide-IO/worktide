<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\InvoiceStatus;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer invoice, MIRRORED read-only from lexoffice (voucherType=invoice)
 * by `app:lexoffice:sync-invoices`. Worktide is not the system of record — the
 * `lexofficeId` (voucher UUID) is the upsert key. Feeds the portal "Rechnungen"
 * tab (screen 4). "Overdue" is derived (Open + past dueOn), not stored.
 */
#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoices')]
#[ORM\Index(name: 'invoice_customer_idx', columns: ['customer_id'])]
#[ORM\UniqueConstraint(name: 'invoice_lexoffice_uniq', columns: ['workspace_id', 'lexoffice_id'])]
#[ORM\HasLifecycleCallbacks]
class Invoice
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    /** lexoffice voucher UUID — external system-of-record id, upsert key. */
    #[ORM\Column(length: 64)]
    private string $lexofficeId;

    #[ORM\Column(length: 64)]
    private string $number = '';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $issuedOn;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueOn = null;

    #[ORM\Column]
    private int $totalCents = 0;

    #[ORM\Column(nullable: true)]
    private ?int $openCents = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(length: 12, enumType: InvoiceStatus::class, options: ['default' => 'open'])]
    private InvoiceStatus $status = InvoiceStatus::Open;

    public function getCustomer(): Customer { return $this->customer; }
    public function setCustomer(Customer $c): self
    {
        $this->customer = $c;
        $this->setWorkspace($c->getWorkspace());
        return $this;
    }

    public function getLexofficeId(): string { return $this->lexofficeId; }
    public function setLexofficeId(string $id): self { $this->lexofficeId = $id; return $this; }

    public function getNumber(): string { return $this->number; }
    public function setNumber(string $n): self { $this->number = $n; return $this; }

    public function getIssuedOn(): \DateTimeImmutable { return $this->issuedOn; }
    public function setIssuedOn(\DateTimeImmutable $d): self { $this->issuedOn = $d; return $this; }

    public function getDueOn(): ?\DateTimeImmutable { return $this->dueOn; }
    public function setDueOn(?\DateTimeImmutable $d): self { $this->dueOn = $d; return $this; }

    public function getTotalCents(): int { return $this->totalCents; }
    public function setTotalCents(int $c): self { $this->totalCents = $c; return $this; }

    public function getOpenCents(): ?int { return $this->openCents; }
    public function setOpenCents(?int $c): self { $this->openCents = $c; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $c): self { $this->currency = $c; return $this; }

    public function getStatus(): InvoiceStatus { return $this->status; }
    public function setStatus(InvoiceStatus $s): self { $this->status = $s; return $this; }

    /** Open and past its due date. */
    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Open
            && $this->dueOn !== null
            && $this->dueOn < new \DateTimeImmutable('today');
    }
}
