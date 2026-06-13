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
use App\Entity\Enum\TaskDependencyType;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\TaskDependencyRepository;
use App\Validator\NoDependencyCycle;
use Doctrine\ORM\Mapping as ORM;

/**
 * Directional precedence relation between two tasks within the same project.
 *
 * Beyond awork: we keep `type` (the four classic PM dependency variants) and
 * `lagMinutes` so Gantt-style scheduling has the data it needs without a
 * schema change later. Cycle detection runs in the controller before persist.
 */
#[ORM\Entity(repositoryClass: TaskDependencyRepository::class)]
#[ORM\Table(name: 'task_dependencies')]
#[ORM\UniqueConstraint(name: 'task_dependency_unique', columns: ['predecessor_id', 'successor_id', 'type'])]
#[ORM\Index(name: 'task_dependency_predecessor_idx', columns: ['predecessor_id'])]
#[ORM\Index(name: 'task_dependency_successor_idx', columns: ['successor_id'])]
#[ORM\HasLifecycleCallbacks]
#[NoDependencyCycle]
#[ApiResource(
    shortName: 'TaskDependency',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object)"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object)"),
        new Delete(security: "is_granted('DELETE', object)"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'predecessor' => 'exact',
    'successor' => 'exact',
    'type' => 'exact',
])]
class TaskDependency
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $predecessor;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $successor;

    #[ORM\Column(length: 24, enumType: TaskDependencyType::class)]
    private TaskDependencyType $type = TaskDependencyType::FinishToStart;

    /** Delay (positive) or lead (negative) in minutes between the linked endpoints. */
    #[ORM\Column(type: 'integer')]
    private int $lagMinutes = 0;

    public function getPredecessor(): Task { return $this->predecessor; }
    public function setPredecessor(Task $task): self { $this->predecessor = $task; return $this; }

    public function getSuccessor(): Task { return $this->successor; }
    public function setSuccessor(Task $task): self { $this->successor = $task; return $this; }

    public function getType(): TaskDependencyType { return $this->type; }
    public function setType(TaskDependencyType $type): self { $this->type = $type; return $this; }

    public function getLagMinutes(): int { return $this->lagMinutes; }
    public function setLagMinutes(int $minutes): self { $this->lagMinutes = $minutes; return $this; }
}
