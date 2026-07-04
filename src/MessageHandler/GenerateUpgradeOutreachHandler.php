<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\AIRecommendation;
use App\Entity\Customer;
use App\Entity\Enum\RecommendationKind;
use App\Entity\Enum\RecommendationStatus;
use App\Entity\Enum\RecommendationTarget;
use App\Message\GenerateUpgradeOutreachMessage;
use App\Repository\AIRecommendationRepository;
use App\Service\Ai\UpgradeOutreachAssistant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Drafts an upgrade-outreach email for one customer off the request thread (on
 * the `ai_agents` transport) and persists a Pending {@see AIRecommendation}.
 * Mirrors {@see GenerateMarketingCopyHandler}: re-load by id, drop unrecoverably
 * when the row is gone, let recoverable LLM/transport failures bubble to retry.
 *
 * A fresh run supersedes any still-pending outreach draft for the same customer.
 */
#[AsMessageHandler]
final class GenerateUpgradeOutreachHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UpgradeOutreachAssistant $assistant,
        private readonly AIRecommendationRepository $recommendations,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(GenerateUpgradeOutreachMessage $message): void
    {
        $customerId = $message->getCustomerId();

        $customer = $this->em->find(Customer::class, $customerId);
        if ($customer === null) {
            throw new UnrecoverableMessageHandlingException(sprintf('Customer %s no longer exists; dropping outreach draft.', $customerId->toRfc4122()));
        }

        $result = $this->assistant->draftOutreach($customer);

        foreach ($this->recommendations->findPendingFor(RecommendationTarget::Customer, $customerId, RecommendationKind::CustomerUpgradeOutreach) as $stale) {
            $stale->setStatus(RecommendationStatus::Superseded);
        }

        $recommendation = (new AIRecommendation())
            ->setWorkspace($customer->getWorkspace())
            ->setTarget(RecommendationTarget::Customer)
            ->setTargetId($customerId)
            ->setKind(RecommendationKind::CustomerUpgradeOutreach)
            ->setStatus(RecommendationStatus::Pending)
            ->setSuggestion($result['suggestion'])
            ->setReasoning($result['reasoning'])
            ->setModel($this->assistant->getModel());

        $this->em->persist($recommendation);
        $this->em->flush();

        $this->publish($recommendation);

        $this->logger->info('AI upgrade outreach produced a recommendation.', [
            'targetId' => $customerId->toRfc4122(),
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
            $this->hub->publish(new Update(
                topics: ['worktide:workspace:' . $wsId . ':ai-recommendations'],
                data: json_encode([
                    'target' => $recommendation->getTarget()->value,
                    'targetId' => $recommendation->getTargetId()->toRfc4122(),
                ]) ?: '{}',
                private: true,
            ));
        } catch (\Throwable $e) {
            $this->logger->debug('Mercure publish failed for AI outreach recommendation', ['error' => $e->getMessage()]);
        }
    }
}
