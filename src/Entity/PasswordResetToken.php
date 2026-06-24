<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\PasswordResetTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single-use, time-limited password-reset token.
 *
 * Account-level (NOT workspace-scoped) — a forgotten password is an
 * identity concern, not a workspace one. Deliberately NOT an API
 * Platform resource: it's never read or written over the public API,
 * only through {@see \App\Service\PasswordResetService} +
 * the /v1/auth/forgot-password and /v1/auth/reset-password endpoints.
 *
 * Unlike WorkspaceInvitation (which stores its token verbatim), we store
 * only the SHA-256 of the token. The plaintext lives solely in the email
 * link — so a leaked DB dump can't be used to reset anyone's password.
 * The token is consumed on first successful use (usedAt) and expires
 * after a short TTL.
 */
#[ORM\Entity(repositoryClass: PasswordResetTokenRepository::class)]
#[ORM\Table(name: 'password_reset_tokens')]
#[ORM\UniqueConstraint(name: 'password_reset_token_hash_unique', columns: ['token_hash'])]
#[ORM\Index(name: 'password_reset_user_idx', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class PasswordResetToken
{
    use EntityIdTrait;
    use TimestampableTrait;

    public const DEFAULT_TTL = '+1 hour';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** SHA-256 hex of the plaintext token (64 chars). Never the plaintext. */
    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct()
    {
        $this->expiresAt = (new \DateTimeImmutable())->modify(self::DEFAULT_TTL);
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;
        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $when): self
    {
        $this->expiresAt = $when;
        return $this;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function markUsed(): self
    {
        $this->usedAt = new \DateTimeImmutable();
        return $this;
    }
}
