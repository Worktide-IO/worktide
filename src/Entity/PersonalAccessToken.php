<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\PersonalAccessTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Long-lived bearer token a user mints for an external system (CI, custom
 * dashboard, integration script). Authenticates against /v1/* the same way
 * a JWT does, but doesn't expire on the 1-hour cycle.
 *
 * The token plaintext is shown EXACTLY ONCE — in the response to the POST
 * that creates it (see PersonalAccessTokenIssueProcessor). After that only
 * the SHA-256 hash sits in the DB, so leaked tokens can be revoked without
 * exposing the value to the recovery process.
 *
 * Scope strings let the operator narrow what the token can do:
 *   - ["*"]            — full ROLE_USER (default)
 *   - ["read:*"]       — only safe-method endpoints
 *   - ["projects:read","tasks:write"]  — fine-grained per-resource
 *
 * Scopes are advisory in the firewall — Voters still enforce VIEW/EDIT/…
 * authorization. They primarily limit a stolen token's blast radius.
 */
#[ORM\Entity(repositoryClass: PersonalAccessTokenRepository::class)]
#[ORM\Table(name: 'personal_access_tokens')]
#[ORM\UniqueConstraint(name: 'pat_token_hash_unique', columns: ['token_hash'])]
#[ORM\Index(name: 'pat_owner_idx', columns: ['owner_id'])]
#[ORM\Index(name: 'pat_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'PersonalAccessToken',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('VIEW', object.getWorkspace())"),
        new Delete(security: "is_granted('VIEW', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'owner' => 'exact', 'name' => 'partial'])]
#[ApiFilter(BooleanFilter::class, properties: ['isRevoked'])]
class PersonalAccessToken
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /**
     * SHA-256 hex of the token plaintext. Stored hashed so leaked DB dumps
     * don't yield usable credentials; the lookup helper hashes the inbound
     * header and matches on this column.
     */
    #[ApiProperty(readable: false, writable: false)]
    #[ORM\Column(length: 64)]
    private string $tokenHash;

    /**
     * Short prefix of the plaintext (the first 8 chars). Lets the operator
     * recognise tokens in lists without exposing the secret. Persisted on
     * create only.
     */
    #[ORM\Column(length: 16)]
    private string $tokenPrefix = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $scopes = ['*'];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * Plaintext token — only populated by the issue processor on POST so it
     * reaches the response body. Not persisted to the DB and not echoed by
     * subsequent GETs (the issue processor is only wired up for POST).
     */
    #[ApiProperty(readable: true, writable: false)]
    private ?string $plaintextToken = null;

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getOwner(): User { return $this->owner; }
    public function setOwner(User $owner): self { $this->owner = $owner; return $this; }

    public function getTokenHash(): string { return $this->tokenHash; }
    public function setTokenHash(string $hash): self { $this->tokenHash = $hash; return $this; }

    public function getTokenPrefix(): string { return $this->tokenPrefix; }
    public function setTokenPrefix(string $prefix): self { $this->tokenPrefix = $prefix; return $this; }

    /** @return list<string> */
    public function getScopes(): array { return $this->scopes; }
    /** @param list<string> $scopes */
    public function setScopes(array $scopes): self { $this->scopes = array_values($scopes); return $this; }

    public function getLastUsedAt(): ?\DateTimeImmutable { return $this->lastUsedAt; }
    public function setLastUsedAt(?\DateTimeImmutable $when): self { $this->lastUsedAt = $when; return $this; }

    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $when): self { $this->expiresAt = $when; return $this; }

    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function revoke(): self { $this->revokedAt ??= new \DateTimeImmutable(); return $this; }

    public function isRevoked(): bool { return $this->revokedAt !== null; }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }

    public function getPlaintextToken(): ?string { return $this->plaintextToken; }
    public function setPlaintextToken(?string $value): self { $this->plaintextToken = $value; return $this; }
}
