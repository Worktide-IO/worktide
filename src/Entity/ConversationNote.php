<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ConversationNoteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A private internal note on a {@see Conversation} — the `note` thread type
 * (Phase C Schicht 2). Visible only to workspace members, never sent to the
 * customer. Supports @-mentions in {@see $body}: mentioning a user (a
 * `/v1/users/<uuid>` IRI) fires `conversation.user_mentioned` via
 * {@see \App\EventSubscriber\ConversationNoteMentionNotifier}, exactly like
 * Document mentions.
 *
 * Author is {@see AuditableTrait::$createdByUser}; the author or a workspace
 * admin may edit/delete ({@see \App\Security\Voter\ConversationNoteVoter}).
 * Incoming customer messages and agent replies are NOT notes — they remain
 * {@see InboundEvent} / {@see OutboundMessage}; the unified thread view merges
 * all three (see {@see \App\Service\ConversationTimeline}).
 */
#[ORM\Entity(repositoryClass: ConversationNoteRepository::class)]
#[ORM\Table(name: 'conversation_notes')]
#[ORM\Index(name: 'conversation_note_conversation_idx', columns: ['conversation_id'])]
#[ORM\Index(name: 'conversation_note_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ConversationNote',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object)"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object)"),
        new Delete(security: "is_granted('DELETE', object)"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'conversation' => 'exact',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isPinned'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
class ConversationNote
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\Column(type: 'text')]
    private string $body = '';

    #[ORM\Column]
    private bool $isPinned = false;

    public function getConversation(): Conversation { return $this->conversation; }
    public function setConversation(Conversation $conversation): self { $this->conversation = $conversation; return $this; }

    public function getBody(): string { return $this->body; }
    public function setBody(string $body): self { $this->body = $body; return $this; }

    public function isPinned(): bool { return $this->isPinned; }
    public function setIsPinned(bool $pinned): self { $this->isPinned = $pinned; return $this; }
}
