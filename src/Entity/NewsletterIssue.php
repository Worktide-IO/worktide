<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\NewsletterIssueStatus;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\NewsletterIssueRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One composed-and-sendable issue of a {@see Newsletter} node — subject +
 * markdown body. Admins draft it, then send it once to the node's opted-in
 * contacts (see NewsletterSendController); a sent issue is read-only.
 *
 * Workspace is auto-stamped from the parent newsletter node (see
 * {@see self::setNewsletter()}), so the Post security checks EDIT on that node's
 * workspace — mirroring the Newsletter tree's own workspace-derivation. Tenant
 * isolation via WorkspaceScopedTrait + the read-side WorkspaceScopeExtension.
 */
#[ORM\Entity(repositoryClass: NewsletterIssueRepository::class)]
#[ORM\Table(name: 'newsletter_issues')]
#[ORM\Index(name: 'newsletter_issue_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'newsletter_issue_newsletter_idx', columns: ['newsletter_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'NewsletterIssue',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(securityPostDenormalize: "is_granted('EDIT', object.getNewsletter().getWorkspace())"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'newsletter' => 'exact', 'status' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'sentAt', 'updatedAt'])]
class NewsletterIssue
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;

    #[ORM\ManyToOne(targetEntity: Newsletter::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Newsletter $newsletter = null;

    #[ORM\Column(length: 200)]
    private string $subject = '';

    /** Markdown; rendered to email HTML at send time via league/commonmark. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    // Send state is server-owned: only NewsletterSendController sets it. Clients
    // can create/edit drafts (subject/body) but never mark one sent or forge a count.
    #[ApiProperty(writable: false)]
    #[ORM\Column(length: 16, enumType: NewsletterIssueStatus::class)]
    private NewsletterIssueStatus $status = NewsletterIssueStatus::Draft;

    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $recipientCount = 0;

    public function getNewsletter(): ?Newsletter
    {
        return $this->newsletter;
    }

    public function setNewsletter(?Newsletter $newsletter): self
    {
        $this->newsletter = $newsletter;
        // An issue lives in its newsletter node's workspace — the same
        // auto-stamp convention as Newsletter::setParent().
        if ($newsletter !== null) {
            $this->setWorkspace($newsletter->getWorkspace());
        }

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

    public function getStatus(): NewsletterIssueStatus
    {
        return $this->status;
    }

    public function setStatus(NewsletterIssueStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isSent(): bool
    {
        return $this->status === NewsletterIssueStatus::Sent;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getRecipientCount(): int
    {
        return $this->recipientCount;
    }

    public function setRecipientCount(int $count): self
    {
        $this->recipientCount = $count;

        return $this;
    }
}
