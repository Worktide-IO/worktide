<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\ProjectHealth;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ProjectStatusUpdateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A point-in-time status report for a Project — the structured "where do we
 * stand" post a project lead writes for stakeholders. Forms a per-project feed
 * ordered by {@see TimestampableTrait::$createdAt}; the latest one is the
 * project's current health.
 *
 * Goes beyond a free-text note by splitting the body into the three sections a
 * status report always has (matching the roadmap):
 *   - {@see $summary}   — was läuft (what's happening / progress)
 *   - {@see $risks}     — risks / blockers
 *   - {@see $nextSteps} — what's coming next
 * plus a {@see ProjectHealth} RAG signal.
 *
 * The author is the authenticated user, auto-stamped via {@see AuditableTrait}
 * (`createdByUser`); no separate author field. Registered in
 * {@see \App\EventSubscriber\DomainEventEmitterSubscriber} so posting an update
 * drives the activity log + outbound webhooks, reaching project watchers the
 * same way milestones and comments do.
 */
#[ORM\Entity(repositoryClass: ProjectStatusUpdateRepository::class)]
#[ORM\Table(name: 'project_status_updates')]
#[ORM\Index(name: 'status_update_project_idx', columns: ['project_id'])]
#[ORM\Index(name: 'status_update_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ProjectStatusUpdate',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object)"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object)"),
        new Delete(security: "is_granted('DELETE', object)"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'project' => 'exact',
    'health' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['createdAt'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'health'])]
class ProjectStatusUpdate
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

    #[ORM\Column(length: 12, enumType: ProjectHealth::class, options: ['default' => 'on_track'])]
    private ProjectHealth $health = ProjectHealth::OnTrack;

    /** Optional short headline; the feed falls back to the health label without it. */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $title = null;

    /** "Was läuft" — overall progress / what's happening. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summary = null;

    /** Risks / blockers. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $risks = null;

    /** What's coming next. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $nextSteps = null;

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $project): self { $this->project = $project; return $this; }

    public function getHealth(): ProjectHealth { return $this->health; }
    public function setHealth(ProjectHealth $health): self { $this->health = $health; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): self { $this->title = $title; return $this; }

    public function getSummary(): ?string { return $this->summary; }
    public function setSummary(?string $summary): self { $this->summary = $summary; return $this; }

    public function getRisks(): ?string { return $this->risks; }
    public function setRisks(?string $risks): self { $this->risks = $risks; return $this; }

    public function getNextSteps(): ?string { return $this->nextSteps; }
    public function setNextSteps(?string $nextSteps): self { $this->nextSteps = $nextSteps; return $this; }
}
