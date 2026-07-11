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
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ContactAbsenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer contact's own away-dates, set by the client in the portal so the
 * agency knows when they're unavailable. Informational only — distinct from the
 * staff {@see Absence} (which feeds the booking slot engine) and from
 * {@see WorkspaceAbsence} closures.
 *
 * Clients create/delete their own via the portal controller
 * ({@see \App\Controller\Api\Portal\PortalContactAbsencesController}); staff read
 * (and may delete) them through this workspace-scoped API resource. `customer` is
 * denormalized from the contact so staff can filter a customer's contacts' absences.
 */
#[ORM\Entity(repositoryClass: ContactAbsenceRepository::class)]
#[ORM\Table(name: 'contact_absences')]
#[ORM\Index(name: 'contact_absence_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'contact_absence_contact_idx', columns: ['contact_id'])]
#[ORM\Index(name: 'contact_absence_customer_idx', columns: ['customer_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ContactAbsence',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'customer' => 'exact', 'contact' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['startsOn', 'endsOn', 'createdAt'])]
class ContactAbsence
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Contact $contact = null;

    // Denormalized from the contact for staff filtering; not client-writable.
    #[ApiProperty(writable: false)]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Customer $customer = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $startsOn = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $endsOn = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(?Contact $contact): self
    {
        $this->contact = $contact;
        // Stamp customer + workspace from the contact (the auto-stamp convention).
        if ($contact !== null) {
            $this->customer = $contact->getCustomer();
            $this->setWorkspace($contact->getCustomer()->getWorkspace());
        }

        return $this;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function getStartsOn(): ?\DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function setStartsOn(?\DateTimeImmutable $startsOn): self
    {
        $this->startsOn = $startsOn;

        return $this;
    }

    public function getEndsOn(): ?\DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function setEndsOn(?\DateTimeImmutable $endsOn): self
    {
        $this->endsOn = $endsOn;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }
}
