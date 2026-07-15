<?php

declare(strict_types=1);

namespace App\Service\Llm;

use App\Entity\LlmUsageLog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Persists one {@see LlmUsageLog} per provider call, pulling the feature +
 * workspace attribution from {@see AiUsageContext} and the cost from
 * {@see LlmPricing}. Called from inside the providers so every path through
 * {@see LlmProviderInterface} is accounted for.
 *
 * Fail-open: accounting must never break an AI call, so any persistence error is
 * logged and swallowed. Safe to flush here — the providers are invoked after the
 * assistants have only *read* their context, so there are no unrelated pending
 * changes to flush prematurely.
 */
final class LlmUsageRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LlmPricing $pricing,
        private readonly AiUsageContext $context,
        private readonly LoggerInterface $logger,
    ) {}

    public function record(string $provider, string $model, int $inputTokens, int $outputTokens, bool $ok = true): void
    {
        try {
            $log = (new LlmUsageLog())
                ->setProvider($provider)
                ->setModel($model)
                ->setInputTokens(max(0, $inputTokens))
                ->setOutputTokens(max(0, $outputTokens))
                ->setCostMicros($this->pricing->costMicros($model, $inputTokens, $outputTokens))
                ->setOk($ok)
                ->setFeature($this->context->getFeature())
                ->setWorkspace($this->context->getWorkspace());

            $this->em->persist($log);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('LLM usage recording failed', ['error' => $e->getMessage()]);
        }
    }
}
