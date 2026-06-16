<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\WorkflowTransitionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One legal status transition in the workflow engine.
 *
 * The shape is (Tracker × fromStatus → toStatus) with an optional set
 * of WorkspaceMemberRole strings that may perform it. The full state
 * machine for a Tracker is the union of all its WorkflowTransition
 * rows.
 *
 * ## Default-open semantics
 *
 * The intent of this entity is to *constrain* a workflow, not to define
 * one from scratch. So:
 *
 *  - If a workspace has **zero** WorkflowTransition rows for a given
 *    (tracker, fromStatus), all moves out of fromStatus are allowed
 *    for every role. This keeps existing workspaces working without
 *    forced seeding.
 *  - As soon as **one** row exists for (tracker, fromStatus), the
 *    workspace has opted into a closed state machine: only the
 *    declared toStatus values are accepted, and only for the listed
 *    roles. Anything else is rejected by the policy service.
 *
 * Workspace owners and admins always bypass the check — they need to
 * fix broken workflows without having to grant themselves a role on
 * every transition.
 *
 * The `tracker` and `allowedRoles` columns are nullable so a workspace
 * can author a coarse "any tracker, any role" baseline before
 * narrowing later — null tracker means "applies to every tracker that
 * doesn't have its own explicit rules"; an empty allowedRoles array
 * means "no role can perform this" (a useful kill-switch).
 */
#[ORM\Entity(repositoryClass: WorkflowTransitionRepository::class)]
#[ORM\Table(name: 'workflow_transitions')]
#[ORM\UniqueConstraint(
    name: 'workflow_transition_unique',
    columns: ['workspace_id', 'tracker_id', 'from_status_id', 'to_status_id'],
)]
#[ORM\Index(name: 'workflow_transition_tracker_from_idx', columns: ['workspace_id', 'tracker_id', 'from_status_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'WorkflowTransition',
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'tracker' => 'exact',
    'fromStatus' => 'exact',
    'toStatus' => 'exact',
])]
class WorkflowTransition
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    /**
     * Null = applies to any tracker that doesn't have its own rules
     * (the baseline workflow). When a per-tracker override exists,
     * the baseline is ignored for that tracker.
     */
    #[ORM\ManyToOne(targetEntity: Tracker::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tracker $tracker = null;

    #[ORM\ManyToOne(targetEntity: TaskStatus::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TaskStatus $fromStatus;

    #[ORM\ManyToOne(targetEntity: TaskStatus::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TaskStatus $toStatus;

    /**
     * Stored as JSON list of WorkspaceMemberRole `value` strings.
     * Null  = any workspace member may perform the transition.
     * []    = no role may perform it (kill-switch).
     * [...] = exactly the listed roles may perform it.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allowedRoles = null;

    /**
     * Optional human-facing label shown next to the transition button —
     * "Submit for review", "Re-open", "Send to QA". Omitted = the SPA
     * just labels the button with the target status name.
     */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $label = null;

    public function getTracker(): ?Tracker { return $this->tracker; }
    public function setTracker(?Tracker $t): self { $this->tracker = $t; return $this; }

    public function getFromStatus(): TaskStatus { return $this->fromStatus; }
    public function setFromStatus(TaskStatus $s): self { $this->fromStatus = $s; return $this; }

    public function getToStatus(): TaskStatus { return $this->toStatus; }
    public function setToStatus(TaskStatus $s): self { $this->toStatus = $s; return $this; }

    /** @return list<string>|null */
    public function getAllowedRoles(): ?array { return $this->allowedRoles; }

    /** @param list<string>|list<WorkspaceMemberRole>|null $roles */
    public function setAllowedRoles(?array $roles): self
    {
        if ($roles === null) {
            $this->allowedRoles = null;
            return $this;
        }
        $normalised = [];
        foreach ($roles as $r) {
            if ($r instanceof WorkspaceMemberRole) {
                $normalised[] = $r->value;
            } elseif (is_string($r) && WorkspaceMemberRole::tryFrom($r) !== null) {
                $normalised[] = $r;
            }
        }
        $this->allowedRoles = array_values(array_unique($normalised));
        return $this;
    }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = $label; return $this; }
}
