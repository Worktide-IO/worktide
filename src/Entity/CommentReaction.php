<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\CommentReactionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One emoji reaction by one user on one comment. Unique per (comment, user,
 * emoji) so a single user can't fire the same emoji twice but can stack
 * different ones (👍 + 🎉 + ❤️).
 *
 * Managed exclusively via the CommentReactionsController — no direct
 * API Platform CRUD here.
 */
#[ORM\Entity(repositoryClass: CommentReactionRepository::class)]
#[ORM\Table(name: 'comment_reactions')]
#[ORM\UniqueConstraint(name: 'comment_reaction_unique', columns: ['comment_id', 'user_id', 'emoji'])]
#[ORM\Index(name: 'comment_reaction_comment_idx', columns: ['comment_id'])]
#[ORM\HasLifecycleCallbacks]
class CommentReaction
{
    use EntityIdTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne(inversedBy: 'reactions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Comment $comment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32)]
    private string $emoji;

    public function getComment(): Comment
    {
        return $this->comment;
    }

    public function setComment(Comment $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getEmoji(): string
    {
        return $this->emoji;
    }

    public function setEmoji(string $emoji): self
    {
        $this->emoji = $emoji;
        return $this;
    }
}
