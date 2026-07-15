<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\MagicLoginTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single-use, short-lived token that logs a portal user in without a password.
 *
 * Today it backs **staff impersonation**: a staff member issues a link to
 * preview the portal AS a given contact (see
 * {@see \App\Service\Portal\MagicLinkService}). {@see $user} is the target
 * portal account (ROLE_PORTAL), {@see $issuedByUser} the staff member who
 * created it — recorded for audit.
 *
 * Mirrors {@see PasswordResetToken}: account-level, NOT an API Platform
 * resource, stored only as the SHA-256 of the token (plaintext lives solely in
 * the emitted URL), single-use ({@see $usedAt}) and short TTL — a magic login is
 * a one-click action, so the window is tighter than a password reset.
 */
#[ORM\Entity(repositoryClass: MagicLoginTokenRepository::class)]
#[ORM\Table(name: 'magic_login_tokens')]
#[ORM\UniqueConstraint(name: 'magic_login_token_hash_unique', columns: ['token_hash'])]
#[ORM\Index(name: 'magic_login_user_idx', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class MagicLoginToken
{
    use EntityIdTrait;
    use TimestampableTrait;

    public const DEFAULT_TTL = '+15 minutes';

    /** The portal account this link logs in as. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The staff member who issued the link (impersonation audit). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $issuedByUser = null;

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

    public function getIssuedByUser(): ?User
    {
        return $this->issuedByUser;
    }

    public function setIssuedByUser(?User $user): self
    {
        $this->issuedByUser = $user;
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
