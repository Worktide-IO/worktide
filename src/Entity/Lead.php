<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
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
use App\Entity\Enum\LeadSource;
use App\Entity\Enum\LeadStage;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TaggableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\LeadRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An acquisition target the research agent found or that was added manually — a
 * NOT-YET-customer. Deliberately a separate table from {@see Customer} so the
 * pipeline, scoring and discovery sources never pollute the CRM. On conversion
 * a real Customer is created and linked via {@see $convertedCustomer}. The
 * per-lead state history lives in {@see LeadActivity}.
 */
#[ORM\Entity(repositoryClass: LeadRepository::class)]
#[ORM\Table(name: 'leads')]
#[ORM\Index(name: 'lead_workspace_stage_idx', columns: ['workspace_id', 'stage'])]
#[ORM\Index(name: 'lead_mission_idx', columns: ['mission_id'])]
#[ORM\Index(name: 'lead_dedupe_idx', columns: ['workspace_id', 'dedupe_key'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Lead',
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
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'mission' => 'exact',
    'stage' => 'exact',
    'source' => 'exact',
    'name' => 'partial',
    'industry' => 'exact',
    'assignedTo' => 'exact',
    'tags.id' => 'exact',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isCompany'])]
#[ApiFilter(ExistsFilter::class, properties: ['deletedAt', 'convertedCustomer'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'fitScore', 'stage', 'createdAt', 'updatedAt'])]
class Lead implements TaggableInterface
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use AuditableTrait;
    use TaggableTrait;

    /** The mission that discovered this lead (null for manually added leads). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ResearchMission $mission = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isCompany = true;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    /** Job title / role of the person (or contact person at the company). */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $industry = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 16, enumType: LeadSource::class)]
    private LeadSource $source = LeadSource::Manual;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $sourceUrl = null;

    /** Provenance detail — e.g. forum thread/handle, search query, provider name. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $sourceDetail = null;

    /** Agent-computed fit 0–100. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $fitScore = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $scoreReason = null;

    #[ORM\Column(length: 16, enumType: LeadStage::class)]
    private LeadStage $stage = LeadStage::Discovered;

    /** email/domain used to dedupe against existing leads + customers. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dedupeKey = null;

    /** Set on conversion — the Customer this lead became. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Customer $convertedCustomer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }

    public function getMission(): ?ResearchMission { return $this->mission; }
    public function setMission(?ResearchMission $mission): self { $this->mission = $mission; return $this; }

    public function isCompany(): bool { return $this->isCompany; }
    public function setIsCompany(bool $v): self { $this->isCompany = $v; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = $phone; return $this; }

    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $website): self { $this->website = $website; return $this; }

    public function getRole(): ?string { return $this->role; }
    public function setRole(?string $role): self { $this->role = $role; return $this; }

    public function getIndustry(): ?string { return $this->industry; }
    public function setIndustry(?string $industry): self { $this->industry = $industry; return $this; }

    public function getRegion(): ?string { return $this->region; }
    public function setRegion(?string $region): self { $this->region = $region; return $this; }

    public function getSource(): LeadSource { return $this->source; }
    public function setSource(LeadSource $source): self { $this->source = $source; return $this; }

    public function getSourceUrl(): ?string { return $this->sourceUrl; }
    public function setSourceUrl(?string $url): self { $this->sourceUrl = $url; return $this; }

    /** @return array<string, mixed>|null */
    public function getSourceDetail(): ?array { return $this->sourceDetail; }
    /** @param array<string, mixed>|null $detail */
    public function setSourceDetail(?array $detail): self { $this->sourceDetail = $detail; return $this; }

    public function getFitScore(): ?int { return $this->fitScore; }
    public function setFitScore(?int $score): self { $this->fitScore = $score; return $this; }

    public function getScoreReason(): ?string { return $this->scoreReason; }
    public function setScoreReason(?string $reason): self { $this->scoreReason = $reason; return $this; }

    public function getStage(): LeadStage { return $this->stage; }
    public function setStage(LeadStage $stage): self { $this->stage = $stage; return $this; }

    public function getDedupeKey(): ?string { return $this->dedupeKey; }
    public function setDedupeKey(?string $key): self { $this->dedupeKey = $key; return $this; }

    public function getConvertedCustomer(): ?Customer { return $this->convertedCustomer; }
    public function setConvertedCustomer(?Customer $customer): self { $this->convertedCustomer = $customer; return $this; }

    public function getAssignedTo(): ?User { return $this->assignedTo; }
    public function setAssignedTo(?User $user): self { $this->assignedTo = $user; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
}
