<?php

declare(strict_types=1);

namespace App\Service\Automation;

use App\Entity\Automation;
use App\Entity\AutomationAction;
use App\Entity\Comment;
use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Enum\AutomationActionType;
use App\Entity\Enum\CommentTarget;
use App\Entity\Enum\TaskPriority;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\TaskAssignee;
use App\Entity\TaskStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Executes one AutomationAction against a Task. Pure side-effect: mutates
 * the task / inserts a comment / etc. Errors are logged but never raised
 * so a faulty action doesn't block the rest of the automation chain.
 *
 * Kept Task-only for the B6 MVP. Project-level actions (close project,
 * advance project status) come when Project Automations land.
 */
final class ActionRunner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(AutomationAction $action, Task $task): void
    {
        $config = $action->getConfig();

        try {
            match ($action->getType()) {
                AutomationActionType::SetTaskStatus => $this->setStatus($task, $config),
                AutomationActionType::SetTaskPriority => $this->setPriority($task, $config),
                AutomationActionType::AddTaskTag => $this->addTag($task, $config),
                AutomationActionType::AssignTaskUser => $this->assignUser($task, $config),
                AutomationActionType::PostTaskComment => $this->postComment($task, $action->getAutomation(), $config),
                AutomationActionType::CloseTask => $this->closeTask($task, $action->getAutomation()),
            };
        } catch (\Throwable $e) {
            $this->logger->warning('Automation action failed; skipping', [
                'action_id' => $action->getId()?->toRfc4122(),
                'action_type' => $action->getType()->value,
                'task_id' => $task->getId()?->toRfc4122(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $config */
    private function setStatus(Task $task, array $config): void
    {
        $id = $this->expectUuid($config, 'statusId');
        $status = $this->em->find(TaskStatus::class, $id);
        if (!$status instanceof TaskStatus || $status->getWorkspace() !== $task->getWorkspace()) {
            return;
        }
        $task->setStatus($status);
    }

    /** @param array<string, mixed> $config */
    private function setPriority(Task $task, array $config): void
    {
        $raw = $config['priority'] ?? null;
        if (!\is_string($raw)) {
            return;
        }
        $priority = TaskPriority::tryFrom($raw);
        if ($priority !== null) {
            $task->setPriority($priority);
        }
    }

    /** @param array<string, mixed> $config */
    private function addTag(Task $task, array $config): void
    {
        $id = $this->expectUuid($config, 'tagId');
        $tag = $this->em->find(Tag::class, $id);
        if (!$tag instanceof Tag || $tag->getWorkspace() !== $task->getWorkspace()) {
            return;
        }
        $task->addTag($tag);
    }

    /** @param array<string, mixed> $config */
    private function assignUser(Task $task, array $config): void
    {
        $id = $this->expectUuid($config, 'userId');
        $user = $this->em->find(User::class, $id);
        if (!$user instanceof User) {
            return;
        }
        // Assignees are polymorphic (User|Team) since the TaskAssignee
        // refactor — the old addAssignee(User) shortcut is gone.
        $task->addAssignedPrincipal(
            (new TaskAssignee())
                ->setPrincipalType(AssigneePrincipalType::User)
                ->setPrincipalId($user->getId())
        );
    }

    /** @param array<string, mixed> $config */
    private function postComment(Task $task, Automation $automation, array $config): void
    {
        $content = $config['content'] ?? null;
        if (!\is_string($content) || $content === '') {
            return;
        }
        $author = $automation->getCreatedByUser() ?? $task->getCreatedBy();
        if (!$author instanceof User) {
            return; // no system user; skip
        }
        $comment = (new Comment())
            ->setWorkspace($task->getWorkspace())
            ->setTarget(CommentTarget::Task)
            ->setTargetId($task->getId())
            ->setAuthor($author)
            ->setContent($content);
        $this->em->persist($comment);
    }

    private function closeTask(Task $task, Automation $automation): void
    {
        $actor = $automation->getCreatedByUser() ?? $task->getCreatedBy();
        if ($actor instanceof User) {
            $task->close($actor);
        }
    }

    /** @param array<string, mixed> $config */
    private function expectUuid(array $config, string $key): Uuid
    {
        $raw = $config[$key] ?? null;
        if (!\is_string($raw)) {
            throw new \InvalidArgumentException("Missing or non-string {$key}");
        }
        return Uuid::fromString($raw);
    }
}
