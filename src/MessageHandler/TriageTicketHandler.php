<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\AIRecommendation;
use App\Entity\Conversation;
use App\Entity\Enum\RecommendationKind;
use App\Entity\Enum\RecommendationStatus;
use App\Entity\Enum\RecommendationTarget;
use App\Entity\Task;
use App\Message\TriageTicketMessage;
use App\Repository\AIRecommendationRepository;
use App\Service\Ai\TicketTriageAssistant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Runs AI triage for one ticket off the request thread (on the `ai_agents`
 * transport) and persists a Pending {@see AIRecommendation}. Mirrors
 * {@see ProcessInboundEventHandler}: re-load by id, drop unrecoverably when the
 * row is gone, let recoverable LLM/transport failures bubble to the retry
 * strategy.
 *
 * A fresh run supersedes any still-pending triage recommendation for the same
 * ticket, so repeated triage never piles up stale suggestions. The result is
 * pushed to the workspace's Mercure topic for live display.
 */
#[AsMessageHandler]
final class TriageTicketHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketTriageAssistant $assistant,
        private readonly AIRecommendationRepository $recommendations,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(TriageTicketMessage $message): void
    {
        $target = $message->getTarget();
        $targetId = $message->getTargetId();

        if ($target === RecommendationTarget::Task) {
            $task = $this->em->find(Task::class, $targetId);
            if ($task === null) {
                throw new UnrecoverableMessageHandlingException(sprintf('Task %s no longer exists; dropping triage.', $targetId->toRfc4122()));
            }
            $result = $this->assistant->triageTask($task);
            $workspace = $task->getWorkspace();
        } else {
            $conversation = $this->em->find(Conversation::class, $targetId);
            if ($conversation === null) {
                throw new UnrecoverableMessageHandlingException(sprintf('Conversation %s no longer exists; dropping triage.', $targetId->toRfc4122()));
            }
            $result = $this->assistant->triageConversation($conversation);
            $workspace = $conversation->getWorkspace();
        }

        // A newer suggestion replaces any still-pending one for this ticket.
        foreach ($this->recommendations->findPendingFor($target, $targetId, RecommendationKind::Triage) as $stale) {
            $stale->setStatus(RecommendationStatus::Superseded);
        }

        $recommendation = (new AIRecommendation())
            ->setWorkspace($workspace)
            ->setTarget($target)
            ->setTargetId($targetId)
            ->setKind(RecommendationKind::Triage)
            ->setStatus(RecommendationStatus::Pending)
            ->setSuggestion($result['suggestion'])
            ->setReasoning($result['reasoning'])
            ->setModel($this->assistant->getModel());

        $this->em->persist($recommendation);
        $this->em->flush();

        $this->publish($recommendation);

        $this->logger->info('AI triage produced a recommendation.', [
            'target' => $target->value,
            'targetId' => $targetId->toRfc4122(),
            'recommendationId' => $recommendation->getId()?->toRfc4122(),
        ]);
    }

    private function publish(AIRecommendation $recommendation): void
    {
        $wsId = $recommendation->getWorkspace()->getId()?->toRfc4122();
        if ($wsId === null) {
            return;
        }
        try {
            // Opaque, workspace-scoped topic string — the SPA builds the exact
            // same string (see worktide-web AiTriagePanel). Only a minimal ping
            // is sent (no summary/suggestion): the Mercure subscriber token
            // grants `subscribe: ['*']`, so anyone could listen to a workspace
            // topic — the actual content is re-fetched over the access-controlled
            // REST API, never leaked over the hub.
            $this->hub->publish(new Update(
                topics: ['worktide:workspace:' . $wsId . ':ai-recommendations'],
                data: json_encode([
                    'target' => $recommendation->getTarget()->value,
                    'targetId' => $recommendation->getTargetId()->toRfc4122(),
                ]) ?: '{}',
                private: true,
            ));
        } catch (\Throwable $e) {
            // Live push is best-effort; the row is already committed.
            $this->logger->debug('Mercure publish failed for AI recommendation', ['error' => $e->getMessage()]);
        }
    }
}
