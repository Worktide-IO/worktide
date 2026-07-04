<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\ProposalOrigin;
use App\Entity\Enum\ProposalStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ProjectProposalRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A proposal pitched to a customer for a specific project — the portal
 * "Ideen-Pitch" screen (wireframe screen 7). The agency (or an AI suggestion)
 * presents a rationale, expected benefit, and a rough estimate (effort / cost /
 * timeframe), optionally with A/B {@see self::$variants}. The customer reviews
 * it (Neu → In Prüfung → Angenommen/Abgelehnt); accepting materializes a Task
 * (and, later, an offer — see {@see self::$convertedAgreement}).
 *
 * Distinct from {@see Idea} (the customer-level idea board with voting): a
 * proposal is project-coupled and carries estimates + a review workflow.
 */
#[ORM\Entity(repositoryClass: ProjectProposalRepository::class)]
#[ORM\Table(name: 'project_proposals')]
#[ORM\Index(name: 'project_proposal_project_idx', columns: ['project_id'])]
#[ORM\Index(name: 'project_proposal_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'project_proposal_status_idx', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ProjectProposal',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'project' => 'exact',
    'status' => 'exact',
    'origin' => 'exact',
    'title' => 'partial',
])]
#[ApiFilter(OrderFilter::class, properties: ['status', 'position', 'createdAt'])]
class ProjectProposal
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $title;

    /** Why we're proposing this ("Warum"). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rationale = null;

    /** Expected benefit ("Erwarteter Nutzen"). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $expectedBenefit = null;

    #[ORM\Column(nullable: true)]
    private ?int $effortHours = null;

    #[ORM\Column(nullable: true)]
    private ?int $costCents = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    /** Free-form timeframe label, e.g. "3 Wochen". */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $timeframeText = null;

    #[ORM\Column(length: 16, enumType: ProposalStatus::class, options: ['default' => 'new'])]
    private ProposalStatus $status = ProposalStatus::New;

    #[ORM\Column(length: 16, enumType: ProposalOrigin::class, options: ['default' => 'agency'])]
    private ProposalOrigin $origin = ProposalOrigin::Agency;

    /**
     * Optional A/B alternatives for comparison ("Varianten vergleichen").
     * Each: { label: string, effortHours?: int, costCents?: int }.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $variants = [];

    /** Latest customer question/feedback ("Rückfrage"); sets status to InReview. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $customerFeedback = null;

    /** Set when accepted → the work item created for it. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Task $convertedTask = null;

    /** Reserved for the eventual offer generated on accept. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CustomerAgreement $convertedAgreement = null;

    #[ORM\Column]
    private int $position = 0;

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $project): self
    {
        $this->project = $project;
        $this->setWorkspace($project->getWorkspace());
        return $this;
    }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    public function getRationale(): ?string { return $this->rationale; }
    public function setRationale(?string $v): self { $this->rationale = $v; return $this; }

    public function getExpectedBenefit(): ?string { return $this->expectedBenefit; }
    public function setExpectedBenefit(?string $v): self { $this->expectedBenefit = $v; return $this; }

    public function getEffortHours(): ?int { return $this->effortHours; }
    public function setEffortHours(?int $v): self { $this->effortHours = $v; return $this; }

    public function getCostCents(): ?int { return $this->costCents; }
    public function setCostCents(?int $v): self { $this->costCents = $v; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $v): self { $this->currency = $v; return $this; }

    public function getTimeframeText(): ?string { return $this->timeframeText; }
    public function setTimeframeText(?string $v): self { $this->timeframeText = $v; return $this; }

    public function getStatus(): ProposalStatus { return $this->status; }
    public function setStatus(ProposalStatus $v): self { $this->status = $v; return $this; }

    public function getOrigin(): ProposalOrigin { return $this->origin; }
    public function setOrigin(ProposalOrigin $v): self { $this->origin = $v; return $this; }

    /** @return list<array<string, mixed>> */
    public function getVariants(): array { return $this->variants; }

    /** @param list<array<string, mixed>> $variants */
    public function setVariants(array $variants): self { $this->variants = $variants; return $this; }

    public function getCustomerFeedback(): ?string { return $this->customerFeedback; }
    public function setCustomerFeedback(?string $v): self { $this->customerFeedback = $v; return $this; }

    public function getConvertedTask(): ?Task { return $this->convertedTask; }
    public function setConvertedTask(?Task $v): self { $this->convertedTask = $v; return $this; }

    public function getConvertedAgreement(): ?CustomerAgreement { return $this->convertedAgreement; }
    public function setConvertedAgreement(?CustomerAgreement $v): self { $this->convertedAgreement = $v; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): self { $this->position = $v; return $this; }
}
