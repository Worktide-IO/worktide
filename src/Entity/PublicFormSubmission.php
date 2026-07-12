<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\PublicFormSubmissionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit record of a single {@see PublicForm} submission.
 *
 * Written by {@see \App\Service\PublicFormSubmissionService} on every accepted
 * submission, capturing the raw posted {@see $payload}, the {@see $createdTask}
 * it produced (null when the form has no target project — the submission is
 * still recorded, read via the staff submissions inbox), and the originating
 * {@see $remoteIp} / {@see $userAgent} for abuse forensics. Honeypot-tripped and
 * rejected submissions are NOT recorded.
 *
 * Read-only over the API (GetCollection + Get); workspace-scoped, never public.
 * `createdTask` is `SET NULL` on delete so purging a task keeps the audit trail.
 */
#[ORM\Entity(repositoryClass: PublicFormSubmissionRepository::class)]
#[ORM\Table(name: 'public_form_submissions')]
#[ORM\Index(name: 'public_form_submission_form_idx', columns: ['form_id'])]
#[ORM\Index(name: 'public_form_submission_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'PublicFormSubmission',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object)"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'form' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
class PublicFormSubmission
{
    use \App\Entity\Trait\EntityIdTrait;
    use \App\Entity\Trait\TimestampableTrait;
    use \App\Entity\Trait\WorkspaceScopedTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PublicForm $form;

    /** @var array<string, mixed> Raw submitted values, keyed by field key. */
    #[ORM\Column(type: 'json')]
    private array $payload = [];

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Task $createdTask = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $remoteIp = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    public function getForm(): PublicForm { return $this->form; }
    public function setForm(PublicForm $form): self { $this->form = $form; return $this; }

    /** @return array<string, mixed> */
    public function getPayload(): array { return $this->payload; }

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): self { $this->payload = $payload; return $this; }

    public function getCreatedTask(): ?Task { return $this->createdTask; }
    public function setCreatedTask(?Task $task): self { $this->createdTask = $task; return $this; }

    public function getRemoteIp(): ?string { return $this->remoteIp; }
    public function setRemoteIp(?string $ip): self { $this->remoteIp = $ip; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $ua): self { $this->userAgent = $ua; return $this; }
}
