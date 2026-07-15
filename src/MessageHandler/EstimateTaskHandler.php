<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\AIRecommendation;
use App\Entity\Enum\RecommendationKind;
use App\Entity\Enum\RecommendationStatus;
use App\Entity\Enum\RecommendationTarget;
use App\Entity\Task;
use App\Message\EstimateTaskMessage;
use App\Repository\AIRecommendationRepository;
use App\Service\Ai\EffortEstimationAssistant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Runs AI effort estimation for one task off the request thread (on the
 * `ai_agents` transport) and persists a Pending {@see AIRecommendation} of kind
 * {@see RecommendationKind::Estimate}. Mirrors {@see TriageTicketHandler}: reload
 * by id, drop unrecoverably when the row is gone, let recoverable LLM/transport
 * failures bubble to the retry strategy.
 *
 * A fresh run supersedes any still-pending estimate for the same task, so
 * repeated runs never pile up stale suggestions. The result is pushed to the
 * workspace's Mercure topic for live display.
 */
#[AsMessageHandler]
final class EstimateTaskHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EffortEstimationAssistant $assistant,
        private readonly AIRecommendationRepository $recommendations,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(EstimateTaskMessage $message): void
    {
        $taskId = $message->getTaskId();

        $task = $this->em->find(Task::class, $taskId);
        if ($task === null) {
            throw new UnrecoverableMessageHandlingException(sprintf('Task %s no longer exists; dropping estimate.', $taskId->toRfc4122()));
        }

        $result = $this->assistant->estimate($task);

        // A newer suggestion replaces any still-pending one for this task.
        foreach ($this->recommendations->findPendingFor(RecommendationTarget::Task, $taskId, RecommendationKind::Estimate) as $stale) {
            $stale->setStatus(RecommendationStatus::Superseded);
        }

        $recommendation = (new AIRecommendation())
            ->setWorkspace($task->getWorkspace())
            ->setTarget(RecommendationTarget::Task)
            ->setTargetId($taskId)
            ->setKind(RecommendationKind::Estimate)
            ->setStatus(RecommendationStatus::Pending)
            ->setSuggestion($result['suggestion'])
            ->setReasoning($result['reasoning'])
            ->setModel($this->assistant->getModel());

        $this->em->persist($recommendation);
        $this->em->flush();

        $this->publish($recommendation);

        $this->logger->info('AI effort estimation produced a recommendation.', [
            'taskId' => $taskId->toRfc4122(),
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
            // Same opaque workspace topic + minimal ping as triage: the SPA
            // re-fetches the content over the access-controlled REST API.
            $this->hub->publish(new Update(
                topics: ['worktide:workspace:' . $wsId . ':ai-recommendations'],
                data: json_encode([
                    'target' => $recommendation->getTarget()->value,
                    'targetId' => $recommendation->getTargetId()->toRfc4122(),
                ]) ?: '{}',
                private: true,
            ));
        } catch (\Throwable $e) {
            $this->logger->debug('Mercure publish failed for AI estimate', ['error' => $e->getMessage()]);
        }
    }
}
