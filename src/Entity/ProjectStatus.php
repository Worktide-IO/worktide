<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ProjectStatusRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectStatusRepository::class)]
#[ORM\Table(name: 'project_statuses')]
#[ORM\UniqueConstraint(name: 'project_status_workspace_name_unique', columns: ['workspace_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
class ProjectStatus
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\Column(length: 60)]
    private string $name;

    #[ORM\Column(length: 16)]
    private string $color = '#6366f1';

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $isCompleted = false;

    #[ORM\Column]
    private bool $isArchived = false;

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

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function setIsArchived(bool $value): self
    {
        $this->isArchived = $value;
        return $this;
    }
}
