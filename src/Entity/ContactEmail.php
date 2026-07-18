<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ContactEmailRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One of a {@see Contact}'s email addresses. A contact can have several; at most
 * one is `isPrimary`. The legacy `Contact.email` column mirrors the primary
 * address (kept in sync by {@see \App\EventListener\ContactPrimaryInfoSyncListener})
 * so the ~70 existing readers of `Contact.email` keep working unchanged.
 *
 * `workspace` is denormalized from the owning contact for query-scoping.
 */
#[ORM\Entity(repositoryClass: ContactEmailRepository::class)]
#[ORM\Table(name: 'contact_emails')]
#[ORM\Index(name: 'contact_email_contact_idx', columns: ['contact_id'])]
#[ORM\Index(name: 'contact_email_address_idx', columns: ['address'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ContactEmail',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['contact' => 'exact', 'address' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isPrimary'])]
class ContactEmail
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne(inversedBy: 'emails')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Contact $contact;

    #[ORM\Column(length: 254)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $address;

    /** Optional label ("Sekretariat", "Rechnung", …). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPrimary = false;

    /** Whether the address has been confirmed (e.g. via a portal double-opt-in). */
    #[ORM\Column(options: ['default' => false])]
    private bool $isVerified = false;

    public function getContact(): Contact { return $this->contact; }
    public function setContact(Contact $c): self
    {
        $this->contact = $c;
        $this->setWorkspace($c->getWorkspace());
        return $this;
    }

    public function getAddress(): string { return $this->address; }
    public function setAddress(string $v): self { $this->address = mb_strtolower(trim($v)); return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $v): self { $this->label = $v; return $this; }

    public function isPrimary(): bool { return $this->isPrimary; }
    public function setPrimary(bool $v): self
    {
        $this->isPrimary = $v;
        if ($v && isset($this->contact)) {
            foreach ($this->contact->getEmails() as $sibling) {
                if ($sibling !== $this && $sibling->isPrimary()) {
                    $sibling->setPrimary(false);
                }
            }
        }
        return $this;
    }

    public function isVerified(): bool { return $this->isVerified; }
    public function setVerified(bool $v): self { $this->isVerified = $v; return $this; }
}
