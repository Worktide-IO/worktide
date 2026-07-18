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
use App\Entity\Enum\PhoneCategory;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ContactPhoneRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One of a {@see Contact}'s phone numbers, categorised (business/private/mobile/
 * fax). At most one is `isPrimary`. The legacy `Contact.phone` / `Contact.mobile`
 * columns mirror the primary non-mobile / primary mobile number respectively
 * (see {@see \App\EventListener\ContactPrimaryInfoSyncListener}).
 */
#[ORM\Entity(repositoryClass: ContactPhoneRepository::class)]
#[ORM\Table(name: 'contact_phones')]
#[ORM\Index(name: 'contact_phone_contact_idx', columns: ['contact_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ContactPhone',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['contact' => 'exact', 'category' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isPrimary'])]
class ContactPhone
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne(inversedBy: 'phones')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Contact $contact;

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    private string $number;

    #[ORM\Column(length: 20, enumType: PhoneCategory::class, options: ['default' => 'business'])]
    private PhoneCategory $category = PhoneCategory::Business;

    /** Optional label ("Zentrale", "Durchwahl", …). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPrimary = false;

    public function getContact(): Contact { return $this->contact; }
    public function setContact(Contact $c): self
    {
        $this->contact = $c;
        $this->setWorkspace($c->getWorkspace());
        return $this;
    }

    public function getNumber(): string { return $this->number; }
    public function setNumber(string $v): self { $this->number = trim($v); return $this; }

    public function getCategory(): PhoneCategory { return $this->category; }
    public function setCategory(PhoneCategory $v): self { $this->category = $v; return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $v): self { $this->label = $v; return $this; }

    public function isPrimary(): bool { return $this->isPrimary; }
    public function setPrimary(bool $v): self
    {
        $this->isPrimary = $v;
        if ($v && isset($this->contact)) {
            foreach ($this->contact->getPhones() as $sibling) {
                if ($sibling !== $this && $sibling->isPrimary()) {
                    $sibling->setPrimary(false);
                }
            }
        }
        return $this;
    }
}
