<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ProjectMemberRole;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\ProjectShareRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An accepted cross-workspace project share: the project (in workspace A) is
 * made available to workspace B for collaboration. Created by
 * {@see \App\Controller\Api\ProjectShareInvitationAcceptController} when an
 * invitee accepts.
 *
 * Consumed by the query scoping ({@see \App\ApiPlatform\Doctrine\WorkspaceScopeExtension})
 * and the voters to grant B's members access to the project + its tasks/
 * comments/time-entries — without B ever becoming a member of A. Not an
 * ApiResource: the shared project simply appears in B's normal project list via
 * the extended scope; share management on the A side goes through
 * ProjectShareInvitation.
 */
#[ORM\Entity(repositoryClass: ProjectShareRepository::class)]
#[ORM\Table(name: 'project_shares')]
#[ORM\UniqueConstraint(name: 'project_share_project_workspace_unique', columns: ['project_id', 'shared_with_workspace_id'])]
#[ORM\Index(name: 'project_share_workspace_idx', columns: ['shared_with_workspace_id'])]
#[ORM\Index(name: 'project_share_project_idx', columns: ['project_id'])]
#[ORM\HasLifecycleCallbacks]
class ProjectShare
{
    use EntityIdTrait;
    use TimestampableTrait;

    /** The shared project (lives in workspace A). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    /** The workspace (B) the project is shared into. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $sharedWithWorkspace;

    #[ORM\Column(length: 16, enumType: ProjectMemberRole::class)]
    private ProjectMemberRole $role = ProjectMemberRole::Contributor;

    /** The B-side user who accepted the invitation. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $acceptedBy = null;

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $project): self { $this->project = $project; return $this; }

    public function getSharedWithWorkspace(): Workspace { return $this->sharedWithWorkspace; }
    public function setSharedWithWorkspace(Workspace $workspace): self { $this->sharedWithWorkspace = $workspace; return $this; }

    public function getRole(): ProjectMemberRole { return $this->role; }
    public function setRole(ProjectMemberRole $role): self { $this->role = $role; return $this; }

    public function getAcceptedBy(): ?User { return $this->acceptedBy; }
    public function setAcceptedBy(?User $user): self { $this->acceptedBy = $user; return $this; }
}
