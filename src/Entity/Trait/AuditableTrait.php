<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Records who created and last updated the entity.
 *
 * Values are auto-populated by AuditableEntityListener from the Security
 * token whenever an authenticated request mutates the entity. For unauthenticated
 * paths (CLI fixtures, internal cron) the columns remain null.
 */
trait AuditableTrait
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdByUser = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedByUser = null;

    public function getCreatedByUser(): ?User
    {
        return $this->createdByUser;
    }

    public function setCreatedByUser(?User $user): self
    {
        $this->createdByUser = $user;
        return $this;
    }

    public function getUpdatedByUser(): ?User
    {
        return $this->updatedByUser;
    }

    public function setUpdatedByUser(?User $user): self
    {
        $this->updatedByUser = $user;
        return $this;
    }
}
