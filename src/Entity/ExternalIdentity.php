<?php

declare(strict_types=1);

namespace App\Entity;

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
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ExternalIdentityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps a user in an external system (Jira accountId, Redmine user id, …) to a
 * Worktide {@see User}, scoped to the {@see Channel} that connection runs on.
 *
 * This is the missing piece the inbound import-filter needs: to decide whether
 * an external ticket is "addressed to someone in this workspace" (assignee or
 * watcher/Mitleser), we must be able to turn an external participant into a
 * Worktide member. {@see \App\Service\Inbound\InboundImportFilter} consults
 * these explicit mappings first, then falls back to matching on
 * {@see $externalEmail} against the workspace's members.
 *
 * `UNIQUE(channel, externalUserId)` keeps one external account → one mapping per
 * connection. The same person on two connected systems gets two rows.
 */
#[ORM\Entity(repositoryClass: ExternalIdentityRepository::class)]
#[ORM\Table(name: 'external_identities')]
#[ORM\UniqueConstraint(name: 'external_identity_channel_user_unique', columns: ['channel_id', 'external_user_id'])]
#[ORM\Index(name: 'external_identity_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'external_identity_email_idx', columns: ['external_email'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ExternalIdentity',
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
    'channel' => 'exact',
    'user' => 'exact',
    'externalUserId' => 'exact',
    'externalEmail' => 'exact',
])]
class ExternalIdentity
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Channel $channel;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Stable external account id (Jira accountId, Redmine user id, …). */
    #[ORM\Column(name: 'external_user_id', length: 191)]
    private string $externalUserId;

    #[ORM\Column(name: 'external_email', length: 180, nullable: true)]
    private ?string $externalEmail = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $externalDisplayName = null;

    public function getChannel(): Channel { return $this->channel; }
    public function setChannel(Channel $channel): self { $this->channel = $channel; return $this; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getExternalUserId(): string { return $this->externalUserId; }
    public function setExternalUserId(string $id): self { $this->externalUserId = $id; return $this; }

    public function getExternalEmail(): ?string { return $this->externalEmail; }
    public function setExternalEmail(?string $email): self { $this->externalEmail = $email; return $this; }

    public function getExternalDisplayName(): ?string { return $this->externalDisplayName; }
    public function setExternalDisplayName(?string $name): self { $this->externalDisplayName = $name; return $this; }
}
