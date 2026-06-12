<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\Workspace;
use Doctrine\ORM\Mapping as ORM;

trait WorkspaceScopedTrait
{
    #[ORM\ManyToOne(inversedBy: null)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function setWorkspace(Workspace $workspace): self
    {
        $this->workspace = $workspace;
        return $this;
    }
}
