<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BackedEnumFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use App\ApiPlatform\Filter\UuidExactFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Enum\RecommendationKind;
use App\Entity\Enum\RecommendationStatus;
use App\Entity\Enum\RecommendationTarget;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\AIRecommendationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A human-in-the-loop AI suggestion for a ticket (Task or Conversation).
 *
 * The agent never mutates the ticket directly: it writes a Pending
 * recommendation, a reviewer accepts (applied via {@see \App\Service\Ai\RecommendationApplier})
 * or rejects. Polymorphic (target, targetId) like {@see Comment}. The concrete
 * proposal lives in {@see $suggestion} (JSON), whose shape depends on the
 * target — e.g. Task: {summary, tracker, priority, tags[]};
 * Conversation: {summary, status}.
 *
 * Read-only over the API — the state machine only advances through the
 * dedicated accept/reject endpoints. {@see OutboundMessage::$createdByRecommendationId}
 * links back here when a reply was drafted from a recommendation.
 */
#[ORM\Entity(repositoryClass: AIRecommendationRepository::class)]
#[ORM\Table(name: 'ai_recommendations')]
#[ORM\Index(name: 'ai_reco_target_idx', columns: ['target', 'target_id'])]
#[ORM\Index(name: 'ai_reco_workspace_status_idx', columns: ['workspace_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'AIRecommendation',
    // Read-only over the API; the state machine advances only through the
    // dedicated accept/reject controllers. WorkspaceScopeExtension auto-scopes
    // both queries to the caller's workspaces (no per-object voter needed).
    operations: [
        new GetCollection(uriTemplate: '/ai_recommendations{._format}', security: "is_granted('ROLE_USER')"),
        new Get(uriTemplate: '/ai_recommendations/{id}{._format}', security: "is_granted('ROLE_USER')"),
    ],
    mercure: true,
)]
// SearchFilter can't match the native-enum columns nor the binary uuid column,
// so use the dedicated filters: BackedEnumFilter for target/kind/status,
// UuidBinaryFilter for targetId. workspace (a relation IRI) stays on SearchFilter.
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact'])]
#[ApiFilter(BackedEnumFilter::class, properties: ['target', 'kind', 'status'])]
#[ApiFilter(UuidExactFilter::class, properties: ['targetId'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
class AIRecommendation
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\Column(length: 16, enumType: RecommendationTarget::class)]
    private RecommendationTarget $target;

    #[ORM\Column(type: 'uuid')]
    private Uuid $targetId;

    #[ORM\Column(length: 32, enumType: RecommendationKind::class)]
    private RecommendationKind $kind = RecommendationKind::Triage;

    #[ORM\Column(length: 16, enumType: RecommendationStatus::class)]
    private RecommendationStatus $status = RecommendationStatus::Pending;

    /** @var array<string, mixed> The validated, structured proposal. */
    #[ORM\Column(type: 'json')]
    private array $suggestion = [];

    /** Markdown rationale the model gave for the suggestion. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reasoning = null;

    /** The LLM model identifier that produced this. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $model = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    public function getTarget(): RecommendationTarget { return $this->target; }
    public function setTarget(RecommendationTarget $target): self { $this->target = $target; return $this; }

    public function getTargetId(): Uuid { return $this->targetId; }
    public function setTargetId(Uuid $targetId): self { $this->targetId = $targetId; return $this; }

    public function getKind(): RecommendationKind { return $this->kind; }
    public function setKind(RecommendationKind $kind): self { $this->kind = $kind; return $this; }

    public function getStatus(): RecommendationStatus { return $this->status; }
    public function setStatus(RecommendationStatus $status): self { $this->status = $status; return $this; }

    /** @return array<string, mixed> */
    public function getSuggestion(): array { return $this->suggestion; }
    /** @param array<string, mixed> $suggestion */
    public function setSuggestion(array $suggestion): self { $this->suggestion = $suggestion; return $this; }

    public function getReasoning(): ?string { return $this->reasoning; }
    public function setReasoning(?string $reasoning): self { $this->reasoning = $reasoning; return $this; }

    public function getModel(): ?string { return $this->model; }
    public function setModel(?string $model): self { $this->model = $model; return $this; }

    public function getReviewedBy(): ?User { return $this->reviewedBy; }
    public function setReviewedBy(?User $user): self { $this->reviewedBy = $user; return $this; }

    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function setReviewedAt(?\DateTimeImmutable $at): self { $this->reviewedAt = $at; return $this; }
}
