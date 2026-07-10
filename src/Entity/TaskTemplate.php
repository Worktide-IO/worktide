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
use App\Entity\Enum\TaskPriority;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\TaskTemplateRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Trait\TranslatableTrait;

/**
 * Template for a Task — lives inside a TaskBundle. When the bundle is
 * applied to a project (or pulled in via instantiate-from-ProjectTemplate),
 * each TaskTemplate becomes a real Task with these defaults filled in and
 * everything else (assignee, dueDate, status) reset to project defaults.
 *
 * `dueDayOffset` is days from the project start; the instantiator computes
 * `dueOn = project.startsOn + dueDayOffset` (or `null` if startsOn is null).
 * `parent` enables one-level subtask templates inside the same bundle.
 */
#[ORM\Entity(repositoryClass: TaskTemplateRepository::class)]
#[ORM\Table(name: 'task_templates')]
#[ORM\Index(name: 'task_template_bundle_idx', columns: ['bundle_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'TaskTemplate',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getBundle().getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getBundle().getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getBundle().getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'bundle' => 'exact',
    'title' => 'partial',
    'priority' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['position', 'title', 'createdAt'])]
class TaskTemplate implements TranslatableInterface
{
    use TranslatableTrait;
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne(inversedBy: 'taskTemplates')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TaskBundle $bundle;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 12, enumType: TaskPriority::class)]
    private TaskPriority $priority = TaskPriority::Normal;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $estimatedMinutes = null;

    /** Days from project start when this task is due. Null = no auto due-date. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $dueDayOffset = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?TaskTemplate $parent = null;

    /** @var list<string> default checklist item names — instantiator creates ChecklistItem rows */
    #[ORM\Column(type: 'json')]
    private array $defaultChecklist = [];

    public function getBundle(): TaskBundle { return $this->bundle; }
    public function setBundle(TaskBundle $bundle): self { $this->bundle = $bundle; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getPriority(): TaskPriority { return $this->priority; }
    public function setPriority(TaskPriority $priority): self { $this->priority = $priority; return $this; }

    public function getEstimatedMinutes(): ?int { return $this->estimatedMinutes; }
    public function setEstimatedMinutes(?int $minutes): self { $this->estimatedMinutes = $minutes; return $this; }

    public function getDueDayOffset(): ?int { return $this->dueDayOffset; }
    public function setDueDayOffset(?int $days): self { $this->dueDayOffset = $days; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    public function getParent(): ?TaskTemplate { return $this->parent; }
    public function setParent(?TaskTemplate $parent): self { $this->parent = $parent; return $this; }

    /** @return list<string> */
    public function getDefaultChecklist(): array { return $this->defaultChecklist; }

    /** @param list<string> $items */
    public function setDefaultChecklist(array $items): self
    {
        $this->defaultChecklist = array_values(array_filter($items, 'is_string'));
        return $this;
    }
    /**
     * @return list<string>
     */
    public static function translatableFields(): array
    {
        return ['title', 'description'];
    }

}
