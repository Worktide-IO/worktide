<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\InvitationStatus;
use App\Entity\Enum\ProjectMemberRole;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ProjectShareInvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Cross-workspace project share invitation.
 *
 * A manager/admin of the *sharing* workspace (A, where the project lives) types
 * an email + collaboration role; this stores an opaque accept-token and mails a
 * magic link. When the invitee accepts (POST .../{token}/accept) while logged
 * into their own workspace (B), a {@see ProjectShare} is created linking the
 * project into B — so B's members collaborate on A's project without ever
 * becoming members of A.
 *
 * Modeled on {@see WorkspaceInvitation}; `workspace` (via WorkspaceScopedTrait)
 * is the SHARING workspace A, so the existing workspace scoping keeps these
 * invitations visible only to A's members.
 */
#[ORM\Entity(repositoryClass: ProjectShareInvitationRepository::class)]
#[ORM\Table(name: 'project_share_invitations')]
#[ORM\UniqueConstraint(name: 'project_share_invitation_token_unique', columns: ['token'])]
#[ORM\Index(name: 'project_share_invitation_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'project_share_invitation_project_idx', columns: ['project_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ProjectShareInvitation',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getProject())"),
        // Issuance is gated to project managers / workspace admins in the
        // state processor (needs MANAGE on the project being shared).
        new Post(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('MANAGE', object.getProject())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'project' => 'exact',
    'email' => 'partial',
    'status' => 'exact',
])]
class ProjectShareInvitation
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use AuditableTrait;

    public const DEFAULT_TTL_DAYS = 14;

    /** The project (in workspace A) being shared. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 254)]
    #[Assert\Email]
    #[Assert\NotBlank]
    private string $email;

    /** Collaboration role granted to the target workspace on accept. */
    #[ORM\Column(length: 16, enumType: ProjectMemberRole::class)]
    private ProjectMemberRole $role = ProjectMemberRole::Contributor;

    /** Opaque accept token — 64 hex chars from random_bytes(32), single-use. */
    #[ApiProperty(readable: false, writable: false)]
    #[ORM\Column(length: 64)]
    private string $token = '';

    #[ORM\Column(length: 16, enumType: InvitationStatus::class)]
    private InvitationStatus $status = InvitationStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(options: ['default' => 0])]
    private int $sendCount = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $acceptedBy = null;

    /**
     * Plaintext accept token — only populated on the POST response so the
     * operator can build/ship the magic link. Never persisted separately.
     */
    #[ApiProperty(writable: false)]
    private ?string $plainToken = null;

    public function __construct()
    {
        $this->expiresAt = new \DateTimeImmutable(\sprintf('+%d days', self::DEFAULT_TTL_DAYS));
    }

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $project): self { $this->project = $project; return $this; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = $email; return $this; }

    public function getRole(): ProjectMemberRole { return $this->role; }
    public function setRole(ProjectMemberRole $role): self { $this->role = $role; return $this; }

    public function getToken(): string { return $this->token; }
    public function setToken(string $token): self { $this->token = $token; return $this; }

    public function getStatus(): InvitationStatus { return $this->status; }
    public function setStatus(InvitationStatus $status): self { $this->status = $status; return $this; }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $when): self { $this->expiresAt = $when; return $this; }

    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }
    public function setAcceptedAt(?\DateTimeImmutable $when): self { $this->acceptedAt = $when; return $this; }

    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }

    public function getSendCount(): int { return $this->sendCount; }

    public function markSent(): self
    {
        $this->sentAt = new \DateTimeImmutable();
        $this->sendCount++;
        return $this;
    }

    public function getAcceptedBy(): ?User { return $this->acceptedBy; }
    public function setAcceptedBy(?User $user): self { $this->acceptedBy = $user; return $this; }

    public function getPlainToken(): ?string { return $this->plainToken; }
    public function setPlainToken(?string $token): self { $this->plainToken = $token; return $this; }

    public function isPending(): bool { return $this->status === InvitationStatus::Pending; }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }
}
