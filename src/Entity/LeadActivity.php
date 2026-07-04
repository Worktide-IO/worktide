<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\LeadActivityChannel;
use App\Entity\Enum\LeadActivityType;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\LeadActivityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only event in a {@see Lead}'s acquisition history: stage changes,
 * outreach touchpoints (incl. forum posts), enrichment, notes. Gives the full
 * audit trail while {@see Lead::$stage} holds the fast current-state view.
 */
#[ORM\Entity(repositoryClass: LeadActivityRepository::class)]
#[ORM\Table(name: 'lead_activities')]
#[ORM\Index(name: 'lead_activity_lead_idx', columns: ['lead_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'LeadActivity',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'lead' => 'exact', 'type' => 'exact', 'channel' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['occurredAt', 'createdAt'])]
class LeadActivity
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Lead $lead;

    #[ORM\Column(length: 16, enumType: LeadActivityType::class)]
    private LeadActivityType $type = LeadActivityType::Note;

    #[ORM\Column(length: 16, enumType: LeadActivityChannel::class, nullable: true)]
    private ?LeadActivityChannel $channel = null;

    /** Free-form detail — e.g. {from, to} for stage_change, {threadUrl, postId} for forum_post. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    /** Who did it — null = the agent. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $outcome = null;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getLead(): Lead { return $this->lead; }
    public function setLead(Lead $lead): self { $this->lead = $lead; return $this; }

    public function getType(): LeadActivityType { return $this->type; }
    public function setType(LeadActivityType $type): self { $this->type = $type; return $this; }

    public function getChannel(): ?LeadActivityChannel { return $this->channel; }
    public function setChannel(?LeadActivityChannel $channel): self { $this->channel = $channel; return $this; }

    /** @return array<string, mixed>|null */
    public function getPayload(): ?array { return $this->payload; }
    /** @param array<string, mixed>|null $payload */
    public function setPayload(?array $payload): self { $this->payload = $payload; return $this; }

    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function setOccurredAt(\DateTimeImmutable $at): self { $this->occurredAt = $at; return $this; }

    public function getActor(): ?User { return $this->actor; }
    public function setActor(?User $actor): self { $this->actor = $actor; return $this; }

    public function getOutcome(): ?string { return $this->outcome; }
    public function setOutcome(?string $outcome): self { $this->outcome = $outcome; return $this; }
}
