<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\TaskAssigneeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Polymorphic Task → (User|Team) assignment.
 *
 * Replaces the old `task_assignees(task_id, user_id)` ManyToMany join.
 * Each row is one assignment of a Principal (a User OR a Team) to a
 * Task. The Task's "effective assignees" — what shows up in the avatar
 * stack and notifications — is the union of:
 *   - direct user assignments (principalType=user)
 *   - the members of every assigned team (principalType=team, expanded
 *     via team_members)
 *
 * Workspace is denormalised from the parent task at persist-time so
 * filters and voters don't need a join.
 *
 * The principal_type + principal_id pattern intentionally avoids
 * Doctrine STI — see AssigneePrincipalType.
 */
#[ORM\Entity(repositoryClass: TaskAssigneeRepository::class)]
#[ORM\Table(name: 'task_assignee_principals')]
#[ORM\UniqueConstraint(
    name: 'task_assignee_unique',
    columns: ['task_id', 'principal_type', 'principal_id'],
)]
#[ORM\Index(name: 'task_assignee_principal_idx', columns: ['principal_type', 'principal_id'])]
#[ORM\Index(name: 'task_assignee_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'TaskAssignee',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getTask())"),
        // POST is gated by ROLE_USER only — a state-processor / lifecycle
        // listener checks EDIT on the resolved task at persist time, so
        // we don't need ExpressionLanguage to walk the unresolved body.
        new Post(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('EDIT', object.getTask())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'task' => 'exact',
    'principalType' => 'exact',
    'principalId' => 'exact',
])]
class TaskAssignee
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne(inversedBy: 'assignedPrincipals')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\Column(length: 16, enumType: AssigneePrincipalType::class)]
    private AssigneePrincipalType $principalType;

    #[ORM\Column(type: 'uuid')]
    private Uuid $principalId;

    public function getTask(): Task { return $this->task; }
    public function setTask(Task $t): self
    {
        $this->task = $t;
        $this->workspace = $t->getWorkspace();
        return $this;
    }

    public function getPrincipalType(): AssigneePrincipalType { return $this->principalType; }
    public function setPrincipalType(AssigneePrincipalType $t): self { $this->principalType = $t; return $this; }

    public function getPrincipalId(): Uuid { return $this->principalId; }
    public function setPrincipalId(Uuid $id): self { $this->principalId = $id; return $this; }

    /** Convenience getter for the API output — returns the IRI of the principal. */
    public function getPrincipalIri(): string
    {
        $base = $this->principalType === AssigneePrincipalType::User
            ? '/v1/users/'
            : '/v1/teams/';
        return $base . $this->principalId->toRfc4122();
    }
}
