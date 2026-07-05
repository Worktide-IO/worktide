<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Channels\AdapterRegistry;
use App\Entity\AIRecommendation;
use App\Entity\Channel;
use App\Entity\Comment;
use App\Entity\Conversation;
use App\Entity\ConversationNote;
use App\Entity\Enum\CommentTarget;
use App\Entity\Enum\ConversationStatus;
use App\Entity\Enum\RecommendationKind;
use App\Entity\Enum\RecommendationTarget;
use App\Entity\Customer;
use App\Entity\Enum\OutboundMessageKind;
use App\Entity\Enum\OutboundMessageStatus;
use App\Entity\Enum\TagScope;
use App\Entity\Enum\TaskPriority;
use App\Entity\OutboundMessage;
use App\Entity\Enum\ResearchMissionStatus;
use App\Entity\Enum\ResearchObjective;
use App\Entity\Product;
use App\Entity\Project;
use App\Entity\ResearchMission;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
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
        private readonly ChannelRepository $channels,
        private readonly AdapterRegistry $adapters,
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

        if ($recommendation->getTarget() === RecommendationTarget::Product) {
            $this->applyMarketingSocialDraft($recommendation);

            return;
        }

        if ($recommendation->getTarget() === RecommendationTarget::Customer) {
            $this->applyCustomerUpgradeOutreach($recommendation, $reviewer);

            return;
        }

        if ($recommendation->getTarget() === RecommendationTarget::Workspace) {
            if ($recommendation->getKind() === RecommendationKind::AgentAction) {
                $this->applyAgentAction($recommendation, $reviewer);
            } else {
                $this->applyResearchSuggestion($recommendation);
            }

            return;
        }

        if ($recommendation->getKind() === RecommendationKind::TicketFromConversation) {
            $this->applyTicketFromConversation($recommendation, $projectOverride);

            return;
        }

        $this->applyToConversation($recommendation, $reviewer);
    }

    /**
     * Materialise a marketing recommendation into a Draft {@see SocialPost}: the
     * summary becomes the shared body and each accepted variant becomes a
     * {@see SocialPostTarget} on its connected channel (matched by adapterCode).
     * Nothing is published — the draft still goes through the normal approval
     * gate. Variants whose network has no connected channel are skipped; if none
     * match, an empty-target draft is created as a starting point.
     */
    private function applyMarketingSocialDraft(AIRecommendation $recommendation): void
    {
        $product = $this->em->find(Product::class, $recommendation->getTargetId());
        if (!$product instanceof Product) {
            return;
        }
        $workspace = $product->getWorkspace();
        $suggestion = $recommendation->getSuggestion();

        $summary = \is_string($suggestion['summary'] ?? null) ? trim((string) $suggestion['summary']) : '';
        /** @var list<array<string, mixed>> $variants */
        $variants = \is_array($suggestion['variants'] ?? null) ? $suggestion['variants'] : [];

        // Index the workspace's connected social channels by adapterCode (first wins).
        $channelByCode = [];
        foreach ($this->channels->findEnabledSocial($workspace) as $channel) {
            $channelByCode[$channel->getAdapterCode()] ??= $channel;
        }

        $firstBody = '';
        $post = new SocialPost();
        $post->setWorkspace($workspace);

        foreach ($variants as $variant) {
            $code = \is_string($variant['adapterCode'] ?? null) ? $variant['adapterCode'] : null;
            $body = \is_string($variant['body'] ?? null) ? trim((string) $variant['body']) : '';
            if ($body === '') {
                continue;
            }
            if ($firstBody === '') {
                $firstBody = $body;
            }
            if ($code === null || !isset($channelByCode[$code])) {
                continue;
            }
            $target = new SocialPostTarget();
            $target->setWorkspace($workspace);
            $target->setChannel($channelByCode[$code]);
            $target->setBodyOverride($body);
            $post->addTarget($target);
        }

        // Shared base body: the summary, or the first variant as a fallback.
        $post->setBody($summary !== '' ? $summary : $firstBody);

        // Targets cascade-persist with the post.
        $this->em->persist($post);
    }

    /**
     * Materialise an upgrade-outreach recommendation into an OutboundMessage
     * (email) addressed to the customer. There is no "draft" status on
     * OutboundMessage — it is created Queued, and the default-deny
     * `email_outbound` egress gate is what actually holds it back until the
     * operator approves sending. The reviewer becomes its author (createdByUser),
     * and it links back to the recommendation for audit. Skipped silently when no
     * recipient email or outbound email channel can be resolved.
     */
    private function applyCustomerUpgradeOutreach(AIRecommendation $recommendation, User $reviewer): void
    {
        $customer = $this->em->find(Customer::class, $recommendation->getTargetId());
        if (!$customer instanceof Customer) {
            return;
        }
        $recipient = $this->resolveRecipientEmail($customer);
        if ($recipient === null) {
            return;
        }
        $channels = $this->channels->findEnabledEmailOutbound($customer->getWorkspace());
        $channel = $channels[0] ?? null;
        if ($channel === null) {
            return;
        }

        $suggestion = $recommendation->getSuggestion();
        $subject = \is_string($suggestion['subject'] ?? null) ? trim((string) $suggestion['subject']) : '';
        $body = \is_string($suggestion['body'] ?? null) ? trim((string) $suggestion['body']) : '';

        $message = (new OutboundMessage())
            ->setWorkspace($customer->getWorkspace())
            ->setChannel($channel)
            ->setRecipientRaw($recipient)
            ->setSubject($subject !== '' ? $subject : null)
            ->setBody($body)
            ->setKind(OutboundMessageKind::Reply)
            ->setStatus(OutboundMessageStatus::Queued)
            ->setCreatedByUser($reviewer)
            ->setCreatedByRecommendationId($recommendation->getId());

        $this->em->persist($message);
    }

    /**
     * Materialise an accepted proactive research suggestion into a
     * {@see ResearchMission}. The suggestion carries a ready-to-run prompt +
     * normalized brief; a mission with a `query` in its brief starts Ready
     * (dispatch the run endpoint), otherwise Draft (needs clarification first).
     * The reviewer becomes the mission's author via the auditable subscriber.
     */
    /**
     * Generic agent action: the LLM planner proposed an outbound action against
     * a connected channel; accepting it materialises the SAME kind of egress-gated
     * draft the bespoke branches create — but driven by connectorCode, not
     * hard-wired. Dispatch is by archetype (a small fixed set), while the concrete
     * connector within each archetype comes from {@see AdapterRegistry} (unbounded).
     * So adding a new channel/forum needs no new branch here.
     *
     * @throws \DomainException on an unresolvable/blocked action (→ 409 on accept)
     */
    private function applyAgentAction(AIRecommendation $recommendation, User $reviewer): void
    {
        $workspace = $this->em->find(Workspace::class, $recommendation->getTargetId());
        if (!$workspace instanceof Workspace) {
            return;
        }
        $s = $recommendation->getSuggestion();
        $archetype = \is_string($s['archetype'] ?? null) ? $s['archetype'] : '';
        $connectorCode = \is_string($s['connectorCode'] ?? null) ? $s['connectorCode'] : '';
        $channelId = \is_string($s['channelId'] ?? null) ? $s['channelId'] : '';
        $payload = \is_array($s['payload'] ?? null) ? $s['payload'] : [];
        if ($connectorCode === '' || $channelId === '') {
            throw new \DomainException('Agent-Aktion ohne Connector oder Kanal.');
        }

        try {
            $channel = $this->em->find(Channel::class, Uuid::fromString($channelId));
        } catch (\InvalidArgumentException) {
            throw new \DomainException('Ungültige Kanal-Referenz.');
        }
        if (!$channel instanceof Channel
            || !$channel->getWorkspace()->getId()?->equals($workspace->getId())
            || !$channel->isEnabled()
            || $channel->getAdapterCode() !== $connectorCode) {
            throw new \DomainException('Der gewählte Kanal ist für diese Aktion nicht verfügbar.');
        }

        $body = \is_string($payload['body'] ?? null) ? trim((string) $payload['body']) : '';

        if ($archetype === 'social_post') {
            if ($this->adapters->trySocial($connectorCode) === null) {
                throw new \DomainException(sprintf('Kein Publisher für "%s".', $connectorCode));
            }
            if ($body === '') {
                throw new \DomainException('Social-Aktion ohne Text.');
            }
            $post = (new SocialPost())->setWorkspace($workspace)->setBody($body);
            $post->addTarget(
                (new SocialPostTarget())->setWorkspace($workspace)->setChannel($channel)->setBodyOverride($body),
            );
            $this->em->persist($post);

            return;
        }

        if ($archetype === 'outbound_message') {
            if ($this->adapters->tryOutbound($connectorCode) === null) {
                throw new \DomainException(sprintf('Kein Outbound-Adapter für "%s".', $connectorCode));
            }
            $recipient = \is_string($payload['recipient'] ?? null) ? trim((string) $payload['recipient']) : '';
            if ($recipient === '' || $body === '') {
                throw new \DomainException('Outbound-Aktion braucht Empfänger und Text.');
            }
            $subject = \is_string($payload['subject'] ?? null) ? trim((string) $payload['subject']) : '';
            $message = (new OutboundMessage())
                ->setWorkspace($workspace)
                ->setChannel($channel)
                ->setRecipientRaw($recipient)
                ->setSubject($subject !== '' ? $subject : null)
                ->setBody($body)
                ->setKind(OutboundMessageKind::Reply)
                ->setStatus(OutboundMessageStatus::Queued)
                ->setCreatedByUser($reviewer)
                ->setCreatedByRecommendationId($recommendation->getId());
            $this->em->persist($message);

            return;
        }

        throw new \DomainException(sprintf('Unbekannter Agent-Action-Archetyp "%s".', $archetype));
    }

    private function applyResearchSuggestion(AIRecommendation $recommendation): void
    {
        $workspace = $this->em->find(Workspace::class, $recommendation->getTargetId());
        if (!$workspace instanceof Workspace) {
            return;
        }
        $suggestion = $recommendation->getSuggestion();
        $prompt = \is_string($suggestion['prompt'] ?? null) ? trim((string) $suggestion['prompt']) : '';
        if ($prompt === '') {
            return;
        }
        $brief = \is_array($suggestion['brief'] ?? null) ? $suggestion['brief'] : [];
        $objective = \is_string($suggestion['objective'] ?? null)
            ? (ResearchObjective::tryFrom($suggestion['objective']) ?? ResearchObjective::General)
            : ResearchObjective::General;
        $targetCount = is_numeric($suggestion['targetCount'] ?? null) ? max(0, (int) $suggestion['targetCount']) : null;

        $mission = (new ResearchMission())
            ->setWorkspace($workspace)
            ->setPrompt($prompt)
            ->setObjective($objective)
            ->setCreatedVia('suggestion')
            ->setBrief($brief === [] ? null : $brief)
            ->setTargetCount($targetCount)
            ->setStatus(
                isset($brief['query']) && $brief['query'] !== ''
                    ? ResearchMissionStatus::Ready
                    : ResearchMissionStatus::Draft,
            );
        $this->em->persist($mission);
    }

    /** Customer's own email, else the first contact with an email; null if none. */
    private function resolveRecipientEmail(Customer $customer): ?string
    {
        $email = trim((string) $customer->getEmail());
        if ($email !== '') {
            return $email;
        }
        foreach ($customer->getContacts() as $contact) {
            $contactEmail = trim((string) $contact->getEmail());
            if ($contactEmail !== '') {
                return $contactEmail;
            }
        }

        return null;
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
