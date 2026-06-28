<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Enum\SocialPostStatus;
use App\Entity\SocialPost;
use App\Message\PublishSocialPostMessage;
use App\Service\Social\SocialPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Runs the publish fan-out for one SocialPost off the request thread. Mirrors
 * {@see ProcessInboundEventHandler}: re-load by id, drop unrecoverably when the
 * row is gone, let recoverable failures bubble so the transport's retry
 * strategy (and ultimately the `failed` transport) handles them.
 *
 * Idempotency: only posts in a publishing-eligible state are acted on, and the
 * publisher only touches Queued targets — so a redelivery of an already-settled
 * post is a no-op.
 */
#[AsMessageHandler]
final class PublishSocialPostHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialPublisher $publisher,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(PublishSocialPostMessage $message): void
    {
        $post = $this->em->find(SocialPost::class, $message->getSocialPostId());

        if ($post === null) {
            throw new UnrecoverableMessageHandlingException(sprintf(
                'SocialPost %s no longer exists; dropping.',
                $message->getSocialPostId()->toRfc4122(),
            ));
        }

        // Scheduled posts are flipped to Publishing by the publish-due command
        // before dispatch; a direct dispatch (approve/publish now) already set
        // Publishing. Anything else (Draft, Canceled, terminal) is a stale
        // redelivery — skip.
        if ($post->getStatus() !== SocialPostStatus::Publishing) {
            $this->logger->debug('SocialPost not in Publishing state; skipping.', [
                'socialPostId' => $post->getId()?->toRfc4122(),
                'status' => $post->getStatus()->value,
            ]);

            return;
        }

        $this->publisher->publishPost($post);
        $this->em->flush();
    }
}
