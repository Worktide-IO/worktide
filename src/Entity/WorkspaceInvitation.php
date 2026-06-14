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
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\WorkspaceInvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Workspace invitation: an owner/admin types an email + role, this entity
 * stores a unique opaque accept-token, and the invitee turns into a
 * WorkspaceMember once they hit POST /v1/workspace_invitations/{token}/accept.
 *
 * Flow:
 *   POST /v1/workspace_invitations    →  status=pending, token returned ONCE
 *   POST .../{token}/accept           →  creates User if needed, links membership
 *                                        with the chosen role, status=accepted
 *   DELETE /v1/workspace_invitations/{id}  →  revokes a pending invite
 *
 * The token plaintext is shown only in the creation response so the operator
 * can ship a magic link to the invitee. After that, the DB column is the
 * opaque value we compare against incoming accepts (no hashing here — the
 * threat model is leaked email content, not a leaked DB dump; the token's
 * value is its unguessability, and acceptance permanently consumes it).
 *
 * Role validation prevents creating Owner invitations — workspace owners are
 * set by the workspace-creation flow, not handed out on email.
 */
#[ORM\Entity(repositoryClass: WorkspaceInvitationRepository::class)]
#[ORM\Table(name: 'workspace_invitations')]
#[ORM\UniqueConstraint(name: 'workspace_invitation_token_unique', columns: ['token'])]
#[ORM\Index(name: 'workspace_invitation_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'workspace_invitation_email_idx', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'WorkspaceInvitation',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('MANAGE', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'email' => 'partial',
    'status' => 'exact',
])]
class WorkspaceInvitation
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use AuditableTrait;

    public const DEFAULT_TTL_DAYS = 14;

    #[ORM\Column(length: 254)]
    #[Assert\Email]
    #[Assert\NotBlank]
    private string $email;

    #[ORM\Column(length: 16, enumType: WorkspaceMemberRole::class)]
    #[Assert\NotEqualTo(
        value: WorkspaceMemberRole::Owner,
        message: 'Workspace owners are not assignable via invitation.',
    )]
    private WorkspaceMemberRole $role = WorkspaceMemberRole::Member;

    /**
     * Opaque accept token — 64 hex chars from random_bytes(32). Stored as-is
     * because it's already high-entropy and short-lived; the value is only
     * returned once via the issuance processor.
     */
    #[ApiProperty(readable: false, writable: false)]
    #[ORM\Column(length: 64)]
    private string $token = '';

    #[ORM\Column(length: 16, enumType: InvitationStatus::class)]
    private InvitationStatus $status = InvitationStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $acceptedBy = null;

    /**
     * Plaintext invite URL (or just the raw token) — only populated on POST
     * so the response body carries it to the operator for copy-paste / mail
     * dispatch. Never persisted, never returned afterwards.
     */
    #[ApiProperty(readable: true, writable: false)]
    private ?string $plaintextToken = null;

    public function __construct()
    {
        $this->expiresAt = (new \DateTimeImmutable())->modify('+' . self::DEFAULT_TTL_DAYS . ' days');
    }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = mb_strtolower(trim($email)); return $this; }

    public function getRole(): WorkspaceMemberRole { return $this->role; }
    public function setRole(WorkspaceMemberRole $role): self { $this->role = $role; return $this; }

    public function getToken(): string { return $this->token; }
    public function setToken(string $token): self { $this->token = $token; return $this; }

    public function getStatus(): InvitationStatus { return $this->status; }
    public function setStatus(InvitationStatus $s): self { $this->status = $s; return $this; }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $when): self { $this->expiresAt = $when; return $this; }

    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }
    public function getAcceptedBy(): ?User { return $this->acceptedBy; }

    public function markAccepted(User $user): self
    {
        $this->status = InvitationStatus::Accepted;
        $this->acceptedAt = new \DateTimeImmutable();
        $this->acceptedBy = $user;
        return $this;
    }

    public function markRevoked(): self
    {
        if ($this->status === InvitationStatus::Pending) {
            $this->status = InvitationStatus::Revoked;
        }
        return $this;
    }

    public function isAcceptable(\DateTimeImmutable $now): bool
    {
        if ($this->status !== InvitationStatus::Pending) {
            return false;
        }
        if ($this->expiresAt <= $now) {
            $this->status = InvitationStatus::Expired;
            return false;
        }
        return true;
    }

    public function getPlaintextToken(): ?string { return $this->plaintextToken; }
    public function setPlaintextToken(?string $value): self { $this->plaintextToken = $value; return $this; }
}
