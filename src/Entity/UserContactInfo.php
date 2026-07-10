<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Repository\UserContactInfoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Additional contact channels for a User — phone, alternative email,
 * postal address. Multiple rows per user; type+subType + label distinguish
 * "work mobile" from "private mobile" from "billing address".
 *
 * Matches awork's UserContactInfo array nested inside the User response.
 */
#[ORM\Entity(repositoryClass: UserContactInfoRepository::class)]
#[ORM\Table(name: 'user_contact_infos')]
#[ORM\Index(name: 'user_contact_info_user_idx', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'UserContactInfo',
    operations: [
        // Per-user PII (private phone numbers, addresses). Reads are scoped to
        // the caller's OWN rows by WorkspaceScopeExtension (root.user == caller);
        // every item/write op is self-only. Previously any ROLE_USER could read
        // or modify every user's contact info across all tenants.
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "object.getUser() == user"),
        new Post(securityPostDenormalize: "object.getUser() == user"),
        new Patch(security: "object.getUser() == user"),
        new Delete(security: "object.getUser() == user"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['user' => 'exact', 'type' => 'exact', 'subType' => 'exact', 'value' => 'partial'])]
class UserContactInfo
{
    use EntityIdTrait;
    use TimestampableTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** "email" | "phone" | "address" — broad channel category */
    #[ORM\Column(length: 20)]
    private string $type;

    /** "work" | "private" | "mobile" | "billing" — narrower role */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $subType = null;

    #[ORM\Column(length: 200)]
    private string $value;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $label = null;

    /** Free-form address payload (street, city, postal, country) when type=address. */
    #[ORM\Column(type: 'json')]
    private array $address = [];

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }

    public function getSubType(): ?string { return $this->subType; }
    public function setSubType(?string $sub): self { $this->subType = $sub; return $this; }

    public function getValue(): string { return $this->value; }
    public function setValue(string $value): self { $this->value = $value; return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = $label; return $this; }

    /** @return array<string, mixed> */
    public function getAddress(): array { return $this->address; }

    /** @param array<string, mixed> $address */
    public function setAddress(array $address): self { $this->address = $address; return $this; }
}
