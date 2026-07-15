<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\TaskOfferDismissalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Records that a staff member declined a role-based ticket offer, so the
 * dismissed task stops appearing in that user's offer list (Phase 2). Not an
 * API resource — written/read only via the task-offer controllers.
 */
#[ORM\Entity(repositoryClass: TaskOfferDismissalRepository::class)]
#[ORM\Table(name: 'task_offer_dismissals')]
#[ORM\UniqueConstraint(name: 'task_offer_dismissal_unique', columns: ['user_id', 'task_id'])]
#[ORM\Index(name: 'task_offer_dismissal_user_idx', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class TaskOfferDismissal
{
    use EntityIdTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getTask(): Task { return $this->task; }
    public function setTask(Task $task): self { $this->task = $task; return $this; }
}
