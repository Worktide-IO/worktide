<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AIRecommendation;
use App\Entity\Comment;
use App\Entity\Conversation;
use App\Entity\ConversationNote;
use App\Entity\Enum\CommentTarget;
use App\Entity\Enum\ConversationStatus;
use App\Entity\Enum\RecommendationKind;
use App\Entity\Enum\RecommendationTarget;
use App\Entity\Enum\TagScope;
use App\Entity\Enum\TaskPriority;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\TagRepository;
use App\Repository\TrackerRepository;
use App\Service\Inbound\ConversationTaskConverter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Applies an accepted {@see AIRecommendation} to its ticket. This is the ONLY
 * place a triage suggestion mutates a Task/Conversation — it runs when a human
 * accepts, never autonomously.
 *
 * Everything is resolved against the workspace's real data (tracker/tag by
 * name); anything unresolvable is skipped rather than invented. The summary is
 * recorded as an internal note/comment so the reasoning is visible in the
 * activity feed. Does not flush — the caller commits after stamping review
 * metadata.
 */
final class RecommendationApplier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TrackerRepository $trackers,
        private readonly TagRepository $tags,
        private readonly ConversationTaskConverter $taskConverter,
    ) {}

    /**
     * @throws \DomainException when a TicketFromConversation recommendation has
     *                          no project (neither suggested nor overridden)
     */
    public function apply(AIRecommendation $recommendation, User $reviewer, ?Project $projectOverride = null): void
    {
        if ($recommendation->getTarget() === RecommendationTarget::Task) {
            $this->applyToTask($recommendation, $reviewer);

            return;
        }

        if ($recommendation->getKind() === RecommendationKind::TicketFromConversation) {
            $this->applyTicketFromConversation($recommendation, $projectOverride);

            return;
        }

        $this->applyToConversation($recommendation, $reviewer);
    }

    private function applyToTask(AIRecommendation $recommendation, User $reviewer): void
    {
        $task = $this->em->find(Task::class, $recommendation->getTargetId());
        if ($task === null) {
            return;
        }
        $workspace = $task->getWorkspace();
        $suggestion = $recommendation->getSuggestion();

        $trackerName = $suggestion['tracker'] ?? null;
        if (\is_string($trackerName) && $trackerName !== '') {
            $tracker = $this->trackers->findOneBy(['workspace' => $workspace, 'name' => $trackerName]);
            if ($tracker !== null) {
                $task->setTracker($tracker);
            }
        }

        $priority = $suggestion['priority'] ?? null;
        if (\is_string($priority) && ($case = TaskPriority::tryFrom($priority)) !== null) {
            $task->setPriority($case);
        }

        foreach ((array) ($suggestion['tags'] ?? []) as $tagName) {
            if (!\is_string($tagName) || $tagName === '') {
                continue;
            }
            $tag = $this->resolveTag($tagName, $task);
            if ($tag !== null) {
                $task->addTag($tag);
            }
        }

        $summary = $suggestion['summary'] ?? null;
        if (\is_string($summary) && trim($summary) !== '') {
            $comment = (new Comment())
                ->setWorkspace($workspace)
                ->setTarget(CommentTarget::Task)
                ->setTargetId($task->getId())
                ->setAuthor($reviewer)
                ->setContent("🤖 KI-Triage-Zusammenfassung:\n\n" . trim($summary))
                ->setIsHiddenForConnectUsers(true);
            $this->em->persist($comment);
        }
    }

    private function applyToConversation(AIRecommendation $recommendation, User $reviewer): void
    {
        $conversation = $this->em->find(Conversation::class, $recommendation->getTargetId());
        if ($conversation === null) {
            return;
        }
        $suggestion = $recommendation->getSuggestion();

        $status = $suggestion['status'] ?? null;
        if (\is_string($status) && ($case = ConversationStatus::tryFrom($status)) !== null) {
            $conversation->setStatus($case);
        }

        $summary = $suggestion['summary'] ?? null;
        if (\is_string($summary) && trim($summary) !== '') {
            $note = (new ConversationNote())
                ->setWorkspace($conversation->getWorkspace())
                ->setConversation($conversation)
                ->setBody("🤖 KI-Triage-Zusammenfassung:\n\n" . trim($summary));
            $note->setCreatedByUser($reviewer);
            $this->em->persist($note);
        }
    }

    private function applyTicketFromConversation(AIRecommendation $recommendation, ?Project $projectOverride): void
    {
        $conversation = $this->em->find(Conversation::class, $recommendation->getTargetId());
        if ($conversation === null) {
            return;
        }
        $suggestion = $recommendation->getSuggestion();

        $project = $projectOverride ?? $this->resolveSuggestedProject($suggestion, $conversation);
        if ($project === null) {
            throw new \DomainException('A project is required to create a ticket from this conversation.');
        }

        $title = \is_string($suggestion['title'] ?? null) && trim($suggestion['title']) !== ''
            ? $suggestion['title']
            : null;

        $this->taskConverter->convert($conversation, $project, $title);
    }

    /**
     * @param array<string, mixed> $suggestion
     */
    private function resolveSuggestedProject(array $suggestion, Conversation $conversation): ?Project
    {
        $id = $suggestion['suggestedProject'] ?? null;
        if (!\is_string($id) || $id === '') {
            return null;
        }
        try {
            $project = $this->em->find(Project::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            return null;
        }
        // Never route across workspaces.
        if ($project === null || !$project->getWorkspace()->getId()?->equals($conversation->getWorkspace()->getId())) {
            return null;
        }

        return $project;
    }

    /**
     * Resolve a tag name to a workspace Tag usable on a Task (scope Task or Any).
     */
    private function resolveTag(string $name, Task $task): ?Tag
    {
        $candidates = $this->tags->findBy(['workspace' => $task->getWorkspace(), 'name' => $name]);
        foreach ($candidates as $tag) {
            if (\in_array($tag->getScope(), [TagScope::Task, TagScope::Any], true)) {
                return $tag;
            }
        }

        return null;
    }
}
