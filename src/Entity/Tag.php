<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\TagScope;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\TagRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tags')]
#[ORM\UniqueConstraint(name: 'tag_workspace_name_scope_unique', columns: ['workspace_id', 'name', 'scope'])]
#[ORM\HasLifecycleCallbacks]
class Tag
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\Column(length: 60)]
    private string $name;

    #[ORM\Column(length: 16)]
    private string $color = '#94a3b8';

    #[ORM\Column(length: 12, enumType: TagScope::class)]
    private TagScope $scope = TagScope::Any;

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

    public function getScope(): TagScope
    {
        return $this->scope;
    }

    public function setScope(TagScope $scope): self
    {
        $this->scope = $scope;
        return $this;
    }
}
