<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Channels\AdapterRegistry;
use App\Entity\Enum\SocialPostStatus;
use App\Entity\Enum\SocialPostTargetStatus;
use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Fans a {@see SocialPost} out to its queued {@see SocialPostTarget} rows: one
 * publish attempt per network, then recompute the aggregate post status.
 *
 * Pure domain logic — it mutates the entities but does NOT flush (the handler /
 * command owns the transaction, matching {@see \App\Service\Inbound\InboundEventProcessor}).
 * Adapters never mutate entities themselves; they return a
 * {@see \App\Channels\SocialPublishResult} and this service writes the outcome.
 *
 * Transient failures (adapter signalled retry, or threw) keep the target Queued
 * with an incremented attemptCount until {@see SocialPostTarget::MAX_ATTEMPTS},
 * so the publish-due command can retry on the next tick.
 */
final class SocialPublisher
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly SocialPostValidator $validator,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {}

    public function publishPost(SocialPost $post): void
    {
        foreach ($post->getTargets() as $target) {
            if ($target->getStatus() !== SocialPostTargetStatus::Queued) {
                continue;
            }
            $this->publishTarget($post, $target);
        }

        $this->recomputeStatus($post);
        $this->publishMercure($post);
    }

    private function publishTarget(SocialPost $post, SocialPostTarget $target): void
    {
        $problems = $this->validator->validateTarget($target);
        if ($problems !== []) {
            $target->markFailed(implode(' ', $problems));
            return;
        }

        $adapter = $this->registry->trySocial($target->getChannel()->getAdapterCode());
        if ($adapter === null) {
            $target->markFailed(sprintf('No publisher for "%s".', $target->getChannel()->getAdapterCode()));
            return;
        }

        $target->setStatus(SocialPostTargetStatus::Publishing);
        $target->incrementAttempts();

        try {
            $result = $adapter->publish($target->getChannel(), $post, $target);
        } catch (\Throwable $e) {
            // Unexpected throw is treated as transient unless we've exhausted retries.
            $this->logger->warning('Social publish threw', [
                'target' => $target->getId()?->toRfc4122(),
                'adapter' => $target->getChannel()->getAdapterCode(),
                'error' => $e->getMessage(),
            ]);
            $this->applyTransientFailure($target, $e->getMessage());
            return;
        }

        if ($result->published) {
            $target->markPublished($result->externalId ?? '', $result->permalink);
            return;
        }

        if ($result->retry) {
            $this->applyTransientFailure($target, $result->reason ?? 'Transient error.');
            return;
        }

        $target->markFailed($result->reason ?? 'Publish failed.');
    }

    private function applyTransientFailure(SocialPostTarget $target, string $reason): void
    {
        if ($target->getAttemptCount() >= SocialPostTarget::MAX_ATTEMPTS) {
            $target->markFailed(sprintf('Giving up after %d attempts: %s', $target->getAttemptCount(), $reason));
            return;
        }
        // Leave it Queued so the next publish-due tick retries it.
        $target->setStatus(SocialPostTargetStatus::Queued);
        $target->setErrorReason($reason);
    }

    private function recomputeStatus(SocialPost $post): void
    {
        $published = $failed = $pending = 0;
        foreach ($post->getTargets() as $t) {
            match ($t->getStatus()) {
                SocialPostTargetStatus::Published => $published++,
                SocialPostTargetStatus::Failed => $failed++,
                SocialPostTargetStatus::Queued,
                SocialPostTargetStatus::Publishing => $pending++,
                SocialPostTargetStatus::Skipped => null,
            };
        }

        if ($pending > 0) {
            $post->setStatus(SocialPostStatus::Publishing);
            return;
        }

        if ($published > 0 && $failed > 0) {
            $post->setStatus(SocialPostStatus::PartiallyFailed);
        } elseif ($failed > 0) {
            $post->setStatus(SocialPostStatus::Failed);
        } else {
            $post->setStatus(SocialPostStatus::Published);
        }

        if ($published > 0 && $post->getPublishedAt() === null) {
            $post->setPublishedAt(new \DateTimeImmutable());
        }
    }

    private function publishMercure(SocialPost $post): void
    {
        $id = $post->getId()?->toRfc4122();
        if ($id === null) {
            return;
        }
        try {
            $this->hub->publish(new Update(
                topics: ['/v1/social_posts/' . $id],
                data: json_encode([
                    'id' => $id,
                    'status' => $post->getStatus()->value,
                    'publishedAt' => $post->getPublishedAt()?->format(\DateTimeInterface::ATOM),
                    'targets' => array_map(
                        static fn (SocialPostTarget $t) => [
                            'id' => $t->getId()?->toRfc4122(),
                            'status' => $t->getStatus()->value,
                            'permalink' => $t->getPermalink(),
                            'errorReason' => $t->getErrorReason(),
                        ],
                        $post->getTargets()->toArray(),
                    ),
                ]) ?: '{}',
                private: true,
            ));
        } catch (\Throwable $e) {
            // Live status is best-effort; never let a hub blip block publishing.
            $this->logger->debug('Mercure publish failed for social post', ['error' => $e->getMessage()]);
        }
    }
}
