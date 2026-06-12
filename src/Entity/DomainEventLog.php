<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Trait\EntityIdTrait;
use App\Repository\DomainEventLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Immutable event log. UUIDv7 PK gives a natural monotonic cursor —
 * subscribers paginate by "id greater than last seen".
 */
#[ORM\Entity(repositoryClass: DomainEventLogRepository::class)]
#[ORM\Table(name: 'domain_events')]
#[ORM\Index(name: 'domain_event_name_idx', columns: ['name'])]
#[ORM\Index(name: 'domain_event_aggregate_idx', columns: ['aggregate_type', 'aggregate_id'])]
#[ORM\Index(name: 'domain_event_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'domain_event_occurred_idx', columns: ['occurred_at'])]
#[ApiResource(
    shortName: 'DomainEvent',
    operations: [
        new GetCollection(),
        new Get(),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial',
    'aggregateType' => 'exact',
    'aggregateId' => 'exact',
    'workspace' => 'exact',
    'actor' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['occurredAt'])]
#[ApiFilter(OrderFilter::class, properties: ['occurredAt'])]
class DomainEventLog
{
    use EntityIdTrait;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 80)]
    private string $aggregateType;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $aggregateId = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Workspace $workspace = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload = [];

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $name,
        string $aggregateType,
        ?Uuid $aggregateId,
        ?Workspace $workspace,
        ?User $actor,
        array $payload,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        $this->name = $name;
        $this->aggregateType = $aggregateType;
        $this->aggregateId = $aggregateId;
        $this->workspace = $workspace;
        $this->actor = $actor;
        $this->payload = $payload;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAggregateType(): string
    {
        return $this->aggregateType;
    }

    public function getAggregateId(): ?Uuid
    {
        return $this->aggregateId;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
