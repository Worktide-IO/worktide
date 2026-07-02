<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\Conversation;
use App\Entity\Enum\TaskCreatedVia;
use App\Entity\Enum\TaskPriority;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Repository\InboundEventRepository;
use App\Repository\TaskStatusRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Converts a {@see Conversation} into a {@see Task} — the "1-click create task
 * from conversation" action (Phase C Schicht 4).
 *
 * Title defaults to the conversation subject, description to the first inbound
 * message body, status to the workspace default; the task remembers its origin
 * via {@see Task::$sourceConversation}. Mirrors the task-building convention of
 * {@see \App\Service\PublicFormSubmissionService} (status fallback + minted
 * identifier).
 */
class ConversationTaskConverter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaskStatusRepository $taskStatuses,
        private readonly InboundEventRepository $inbound,
    ) {}

    public function convert(Conversation $conversation, Project $project, ?string $title = null): Task
    {
        if ($project->getWorkspace() !== $conversation->getWorkspace()) {
            throw new \DomainException('Project belongs to a different workspace than the conversation.');
        }

        $subject = $conversation->getSubject();
        $task = (new Task())
            ->setWorkspace($project->getWorkspace())
            ->setProject($project)
            ->setTitle($title !== null && $title !== '' ? $title : ($subject !== '' ? $subject : 'Conversation'))
            ->setDescription($this->firstInboundBody($conversation))
            ->setStatus($this->resolveStatus($project))
            ->setPriority(TaskPriority::Normal)
            ->setCreatedVia(TaskCreatedVia::Email)
            ->setSourceConversation($conversation)
            ->setIdentifier($project->getKey() . '-' . dechex(random_int(0x1000, 0xFFFF)));

        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function firstInboundBody(Conversation $conversation): ?string
    {
        $events = $this->inbound->findBy(['conversation' => $conversation], ['receivedAt' => 'ASC'], 1);
        $first = $events[0] ?? null;

        return $first?->getBody();
    }

    private function resolveStatus(Project $project): TaskStatus
    {
        $workspace = $project->getWorkspace();

        $default = $this->taskStatuses->findOneBy(['workspace' => $workspace, 'isDefault' => true]);
        if ($default !== null) {
            return $default;
        }

        $statuses = $this->taskStatuses->findBy(['workspace' => $workspace], ['position' => 'ASC'], 1);
        $first = $statuses[0] ?? null;
        if ($first === null) {
            throw new \RuntimeException('Workspace has no task statuses; cannot create a task from a conversation.');
        }

        return $first;
    }
}
