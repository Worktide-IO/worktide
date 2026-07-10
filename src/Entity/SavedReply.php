<?php

declare(strict_types=1);

namespace App\Entity;

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
use App\Repository\SavedReplyRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Trait\TranslatableTrait;

/**
 * A workspace-scoped canned response (Phase C Schicht 2). The {@see $body} may
 * contain `{{variable}}` placeholders that {@see \App\Service\SavedReplyRenderer}
 * interpolates against a conversation + agent context at insert time
 * (`{{customer.name}}`, `{{conversation.subject}}`, `{{agent.name}}`, …).
 *
 * Visible to every workspace member (scoped via
 * {@see \App\ApiPlatform\Doctrine\WorkspaceScopeExtension}); plain CRUD like the
 * other workspace-config resources.
 */
#[ORM\Entity(repositoryClass: SavedReplyRepository::class)]
#[ORM\Table(name: 'saved_replies')]
#[ORM\Index(name: 'saved_reply_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'SavedReply',
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'name' => 'partial',
    'shortcut' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
class SavedReply implements TranslatableInterface
{
    use TranslatableTrait;
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Optional SPA quick-insert trigger, e.g. `/greeting`. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $shortcut = null;

    /** Reply text with optional `{{variable}}` placeholders. */
    #[ORM\Column(type: 'text')]
    private string $body = '';

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getShortcut(): ?string { return $this->shortcut; }
    public function setShortcut(?string $shortcut): self { $this->shortcut = $shortcut; return $this; }

    public function getBody(): string { return $this->body; }
    public function setBody(string $body): self { $this->body = $body; return $this; }
    /**
     * @return list<string>
     */
    public static function translatableFields(): array
    {
        return ['name', 'description', 'body'];
    }

}
