<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\IdeaOrigin;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TaggableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\BrainstormNoteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single contribution on a customer's shared brainstorming board (portal
 * screen 5, "Brainstorming"). Free-form notes the customer and agency exchange
 * around goals/ideas — distinct from {@see Idea} (which is votable and can
 * convert to a task/offer). Scoped per {@see Customer}; `origin` distinguishes
 * customer / agency / AI-authored notes.
 */
#[ORM\Entity(repositoryClass: BrainstormNoteRepository::class)]
#[ORM\Table(name: 'brainstorm_notes')]
#[ORM\Index(name: 'brainstorm_customer_idx', columns: ['customer_id'])]
#[ORM\HasLifecycleCallbacks]
class BrainstormNote implements TaggableInterface
{
    use EntityIdTrait;
    use TaggableTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(length: 16, enumType: IdeaOrigin::class, options: ['default' => 'customer'])]
    private IdeaOrigin $origin = IdeaOrigin::Customer;

    /** The portal contact who wrote it (null for agency/AI notes). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $authorContact = null;

    /** Denormalized display name so agency/AI authors render without leaking a User. */
    #[ORM\Column(length: 120)]
    private string $authorName;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }

    public function getCustomer(): Customer { return $this->customer; }
    public function setCustomer(Customer $c): self
    {
        $this->customer = $c;
        $this->setWorkspace($c->getWorkspace());
        return $this;
    }

    public function getBody(): string { return $this->body; }
    public function setBody(string $b): self { $this->body = $b; return $this; }

    public function getOrigin(): IdeaOrigin { return $this->origin; }
    public function setOrigin(IdeaOrigin $o): self { $this->origin = $o; return $this; }

    public function getAuthorContact(): ?Contact { return $this->authorContact; }
    public function setAuthorContact(?Contact $c): self { $this->authorContact = $c; return $this; }

    public function getAuthorName(): string { return $this->authorName; }
    public function setAuthorName(string $n): self { $this->authorName = $n; return $this; }
}
