<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\TaskPriority;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\PublicFormRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A publicly reachable form. Anyone with the {@see $slug} can GET its schema and
 * POST a submission at `/v1/forms/<slug>` — no authentication; the slug is the
 * only credential, so those routes live behind PUBLIC_ACCESS in security.yaml.
 *
 * Each valid submission directly materializes a {@see Task} in {@see $project}
 * (status / tracker / priority from the form defaults) and an audit
 * {@see PublicFormSubmission} row. There is no moderation queue — a submission
 * becomes a task immediately.
 *
 * Admin CRUD is exposed under `/v1/public_forms` (shortName-derived), kept
 * distinct from the public `/v1/forms/{slug}` controller routes.
 *
 * {@see $fields} is the form schema: a list of field definitions
 *   { key, label, type, required, options?, mapsTo? }
 * where `mapsTo` routes the submitted value to a native task field
 * (`title` | `description` | `priority`), to a custom field (`cf:<key>`,
 * resolved against {@see CustomFieldDefinition} by (workspace, target=task, key)),
 * or — when omitted — only into the recorded submission payload.
 *
 * Submission processing lives in {@see \App\Service\PublicFormSubmissionService}.
 */
#[ORM\Entity(repositoryClass: PublicFormRepository::class)]
#[ORM\Table(name: 'public_forms')]
#[ORM\UniqueConstraint(name: 'public_form_slug_unique', columns: ['slug'])]
#[ORM\Index(name: 'public_form_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'public_form_project_idx', columns: ['project_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'PublicForm',
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
    'slug' => 'exact',
    'title' => 'partial',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isEnabled'])]
#[ApiFilter(OrderFilter::class, properties: ['title', 'slug', 'createdAt'])]
class PublicForm
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    /** Public URL path segment — globally unique, lowercase kebab. */
    #[ORM\Column(length: 60)]
    private string $slug;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Shown to the visitor after a successful submission. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $successMessage = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isEnabled = true;

    /** Target project — submissions create tasks here. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    /** Status applied to created tasks; null falls back to the workspace default. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TaskStatus $defaultStatus = null;

    /** Tracker applied to created tasks; null lets the workspace default apply. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Tracker $defaultTracker = null;

    #[ORM\Column(length: 8, enumType: TaskPriority::class, nullable: true)]
    private ?TaskPriority $defaultPriority = null;

    /**
     * Legacy flat form schema (schema v1). Each entry:
     *   { "key": string, "label": string, "type": string,
     *     "required"?: bool, "options"?: list<string>, "mapsTo"?: string }
     *
     * Still the storage for v1 forms and always the fallback the
     * {@see \App\Service\Form\FormSchemaNormalizer} synthesises a v2 document
     * from when {@see $schema} is null. New engine capabilities live in $schema.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $fields = [];

    /**
     * Tally-like engine schema (schema v2), or null for legacy flat forms.
     * A nested document — pages → blocks — plus branching logic and computed
     * fields. The canonical shape is documented on
     * {@see \App\Service\Form\FormSchemaNormalizer}; everything downstream
     * consumes the *normalised* document that service produces, never this raw
     * column, so a null here transparently falls back to {@see $fields}.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'form_schema', type: 'json', nullable: true)]
    private ?array $schema = null;

    /** Schema generation: 1 = legacy flat {@see $fields}, 2 = {@see $schema} document. */
    #[ORM\Column(options: ['default' => 1])]
    private int $schemaVersion = 1;

    /** Optional cap on accepted submissions; null = unlimited. */
    #[ORM\Column(nullable: true)]
    private ?int $submissionLimit = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $submissionCount = 0;

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }

    public function getSuccessMessage(): ?string { return $this->successMessage; }
    public function setSuccessMessage(?string $m): self { $this->successMessage = $m; return $this; }

    public function isEnabled(): bool { return $this->isEnabled; }
    public function setIsEnabled(bool $e): self { $this->isEnabled = $e; return $this; }

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $project): self { $this->project = $project; return $this; }

    public function getDefaultStatus(): ?TaskStatus { return $this->defaultStatus; }
    public function setDefaultStatus(?TaskStatus $s): self { $this->defaultStatus = $s; return $this; }

    public function getDefaultTracker(): ?Tracker { return $this->defaultTracker; }
    public function setDefaultTracker(?Tracker $t): self { $this->defaultTracker = $t; return $this; }

    public function getDefaultPriority(): ?TaskPriority { return $this->defaultPriority; }
    public function setDefaultPriority(?TaskPriority $p): self { $this->defaultPriority = $p; return $this; }

    /** @return list<array<string, mixed>> */
    public function getFields(): array { return $this->fields; }

    /** @param list<array<string, mixed>> $fields */
    public function setFields(array $fields): self { $this->fields = $fields; return $this; }

    /** @return array<string, mixed>|null */
    public function getSchema(): ?array { return $this->schema; }

    /** @param array<string, mixed>|null $schema */
    public function setSchema(?array $schema): self { $this->schema = $schema; return $this; }

    public function getSchemaVersion(): int { return $this->schemaVersion; }
    public function setSchemaVersion(int $version): self { $this->schemaVersion = $version; return $this; }

    public function getSubmissionLimit(): ?int { return $this->submissionLimit; }
    public function setSubmissionLimit(?int $limit): self { $this->submissionLimit = $limit; return $this; }

    public function getSubmissionCount(): int { return $this->submissionCount; }
    public function setSubmissionCount(int $count): self { $this->submissionCount = $count; return $this; }
    public function incrementSubmissionCount(): self { ++$this->submissionCount; return $this; }
}
