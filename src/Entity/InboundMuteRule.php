<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\InboundMuteMatchType;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\InboundMuteRuleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A workspace rule that suppresses a KIND of inbound message (e.g. 2FA /
 * verification-code mail) so it no longer clutters the inbox or triggers
 * automation/AI.
 *
 * "Suppress" here does NOT delete: {@see \App\Service\Inbound\InboundMuteMatcher}
 * flags the matched {@see Conversation} with `mutedAt`, keeping the row fully
 * stored + searchable. It merely drops out of the default inbox (SPA filters
 * `exists[mutedAt]=false`) and is reachable via the "Ignoriert" view. Reversible
 * — delete the rule and clear the flag to bring a thread back.
 *
 * Created most easily one-click from a conversation via
 * {@see \App\Controller\Api\ConversationMuteSenderController}, which also
 * back-fills existing matching threads.
 */
#[ORM\Entity(repositoryClass: InboundMuteRuleRepository::class)]
#[ORM\Table(name: 'inbound_mute_rules')]
#[ORM\Index(name: 'inbound_mute_rule_workspace_idx', columns: ['workspace_id', 'is_enabled'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'InboundMuteRule',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'matchType' => 'exact', 'value' => 'partial'])]
#[ApiFilter(BooleanFilter::class, properties: ['isEnabled'])]
class InboundMuteRule
{
    use EntityIdTrait;
    use TimestampableTrait;
    use VersionedTrait;
    use AuditableTrait;
    use WorkspaceScopedTrait;

    #[ORM\Column(length: 20, enumType: InboundMuteMatchType::class)]
    private InboundMuteMatchType $matchType = InboundMuteMatchType::SenderEmail;

    /** The address (for SenderEmail) or substring (for SubjectContains) to match. */
    #[Assert\NotBlank]
    #[Assert\Length(max: 250)]
    #[ORM\Column(length: 250)]
    private string $value = '';

    #[ORM\Column]
    private bool $isEnabled = true;

    /** Server-managed: how many messages this rule has muted (observability). */
    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $matchCount = 0;

    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastMatchedAt = null;

    public function getMatchType(): InboundMuteMatchType { return $this->matchType; }
    public function setMatchType(InboundMuteMatchType $t): self { $this->matchType = $t; return $this; }

    public function getValue(): string { return $this->value; }
    public function setValue(string $v): self { $this->value = trim($v); return $this; }

    public function isEnabled(): bool { return $this->isEnabled; }
    public function setIsEnabled(bool $v): self { $this->isEnabled = $v; return $this; }

    public function getMatchCount(): int { return $this->matchCount; }
    public function setMatchCount(int $n): self { $this->matchCount = $n; return $this; }

    public function getLastMatchedAt(): ?\DateTimeImmutable { return $this->lastMatchedAt; }
    public function setLastMatchedAt(?\DateTimeImmutable $t): self { $this->lastMatchedAt = $t; return $this; }

    public function registerHit(\DateTimeImmutable $when): void
    {
        ++$this->matchCount;
        $this->lastMatchedAt = $when;
    }
}
