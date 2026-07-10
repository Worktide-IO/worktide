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
use App\Entity\Trait\VersionedTrait;
use Symfony\Component\Validator\Constraints as Assert;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\WebhookRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Subscription that mirrors selected DomainEvents to an external HTTP endpoint.
 *
 * Each delivery is signed with HMAC-SHA256 over the request body, using `secret`
 * as the key; consumers verify by recomputing the digest and comparing it to the
 * `X-Worktide-Signature: sha256=…` header.
 *
 * Event subscription matches by name:
 *   - `["*"]`                  — every event
 *   - `["task.*"]`             — every event whose name starts with "task."
 *   - `["task.created"]`       — exact name only
 *
 * The `secret` is write-only via the API (returned obfuscated on reads) so it
 * never leaks back to clients after creation. `failureCount` and
 * `lastTriggeredAt` are server-managed; clients cannot patch them.
 *
 * After {@see self::FAILURE_THRESHOLD} consecutive non-2xx deliveries the
 * webhook auto-deactivates — the operator must inspect the deliveries log and
 * re-enable it with a fresh PATCH.
 */
#[ORM\Entity(repositoryClass: WebhookRepository::class)]
#[ORM\Table(name: 'webhooks')]
#[ORM\Index(name: 'webhook_workspace_active_idx', columns: ['workspace_id', 'is_active'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Webhook',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'name' => 'partial'])]
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
class Webhook
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    /** Auto-deactivate after this many consecutive non-2xx deliveries. */
    public const FAILURE_THRESHOLD = 20;

    #[ORM\Column(length: 200)]
    private string $name = '';

    // Format check only (http/https, well-formed). The real SSRF protection —
    // rejecting private/internal targets — happens at delivery time in
    // SendWebhookHandler via OutboundUrlGuard, since a static constraint can't
    // safely resolve DNS or defend against rebinding.
    #[Assert\Url(protocols: ['http', 'https'], requireTld: false)]
    #[ORM\Column(length: 2048)]
    private string $url;

    /**
     * HMAC-SHA256 key used to sign outgoing requests. `readable: false` keeps
     * the secret out of every GET / list response so once the operator has set
     * it the API never echoes it back. The setter is reachable via a custom
     * state processor (WebhookSecretProcessor) — the standard ApiProperty
     * `writable: true` is unreliable for write-only properties in API
     * Platform 4 because the denormalizer also respects `readable: false`.
     */
    #[ApiProperty(readable: false, writable: false)]
    #[ORM\Column(length: 200)]
    private string $secret;

    /**
     * Event-name patterns this webhook subscribes to.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $eventTypes = ['*'];

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private int $failureCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastTriggeredAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSucceededAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastFailedAt = null;

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getUrl(): string { return $this->url; }
    public function setUrl(string $url): self { $this->url = $url; return $this; }

    public function getSecret(): string { return $this->secret; }
    public function setSecret(string $secret): self { $this->secret = $secret; return $this; }

    /** @return list<string> */
    public function getEventTypes(): array { return $this->eventTypes; }
    /** @param list<string> $types */
    public function setEventTypes(array $types): self { $this->eventTypes = array_values($types); return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): self { $this->isActive = $v; return $this; }

    public function getFailureCount(): int { return $this->failureCount; }
    public function setFailureCount(int $n): self { $this->failureCount = $n; return $this; }

    public function getLastTriggeredAt(): ?\DateTimeImmutable { return $this->lastTriggeredAt; }
    public function setLastTriggeredAt(?\DateTimeImmutable $when): self { $this->lastTriggeredAt = $when; return $this; }

    public function getLastSucceededAt(): ?\DateTimeImmutable { return $this->lastSucceededAt; }
    public function setLastSucceededAt(?\DateTimeImmutable $when): self { $this->lastSucceededAt = $when; return $this; }

    public function getLastFailedAt(): ?\DateTimeImmutable { return $this->lastFailedAt; }
    public function setLastFailedAt(?\DateTimeImmutable $when): self { $this->lastFailedAt = $when; return $this; }

    /**
     * Does this webhook subscribe to the given event name?
     * Supports "*" wildcard and "prefix.*" patterns.
     */
    public function matches(string $eventName): bool
    {
        foreach ($this->eventTypes as $pattern) {
            if ($pattern === '*' || $pattern === $eventName) {
                return true;
            }
            if (\str_ends_with($pattern, '.*')) {
                $prefix = \substr($pattern, 0, -1); // keep trailing dot
                if (\str_starts_with($eventName, $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }
}
