<?php

declare(strict_types=1);

namespace App\Entity;

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
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\WorkspaceAbsenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Workspace-wide non-working day — company holiday, retreat, training day.
 * Applies to every member; the absences UI overlays these on top of personal
 * Absences when rendering availability.
 */
#[ORM\Entity(repositoryClass: WorkspaceAbsenceRepository::class)]
#[ORM\Table(name: 'workspace_absences')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'WorkspaceAbsence',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'name' => 'partial'])]
#[ApiFilter(DateFilter::class, properties: ['startsOn', 'endsOn'])]
#[ApiFilter(OrderFilter::class, properties: ['startsOn'])]
class WorkspaceAbsence
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column]
    private \DateTimeImmutable $endsOn;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getStartsOn(): \DateTimeImmutable { return $this->startsOn; }
    public function setStartsOn(\DateTimeImmutable $when): self { $this->startsOn = $when; return $this; }

    public function getEndsOn(): \DateTimeImmutable { return $this->endsOn; }
    public function setEndsOn(\DateTimeImmutable $when): self { $this->endsOn = $when; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $desc): self { $this->description = $desc; return $this; }
}
