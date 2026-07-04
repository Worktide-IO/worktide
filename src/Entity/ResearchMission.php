<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\ApiPlatform\Filter\UuidExactFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\ResearchMissionStatus;
use App\Entity\Enum\ResearchObjective;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ResearchMissionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A research/acquisition mission: a goal the research agent works on, from a
 * free-text employee prompt ("find 1000 key accounts as partners") or an
 * accepted proactive suggestion. Long-lived and stateful — unlike the one-shot
 * {@see AIRecommendation} seam — so it lives in its own table with a resumable
 * `state` cursor, a clarification dialog ({@see ResearchMissionMessage}) and the
 * leads it discovers ({@see Lead}).
 */
#[ORM\Entity(repositoryClass: ResearchMissionRepository::class)]
#[ORM\Table(name: 'research_missions')]
#[ORM\Index(name: 'research_mission_workspace_status_idx', columns: ['workspace_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ResearchMission',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(UuidExactFilter::class, properties: ['id'])]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'status' => 'exact', 'objective' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'updatedAt', 'status'])]
class ResearchMission
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use AuditableTrait;

    /** The raw employee instruction. */
    #[ORM\Column(type: 'text')]
    private string $prompt = '';

    #[ORM\Column(length: 20, enumType: ResearchObjective::class)]
    private ResearchObjective $objective = ResearchObjective::General;

    /** How the mission was created: prompt | suggestion | schedule. */
    #[ORM\Column(length: 16)]
    private string $createdVia = 'prompt';

    /** Normalized task after clarification: {targetCount, segment, criteria[], region, productId, …}. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $brief = null;

    #[ORM\Column(length: 16, enumType: ResearchMissionStatus::class)]
    private ResearchMissionStatus $status = ResearchMissionStatus::Draft;

    /** Agent working memory / cursor — makes a run resumable across retries. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $state = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $foundCount = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $targetCount = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summary = null;

    public function getPrompt(): string { return $this->prompt; }
    public function setPrompt(string $prompt): self { $this->prompt = $prompt; return $this; }

    public function getObjective(): ResearchObjective { return $this->objective; }
    public function setObjective(ResearchObjective $objective): self { $this->objective = $objective; return $this; }

    public function getCreatedVia(): string { return $this->createdVia; }
    public function setCreatedVia(string $via): self { $this->createdVia = $via; return $this; }

    /** @return array<string, mixed>|null */
    public function getBrief(): ?array { return $this->brief; }
    /** @param array<string, mixed>|null $brief */
    public function setBrief(?array $brief): self { $this->brief = $brief; return $this; }

    public function getStatus(): ResearchMissionStatus { return $this->status; }
    public function setStatus(ResearchMissionStatus $status): self { $this->status = $status; return $this; }

    /** @return array<string, mixed>|null */
    public function getState(): ?array { return $this->state; }
    /** @param array<string, mixed>|null $state */
    public function setState(?array $state): self { $this->state = $state; return $this; }

    public function getFoundCount(): int { return $this->foundCount; }
    public function setFoundCount(int $n): self { $this->foundCount = $n; return $this; }

    public function getTargetCount(): ?int { return $this->targetCount; }
    public function setTargetCount(?int $n): self { $this->targetCount = $n; return $this; }

    public function getSummary(): ?string { return $this->summary; }
    public function setSummary(?string $summary): self { $this->summary = $summary; return $this; }
}
