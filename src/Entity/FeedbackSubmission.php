<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\FeedbackSubmissionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Admin-only sidecar for a feedback ticket (see the Feedback & Requests board,
 * docs/feedback-board-plan.md).
 *
 * The ticket itself is a {@see Task} in the shared cross-tenant feedback
 * project; this row holds the SENSITIVE attribution + context that must never
 * reach the anonymized public board: who submitted it, which workspace they
 * came from, the browser context, and the captured client diagnostics.
 *
 * Deliberately NOT an #[ApiResource] and deliberately NOT WorkspaceScoped —
 * it is only ever read by super-admins (via the normal Task triage UI / a
 * future admin controller). The anonymized feedback controllers never join to
 * it on a read path, so identity cannot leak.
 */
#[ORM\Entity(repositoryClass: FeedbackSubmissionRepository::class)]
#[ORM\Table(name: 'feedback_submissions')]
#[ORM\HasLifecycleCallbacks]
class FeedbackSubmission
{
    use EntityIdTrait;
    use TimestampableTrait;

    /** The feedback ticket this submission backs. */
    #[ORM\OneToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'task_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private Task $task;

    /** Staff submitter (worktide-web), if any. Admin-only. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $submitterUser = null;

    /** Portal-client submitter (worktide-portal), if any. Admin-only. */
    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $submitterContact = null;

    /** The workspace the report came from (NOT the platform feedback workspace). */
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Workspace $originWorkspace = null;

    /** Which app the report was filed from: 'staff' | 'portal'. */
    #[ORM\Column(length: 16)]
    private string $sourceApp = 'staff';

    /** SPA route/path the reporter was on when filing. */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $route = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $appVersion = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    /**
     * Captured client diagnostics — recent JS errors, unhandled rejections,
     * failed-request metadata (method/url/status/timestamp, never bodies) and
     * breadcrumbs. Small, structured, admin-only.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $diagnostics = null;

    /** Optional attached screenshot (a hidden File on the feedback task). */
    #[ORM\ManyToOne(targetEntity: File::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?File $screenshotFile = null;

    public function getTask(): Task { return $this->task; }
    public function setTask(Task $task): self { $this->task = $task; return $this; }

    public function getSubmitterUser(): ?User { return $this->submitterUser; }
    public function setSubmitterUser(?User $user): self { $this->submitterUser = $user; return $this; }

    public function getSubmitterContact(): ?Contact { return $this->submitterContact; }
    public function setSubmitterContact(?Contact $contact): self { $this->submitterContact = $contact; return $this; }

    public function getOriginWorkspace(): ?Workspace { return $this->originWorkspace; }
    public function setOriginWorkspace(?Workspace $workspace): self { $this->originWorkspace = $workspace; return $this; }

    public function getSourceApp(): string { return $this->sourceApp; }
    public function setSourceApp(string $sourceApp): self { $this->sourceApp = $sourceApp; return $this; }

    public function getRoute(): ?string { return $this->route; }
    public function setRoute(?string $route): self { $this->route = $route; return $this; }

    public function getAppVersion(): ?string { return $this->appVersion; }
    public function setAppVersion(?string $appVersion): self { $this->appVersion = $appVersion; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $userAgent): self { $this->userAgent = $userAgent; return $this; }

    /** @return array<string, mixed>|null */
    public function getDiagnostics(): ?array { return $this->diagnostics; }
    /** @param array<string, mixed>|null $diagnostics */
    public function setDiagnostics(?array $diagnostics): self { $this->diagnostics = $diagnostics; return $this; }

    public function getScreenshotFile(): ?File { return $this->screenshotFile; }
    public function setScreenshotFile(?File $file): self { $this->screenshotFile = $file; return $this; }
}
