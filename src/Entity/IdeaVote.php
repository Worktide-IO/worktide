<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\IdeaVoteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One upvote on an {@see Idea} by one {@see User} (a portal contact's user or
 * staff). The UNIQUE(idea, voter) constraint makes voting idempotent — a user
 * can upvote an idea at most once. Not an API resource: voting goes through the
 * portal vote action, which also keeps {@see Idea::$voteCount} in sync.
 */
#[ORM\Entity(repositoryClass: IdeaVoteRepository::class)]
#[ORM\Table(name: 'idea_votes')]
#[ORM\UniqueConstraint(name: 'idea_vote_idea_voter_uniq', columns: ['idea_id', 'voter_id'])]
#[ORM\Index(name: 'idea_vote_idea_idx', columns: ['idea_id'])]
#[ORM\Index(name: 'idea_vote_voter_idx', columns: ['voter_id'])]
#[ORM\HasLifecycleCallbacks]
class IdeaVote
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne(inversedBy: 'votes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Idea $idea;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $voter;

    public function getIdea(): Idea { return $this->idea; }
    public function setIdea(Idea $idea): self
    {
        $this->idea = $idea;
        // Votes live in the idea's workspace (WorkspaceScopedTrait).
        $this->setWorkspace($idea->getWorkspace());
        return $this;
    }

    public function getVoter(): User { return $this->voter; }
    public function setVoter(User $voter): self { $this->voter = $voter; return $this; }
}
