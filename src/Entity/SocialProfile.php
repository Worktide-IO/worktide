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
use App\Entity\Enum\SocialPlatform;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\SocialProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A social-media / web presence, attachable to EITHER a {@see Contact} OR a
 * {@see Customer} (exactly one owner). Arbitrarily many per owner, each typed by
 * {@see SocialPlatform}. `workspace` is denormalized from whichever owner is set
 * for query-scoping.
 */
#[ORM\Entity(repositoryClass: SocialProfileRepository::class)]
#[ORM\Table(name: 'social_profiles')]
#[ORM\Index(name: 'social_profile_contact_idx', columns: ['contact_id'])]
#[ORM\Index(name: 'social_profile_customer_idx', columns: ['customer_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'SocialProfile',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['contact' => 'exact', 'customer' => 'exact', 'platform' => 'exact'])]
#[Assert\Callback('validateExactlyOneOwner')]
class SocialProfile
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne(inversedBy: 'socialProfiles')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne(inversedBy: 'socialProfiles')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Customer $customer = null;

    #[ORM\Column(length: 20, enumType: SocialPlatform::class)]
    private SocialPlatform $platform = SocialPlatform::Other;

    /** Full profile URL. */
    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Url]
    private string $url;

    /** Optional handle/username ("@acme") shown instead of the raw URL. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $handle = null;

    /** Free-text label, primarily for platform=other ("Behance", "Threads", …). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $label = null;

    public function getContact(): ?Contact { return $this->contact; }
    public function setContact(?Contact $c): self
    {
        $this->contact = $c;
        if ($c !== null) {
            $this->customer = null;
            $this->setWorkspace($c->getWorkspace());
        }
        return $this;
    }

    public function getCustomer(): ?Customer { return $this->customer; }
    public function setCustomer(?Customer $c): self
    {
        $this->customer = $c;
        if ($c !== null) {
            $this->contact = null;
            $this->setWorkspace($c->getWorkspace());
        }
        return $this;
    }

    public function getPlatform(): SocialPlatform { return $this->platform; }
    public function setPlatform(SocialPlatform $v): self { $this->platform = $v; return $this; }

    public function getUrl(): string { return $this->url; }
    public function setUrl(string $v): self { $this->url = trim($v); return $this; }

    public function getHandle(): ?string { return $this->handle; }
    public function setHandle(?string $v): self { $this->handle = $v; return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $v): self { $this->label = $v; return $this; }

    public function validateExactlyOneOwner(ExecutionContextInterface $context): void
    {
        if (($this->contact === null) === ($this->customer === null)) {
            $context->buildViolation('A social profile must belong to exactly one of a contact or a customer.')
                ->atPath('contact')
                ->addViolation();
        }
    }
}
