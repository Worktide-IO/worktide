<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
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
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\TaskStatusRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaskStatusRepository::class)]
#[ORM\Table(name: 'task_statuses')]
#[ORM\UniqueConstraint(name: 'task_status_workspace_name_unique', columns: ['workspace_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'TaskStatus',
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial', 'workspace' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isCompleted', 'isDefault'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'position', 'createdAt'])]
class TaskStatus
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;
    use ExternalReferenceTrait;

    #[ORM\Column(length: 60)]
    private string $name;

    #[ORM\Column(length: 16)]
    private string $color = '#94a3b8';

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $isCompleted = false;

    #[ORM\Column]
    private bool $isDefault = false;

    /** Tickets in this status are waiting on the customer → the portal SLA clock pauses. */
    #[ORM\Column(options: ['default' => false])]
    private bool $isWaitingForCustomer = false;

    public function isWaitingForCustomer(): bool { return $this->isWaitingForCustomer; }
    public function setIsWaitingForCustomer(bool $v): self { $this->isWaitingForCustomer = $v; return $this; }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;
        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->isCompleted;
    }

    public function setIsCompleted(bool $value): self
    {
        $this->isCompleted = $value;
        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $value): self
    {
        $this->isDefault = $value;
        return $this;
    }
}
