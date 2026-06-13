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
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\TaskListEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Membership of a Task in a TaskList, with per-list ordering.
 *
 * Modelled as an explicit join entity (rather than a plain ManyToMany
 * junction) so each placement carries its own ordering — moving a task
 * between lists doesn't lose its position in the others.
 *
 * Unique constraint on (list, task) prevents the same task being added
 * to the same list twice.
 */
#[ORM\Entity(repositoryClass: TaskListEntryRepository::class)]
#[ORM\Table(name: 'task_list_entries')]
#[ORM\UniqueConstraint(name: 'task_list_entry_unique', columns: ['list_id', 'task_id'])]
#[ORM\Index(name: 'task_list_entry_list_idx', columns: ['list_id'])]
#[ORM\Index(name: 'task_list_entry_task_idx', columns: ['task_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'TaskListEntry',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getList())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getList())"),
        new Delete(security: "is_granted('EDIT', object.getList())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['list' => 'exact', 'task' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['position'])]
class TaskListEntry
{
    use EntityIdTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne(inversedBy: 'entries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TaskList $list;

    #[ORM\ManyToOne(inversedBy: 'listEntries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\Column(type: 'float')]
    private float $position = 0.0;

    public function getList(): TaskList { return $this->list; }
    public function setList(TaskList $list): self { $this->list = $list; return $this; }

    public function getTask(): Task { return $this->task; }
    public function setTask(Task $task): self { $this->task = $task; return $this; }

    public function getPosition(): float { return $this->position; }
    public function setPosition(float $p): self { $this->position = $p; return $this; }
}
