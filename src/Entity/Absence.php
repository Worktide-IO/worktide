<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
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
use App\Entity\Trait\ExternalReferenceTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\AbsenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Personal absence — vacation, sick leave, etc. Linked to a user; counts
 * against their UserCapacity when computing availability.
 *
 * Beyond awork: we have a `type` enum (vacation / sick / child_sick /
 * personal / holiday / other) for filtering and reporting. awork doesn't
 * model this, so its UI relies on `description` heuristics.
 *
 * Half-day flags follow awork's semantics: if both start and end are the
 * same date, only one half-day flag matters.
 *
 * `availabilityPercent` (0–100, default 0) is the finer-grained axis for
 * sickness / child-sickness: 0 means fully away (blocks the whole day), 50
 * means the person can still work half their normal daily capacity. It
 * supersedes the half-day flags for capacity math (see WorkloadController).
 */
#[ORM\Entity(repositoryClass: AbsenceRepository::class)]
#[ORM\Table(name: 'absences')]
#[ORM\Index(name: 'absence_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'absence_range_idx', columns: ['starts_on', 'ends_on'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Absence',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_USER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'user' => 'exact', 'type' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isReadOnly'])]
#[ApiFilter(DateFilter::class, properties: ['startsOn', 'endsOn'])]
#[ApiFilter(OrderFilter::class, properties: ['startsOn', 'endsOn'])]
class Absence
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;
    use ExternalReferenceTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column]
    private \DateTimeImmutable $endsOn;

    #[ORM\Column(length: 24)]
    private string $type = 'vacation';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Share of the person's normal daily capacity they can still work despite
     * this absence: 0 = fully away, 100 = fully available. Used for sickness /
     * child-sickness with limited availability.
     */
    #[ORM\Column]
    private int $availabilityPercent = 0;

    #[ORM\Column]
    private bool $isHalfDayOnStart = false;

    #[ORM\Column]
    private bool $isHalfDayOnEnd = false;

    /** Imported from an external calendar — editable only via re-import. */
    #[ORM\Column]
    private bool $isReadOnly = false;

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getStartsOn(): \DateTimeImmutable { return $this->startsOn; }
    public function setStartsOn(\DateTimeImmutable $when): self { $this->startsOn = $when; return $this; }

    public function getEndsOn(): \DateTimeImmutable { return $this->endsOn; }
    public function setEndsOn(\DateTimeImmutable $when): self { $this->endsOn = $when; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }

    public function getAvailabilityPercent(): int { return $this->availabilityPercent; }
    public function setAvailabilityPercent(int $percent): self { $this->availabilityPercent = max(0, min(100, $percent)); return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $desc): self { $this->description = $desc; return $this; }

    public function isHalfDayOnStart(): bool { return $this->isHalfDayOnStart; }
    public function setIsHalfDayOnStart(bool $v): self { $this->isHalfDayOnStart = $v; return $this; }

    public function isHalfDayOnEnd(): bool { return $this->isHalfDayOnEnd; }
    public function setIsHalfDayOnEnd(bool $v): self { $this->isHalfDayOnEnd = $v; return $this; }

    public function isReadOnly(): bool { return $this->isReadOnly; }
    public function setIsReadOnly(bool $v): self { $this->isReadOnly = $v; return $this; }
}
