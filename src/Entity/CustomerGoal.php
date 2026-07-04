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
use App\Entity\Enum\GoalStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\CustomerGoalRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A measurable business goal the agency tracks for a customer — the portal
 * "Ziele" screen (e.g. "Conversion-Rate +20 %", "500 Newsletter-Abos").
 *
 * Agency-managed, customer-visible: staff set target/current/status; the
 * portal renders progress ({@see self::$currentValue} / {@see self::$targetValue})
 * read-only. `unit` is a free label ("%", "Abos", "s") kept alongside the
 * numbers so the UI can format without guessing.
 */
#[ORM\Entity(repositoryClass: CustomerGoalRepository::class)]
#[ORM\Table(name: 'customer_goals')]
#[ORM\Index(name: 'customer_goal_customer_idx', columns: ['customer_id'])]
#[ORM\Index(name: 'customer_goal_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'CustomerGoal',
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
    'title' => 'partial',
])]
#[ApiFilter(OrderFilter::class, properties: ['title', 'status', 'targetDate', 'position', 'createdAt'])]
class CustomerGoal
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

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Free-form unit label shown next to the numbers, e.g. "%", "Abos", "s". */
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $targetValue = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $currentValue = null;

    #[ORM\Column(length: 16, enumType: GoalStatus::class, options: ['default' => 'on_track'])]
    private GoalStatus $status = GoalStatus::OnTrack;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $targetDate = null;

    #[ORM\Column]
    private int $position = 0;

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

    public function getUnit(): ?string { return $this->unit; }
    public function setUnit(?string $unit): self { $this->unit = $unit; return $this; }

    public function getTargetValue(): ?float { return $this->targetValue; }
    public function setTargetValue(?float $targetValue): self { $this->targetValue = $targetValue; return $this; }

    public function getCurrentValue(): ?float { return $this->currentValue; }
    public function setCurrentValue(?float $currentValue): self { $this->currentValue = $currentValue; return $this; }

    public function getStatus(): GoalStatus { return $this->status; }
    public function setStatus(GoalStatus $status): self { $this->status = $status; return $this; }

    public function getTargetDate(): ?\DateTimeImmutable { return $this->targetDate; }
    public function setTargetDate(?\DateTimeImmutable $targetDate): self { $this->targetDate = $targetDate; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }
}
