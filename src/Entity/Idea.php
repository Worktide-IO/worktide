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
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\IdeaOrigin;
use App\Entity\Enum\IdeaStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TaggableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\IdeaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An idea on a customer's idea/feature board — the portal "Ideen fürs Business"
 * screen with upvoting. Ideas may come from the customer, the agency, or an AI
 * suggestion ({@see IdeaOrigin}); enough upvotes can turn one into a Task or
 * offer, recorded via {@see self::$convertedTask}.
 *
 * `voteCount` is denormalized from {@see IdeaVote} rows for cheap sorting/
 * display and is kept in sync by the vote endpoint.
 */
#[ORM\Entity(repositoryClass: IdeaRepository::class)]
#[ORM\Table(name: 'ideas')]
#[ORM\Index(name: 'idea_customer_idx', columns: ['customer_id'])]
#[ORM\Index(name: 'idea_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'idea_status_idx', columns: ['status'])]
#[ORM\Index(name: 'idea_vote_count_idx', columns: ['vote_count'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Idea',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'customer' => 'exact',
    'status' => 'exact',
    'origin' => 'exact',
    'title' => 'partial',
    'tags.id' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['voteCount', 'status', 'title', 'createdAt'])]
class Idea implements TaggableInterface
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;
    use TaggableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 16, enumType: IdeaStatus::class, options: ['default' => 'proposed'])]
    private IdeaStatus $status = IdeaStatus::Proposed;

    #[ORM\Column(length: 16, enumType: IdeaOrigin::class, options: ['default' => 'customer'])]
    private IdeaOrigin $origin = IdeaOrigin::Customer;

    /** The contact who submitted it (when a customer contact did). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $submittedByContact = null;

    /** Denormalized upvote count — kept in sync with {@see IdeaVote} rows. */
    #[ORM\Column(options: ['default' => 0])]
    private int $voteCount = 0;

    /** Set when the idea has been turned into a task ("genug Upvotes → Task"). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Task $convertedTask = null;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, IdeaVote> */
    #[ORM\OneToMany(targetEntity: IdeaVote::class, mappedBy: 'idea', cascade: ['remove'], orphanRemoval: true)]
    private Collection $votes;

    public function __construct()
    {
        $this->votes = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }

    public function getCustomer(): Customer { return $this->customer; }
    public function setCustomer(Customer $customer): self
    {
        $this->customer = $customer;
        // Denormalize workspace from the customer (WorkspaceScopedTrait).
        $this->setWorkspace($customer->getWorkspace());
        return $this;
    }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getStatus(): IdeaStatus { return $this->status; }
    public function setStatus(IdeaStatus $status): self { $this->status = $status; return $this; }

    public function getOrigin(): IdeaOrigin { return $this->origin; }
    public function setOrigin(IdeaOrigin $origin): self { $this->origin = $origin; return $this; }

    public function getSubmittedByContact(): ?Contact { return $this->submittedByContact; }
    public function setSubmittedByContact(?Contact $contact): self { $this->submittedByContact = $contact; return $this; }

    public function getVoteCount(): int { return $this->voteCount; }
    public function setVoteCount(int $voteCount): self { $this->voteCount = max(0, $voteCount); return $this; }

    public function getConvertedTask(): ?Task { return $this->convertedTask; }
    public function setConvertedTask(?Task $task): self { $this->convertedTask = $task; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    /** @return Collection<int, IdeaVote> */
    public function getVotes(): Collection { return $this->votes; }
}
