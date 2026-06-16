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
use App\Entity\Enum\VersionSharing;
use App\Entity\Enum\VersionStatus;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ProjectVersionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Release / Version of a Project — the "ship target" axis on top of
 * Project + Tracker + Status. Tasks can be tagged with `fixedVersion`
 * to mark "this work goes into Release 1.2.0".
 *
 * Named `ProjectVersion` (not `Version`) to avoid clashing with the
 * VersionedTrait + Symfony's `Symfony\Component\Console\Application::VERSION`
 * + general naming clarity (we already have `version: int` optimistic
 * locking on most entities).
 *
 * Sharing field is reserved for the future Redmine-style cross-project
 * release model — for now Worktide ships only `none` (this project) and
 * `system` (every project in the workspace). The other three enum
 * values exist so the column doesn't need a migration when sub-projects
 * land in a later phase.
 *
 * effectiveDate is the planned ship date — burndown / release reports
 * use it as the X-axis anchor. When `status=closed`, the SPA treats
 * effectiveDate as the actual release date.
 *
 * wikiPageTitle is a plain string (not an FK) because the Document a
 * release-page lives at can be in any space; storing only the title +
 * the workspace lets the SPA look it up by name without forcing a
 * link-table that gets stale when pages are renamed.
 */
#[ORM\Entity(repositoryClass: ProjectVersionRepository::class)]
#[ORM\Table(name: 'project_versions')]
#[ORM\UniqueConstraint(name: 'project_version_project_name_unique', columns: ['project_id', 'name'])]
#[ORM\Index(name: 'project_version_project_idx', columns: ['project_id'])]
#[ORM\Index(name: 'project_version_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'project_version_effective_idx', columns: ['effective_date'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ProjectVersion',
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
    'name' => 'partial',
    'status' => 'exact',
    'sharing' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['effectiveDate'])]
#[ApiFilter(OrderFilter::class, properties: ['effectiveDate', 'name', 'createdAt'])]
class ProjectVersion
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

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $effectiveDate = null;

    #[ORM\Column(length: 12, enumType: VersionStatus::class, options: ['default' => 'open'])]
    private VersionStatus $status = VersionStatus::Open;

    #[ORM\Column(length: 12, enumType: VersionSharing::class, options: ['default' => 'none'])]
    private VersionSharing $sharing = VersionSharing::None;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $wikiPageTitle = null;

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $project): self { $this->project = $project; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getEffectiveDate(): ?\DateTimeImmutable { return $this->effectiveDate; }
    public function setEffectiveDate(?\DateTimeImmutable $d): self { $this->effectiveDate = $d; return $this; }

    public function getStatus(): VersionStatus { return $this->status; }
    public function setStatus(VersionStatus $s): self { $this->status = $s; return $this; }

    public function getSharing(): VersionSharing { return $this->sharing; }
    public function setSharing(VersionSharing $s): self { $this->sharing = $s; return $this; }

    public function getWikiPageTitle(): ?string { return $this->wikiPageTitle; }
    public function setWikiPageTitle(?string $title): self { $this->wikiPageTitle = $title; return $this; }
}
