<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshTokenRepository;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Refresh token storage for gesdinet/jwt-refresh-token-bundle.
 *
 * Owned by us so it can use auto-increment INT (matches the bundle's
 * interface expectation of `int|string|null`) while the rest of our schema
 * is UUIDv7.
 *
 * The metadata fields below (user_id, created_at, last_seen_at,
 * user_agent, ip_address) are populated by AuthSessionMetadataListener
 * right after login/refresh and back the "Aktive Sitzungen" UI under
 * /settings/profile → Sicherheit.
 */
#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_tokens')]
#[ORM\Index(name: 'refresh_user_idx', columns: ['user_id'])]
class RefreshToken implements RefreshTokenInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(name: 'refresh_token', type: 'string', length: 128, unique: true)]
    protected ?string $refreshToken = null;

    #[ORM\Column(type: 'string', length: 255)]
    protected ?string $username = null;

    #[ORM\Column(type: 'datetime')]
    protected ?\DateTimeInterface $valid = null;

    /**
     * UUID FK to the owning User. Denormalised from username so the
     * sessions endpoint can join without a SELECT-by-email roundtrip.
     */
    #[ORM\Column(name: 'user_id', type: 'uuid', nullable: true)]
    protected ?Uuid $userId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $lastSeenAt = null;

    /** Truncated User-Agent. Long enough to identify "Chrome on macOS 14". */
    #[ORM\Column(name: 'user_agent', type: 'string', length: 255, nullable: true)]
    protected ?string $userAgent = null;

    /** Source IP (IPv4 or IPv6); 45 chars fits an IPv6-mapped IPv4. */
    #[ORM\Column(name: 'ip_address', type: 'string', length: 45, nullable: true)]
    protected ?string $ipAddress = null;

    public function __construct(?string $refreshToken = null, ?string $username = null, ?\DateTimeInterface $valid = null)
    {
        $this->refreshToken = $refreshToken;
        $this->username = $username;
        $this->valid = $valid;
    }

    public static function createForUserWithTtl(string $refreshToken, UserInterface $user, int $ttl): static
    {
        $valid = new \DateTime();
        $valid->modify('+' . $ttl . ' seconds');

        return new static($refreshToken, $user->getUserIdentifier(), $valid);
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function setRefreshToken(string $refreshToken): static
    {
        $this->refreshToken = $refreshToken;
        return $this;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;
        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setValid(\DateTimeInterface $valid): static
    {
        $this->valid = $valid;
        return $this;
    }

    public function getValid(): ?\DateTimeInterface
    {
        return $this->valid;
    }

    public function isValid(): bool
    {
        return $this->valid !== null && $this->valid >= new \DateTime();
    }

    public function __toString(): string
    {
        return (string) $this->getRefreshToken();
    }

    public function getUserId(): ?Uuid { return $this->userId; }
    public function setUserId(?Uuid $id): static { $this->userId = $id; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $at): static { $this->createdAt = $at; return $this; }

    public function getLastSeenAt(): ?\DateTimeImmutable { return $this->lastSeenAt; }
    public function setLastSeenAt(?\DateTimeImmutable $at): static { $this->lastSeenAt = $at; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $ua): static { $this->userAgent = $ua === null ? null : substr($ua, 0, 255); return $this; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ip): static { $this->ipAddress = $ip; return $this; }
}
