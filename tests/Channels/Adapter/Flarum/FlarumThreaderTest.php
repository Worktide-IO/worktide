<?php

declare(strict_types=1);

namespace App\Tests\Channels\Adapter\Flarum;

use App\Channels\Adapter\Flarum\FlarumAdapter;
use App\Channels\Adapter\Flarum\FlarumThreader;
use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Enum\ConversationStatus;
use App\Entity\InboundEvent;
use App\Entity\Workspace;
use App\Repository\ConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the Flarum conversation-threading strategy: one thread
 * per discussion, joins existing threads, updates lastEventAt on newer events.
 */
final class FlarumThreaderTest extends TestCase
{
    public function testCreatesConversationForNewDiscussion(): void
    {
        $conversations = $this->createStub(ConversationRepository::class);
        $conversations->method('findByThreadKey')->willReturn(null);

        $threader = new FlarumThreader($conversations, $this->createStub(EntityManagerInterface::class));
        $channel = $this->channel();
        $event = $this->event($channel);

        $conversation = $threader->attach($channel, $event);

        self::assertSame('flarum:42', $conversation->getThreadKey());
        self::assertSame(ConversationStatus::Open, $conversation->getStatus());
        self::assertSame('TYPO3 v13 released', $conversation->getSubject());
        self::assertNull($conversation->getSenderRaw()); // author handle not included by default
        self::assertSame($conversation, $event->getConversation());
    }

    public function testCreatesConversationWithAuthorWhenProvided(): void
    {
        $conversations = $this->createStub(ConversationRepository::class);
        $conversations->method('findByThreadKey')->willReturn(null);

        $threader = new FlarumThreader($conversations, $this->createStub(EntityManagerInterface::class));
        $channel = $this->channel();
        $event = $this->event($channel, authorHandle: 'forum_user');

        $conversation = $threader->attach($channel, $event);

        self::assertSame('forum_user', $conversation->getSenderRaw());
    }

    public function testJoinsExistingThread(): void
    {
        $channel = $this->channel();
        $existing = (new Conversation())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setThreadKey('flarum:42')
            ->setSubject('TYPO3 v13 released')
            ->setStatus(ConversationStatus::Open);

        $conversations = $this->createStub(ConversationRepository::class);
        $conversations->method('findByThreadKey')->willReturn($existing);

        $threader = new FlarumThreader($conversations, $this->createStub(EntityManagerInterface::class));
        $event = $this->event($channel);

        $conversation = $threader->attach($channel, $event);

        self::assertSame($existing, $conversation);
        self::assertSame($existing, $event->getConversation());
    }

    public function testUpdatesLastEventAtOnNewerEvent(): void
    {
        $channel = $this->channel();
        $later = new \DateTimeImmutable('2026-07-22 12:00:00');
        $existing = (new Conversation())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setThreadKey('flarum:42')
            ->setSubject('TYPO3 v13 released')
            ->setStatus(ConversationStatus::Open)
            ->setLastEventAt(new \DateTimeImmutable('2026-07-20 10:00:00'));

        $conversations = $this->createStub(ConversationRepository::class);
        $conversations->method('findByThreadKey')->willReturn($existing);

        $threader = new FlarumThreader($conversations, $this->createStub(EntityManagerInterface::class));
        $event = $this->event($channel, receivedAt: $later);

        $conversation = $threader->attach($channel, $event);

        self::assertEquals($later, $conversation->getLastEventAt());
    }

    public function testOlderEventDoesNotRollBackLastEventAt(): void
    {
        $channel = $this->channel();
        $existingAt = new \DateTimeImmutable('2026-07-22 12:00:00');
        $existing = (new Conversation())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setThreadKey('flarum:42')
            ->setSubject('TYPO3 v13 released')
            ->setStatus(ConversationStatus::Open)
            ->setLastEventAt($existingAt);

        $conversations = $this->createStub(ConversationRepository::class);
        $conversations->method('findByThreadKey')->willReturn($existing);

        $threader = new FlarumThreader($conversations, $this->createStub(EntityManagerInterface::class));
        $stale = $this->event($channel, receivedAt: new \DateTimeImmutable('2026-07-20 10:00:00'));

        $conversation = $threader->attach($channel, $stale);

        self::assertEquals($existingAt, $conversation->getLastEventAt());
    }

    public function testBackfillsSenderRawOnExistingThread(): void
    {
        $channel = $this->channel();
        $existing = (new Conversation())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setThreadKey('flarum:42')
            ->setSubject('TYPO3 v13 released')
            ->setStatus(ConversationStatus::Open);

        $conversations = $this->createStub(ConversationRepository::class);
        $conversations->method('findByThreadKey')->willReturn($existing);

        $threader = new FlarumThreader($conversations, $this->createStub(EntityManagerInterface::class));
        $event = $this->event($channel, authorHandle: 'new_user');

        $conversation = $threader->attach($channel, $event);

        self::assertSame('new_user', $conversation->getSenderRaw());
    }

    public function testDoesNotOverwriteExistingSenderRaw(): void
    {
        $channel = $this->channel();
        $existing = (new Conversation())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setThreadKey('flarum:42')
            ->setSubject('TYPO3 v13 released')
            ->setStatus(ConversationStatus::Open)
            ->setSenderRaw('original_author');

        $conversations = $this->createStub(ConversationRepository::class);
        $conversations->method('findByThreadKey')->willReturn($existing);

        $threader = new FlarumThreader($conversations, $this->createStub(EntityManagerInterface::class));
        $event = $this->event($channel, authorHandle: null);

        $conversation = $threader->attach($channel, $event);

        self::assertSame('original_author', $conversation->getSenderRaw());
    }

    private function channel(): Channel
    {
        return (new Channel())
            ->setName('Flarum')
            ->setAdapterCode(FlarumAdapter::CODE)
            ->setWorkspace(new Workspace());
    }

    private function event(
        Channel $channel,
        ?\DateTimeImmutable $receivedAt = null,
        ?string $authorHandle = null,
    ): InboundEvent {
        return (new InboundEvent())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setExternalId('discussion:42')
            ->setSubject('TYPO3 v13 released')
            ->setBody('Awesome news about TYPO3!')
            ->setSenderRaw($authorHandle)
            ->setSourceMetadata(['discussionId' => 42])
            ->setReceivedAt($receivedAt ?? new \DateTimeImmutable('2026-07-20T10:00:00+00:00'));
    }
}
