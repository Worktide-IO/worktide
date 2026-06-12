<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshTokenRepository;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refresh token storage for gesdinet/jwt-refresh-token-bundle.
 *
 * Owned by us so it can use auto-increment INT (matches the bundle's
 * interface expectation of `int|string|null`) while the rest of our schema
 * is UUIDv7.
 */
#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_tokens')]
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
}
