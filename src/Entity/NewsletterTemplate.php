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
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\NewsletterTemplateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reusable, named newsletter content template — subject + markdown body kept
 * for reuse across any newsletter node. Workspace-global (not tied to one node):
 * staff pick one to pre-fill a new {@see NewsletterIssue}, or save the current
 * composer content back as a named template.
 *
 * Admin-only CRUD (workspace Owner/Admin), workspace-scoped like the newsletter
 * tree; the Post uses `securityPostDenormalize` so the workspace grant is checked
 * after the body's `workspace` IRI is bound (the audited self-escalation class).
 */
#[ORM\Entity(repositoryClass: NewsletterTemplateRepository::class)]
#[ORM\Table(name: 'newsletter_templates')]
#[ORM\Index(name: 'newsletter_template_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'NewsletterTemplate',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(securityPostDenormalize: "is_granted('EDIT', object.getWorkspace())"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'name' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'updatedAt'])]
class NewsletterTemplate
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;

    #[ORM\Column(length: 200)]
    private string $name = '';

    #[ORM\Column(length: 200)]
    private string $subject = '';

    /** Markdown; copied verbatim into a NewsletterIssue when applied. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): self
    {
        $this->body = $body;

        return $this;
    }
}
