<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\AIRecommendation;
use App\Entity\Conversation;
use App\Entity\Enum\RecommendationKind;
use App\Entity\Enum\RecommendationStatus;
use App\Entity\Enum\RecommendationTarget;
use App\Message\SuggestConversationTicketMessage;
use App\Repository\AIRecommendationRepository;
use App\Repository\ProjectRepository;
use App\Service\Ai\TicketTriageAssistant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Off-thread (ai_agents): asks the AI whether a Conversation warrants a ticket
 * and, if so, persists a Pending "create ticket?" {@see AIRecommendation}
 * (kind = TicketFromConversation) with a suggested project.
 *
 * Any prior still-pending suggestion for the conversation is superseded first,
 * so a re-run (new reply, or on-demand) never piles up stale suggestions and an
 * outdated one clears when the conversation is no longer actionable.
 */
#[AsMessageHandler]
final class SuggestConversationTicketHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketTriageAssistant $assistant,
        private readonly AIRecommendationRepository $recommendations,
        private readonly ProjectRepository $projects,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SuggestConversationTicketMessage $message): void
    {
        $conversationId = $message->getConversationId();
        $conversation = $this->em->find(Conversation::class, $conversationId);
        if ($conversation === null) {
            throw new UnrecoverableMessageHandlingException(sprintf('Conversation %s no longer exists; dropping ticket suggestion.', $conversationId->toRfc4122()));
        }

        $result = $this->assistant->suggestTicketForConversation($conversation);

        // Clear any stale pending suggestion before deciding.
        foreach ($this->recommendations->findPendingFor(RecommendationTarget::Conversation, $conversationId, RecommendationKind::TicketFromConversation) as $stale) {
            $stale->setStatus(RecommendationStatus::Superseded);
        }

        if (($result['suggestion']['shouldCreateTicket'] ?? false) !== true) {
            $this->em->flush();
            $this->logger->info('No ticket suggested for conversation.', ['conversationId' => $conversationId->toRfc4122()]);

            return;
        }

        $suggestion = $result['suggestion'];
        $suggestion['suggestedProject'] = $this->resolveProject($conversation);

        $recommendation = (new AIRecommendation())
            ->setWorkspace($conversation->getWorkspace())
            ->setTarget(RecommendationTarget::Conversation)
            ->setTargetId($conversationId)
            ->setKind(RecommendationKind::TicketFromConversation)
            ->setStatus(RecommendationStatus::Pending)
            ->setSuggestion($suggestion)
            ->setReasoning($result['reasoning'])
            ->setModel($this->assistant->getModel());

        $this->em->persist($recommendation);
        $this->em->flush();

        $this->publish($conversation->getWorkspace()->getId()?->toRfc4122(), $conversationId->toRfc4122());
    }

    /**
     * 1 non-archived project of the conversation's customer → that (true 1-click);
     * else the channel's configured default project; else null (user picks at accept).
     */
    private function resolveProject(Conversation $conversation): ?string
    {
        $customer = $conversation->getCustomer();
        if ($customer !== null) {
            $projects = $this->projects->findBy(['customer' => $customer, 'isArchived' => false]);
            if (\count($projects) === 1) {
                return $projects[0]->getId()?->toRfc4122();
            }
        }

        $default = $conversation->getChannel()->getInboundConfig()['defaultProjectId'] ?? null;

        return \is_string($default) && $default !== '' ? $default : null;
    }

    private function publish(?string $workspaceId, string $conversationId): void
    {
        if ($workspaceId === null) {
            return;
        }
        try {
            $this->hub->publish(new Update(
                topics: ['worktide:workspace:' . $workspaceId . ':ai-recommendations'],
                data: json_encode([
                    'target' => RecommendationTarget::Conversation->value,
                    'targetId' => $conversationId,
                ]) ?: '{}',
                private: true,
            ));
        } catch (\Throwable $e) {
            $this->logger->debug('Mercure publish failed for ticket suggestion', ['error' => $e->getMessage()]);
        }
    }
}
