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
use App\Entity\Enum\DocumentAccess;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\DocumentContributorRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Explicit per-user access to a Document — overrides workspace defaults for
 * private documents. Mirrors awork's DocumentContributorModel.
 *
 * Access levels: read | manage. Add a user as 'read' on a private doc to
 * share it with them; 'manage' lets them also edit + invite further people.
 *
 * Voter resolution: DocumentVoter checks for an explicit contributor row
 * before falling back to workspace-membership rules.
 */
#[ORM\Entity(repositoryClass: DocumentContributorRepository::class)]
#[ORM\Table(name: 'document_contributors')]
#[ORM\UniqueConstraint(name: 'document_contributor_unique', columns: ['document_id', 'user_id'])]
#[ORM\Index(name: 'document_contributor_document_idx', columns: ['document_id'])]
#[ORM\Index(name: 'document_contributor_user_idx', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'DocumentContributor',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getDocument())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('MANAGE', object.getDocument())"),
        new Delete(security: "is_granted('MANAGE', object.getDocument())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['document' => 'exact', 'user' => 'exact', 'access' => 'exact'])]
class DocumentContributor
{
    use EntityIdTrait;
    use TimestampableTrait;
    use AuditableTrait;

    #[ORM\ManyToOne(inversedBy: 'contributors')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 12, enumType: DocumentAccess::class)]
    private DocumentAccess $access = DocumentAccess::Read;

    public function getDocument(): Document { return $this->document; }
    public function setDocument(Document $document): self { $this->document = $document; return $this; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getAccess(): DocumentAccess { return $this->access; }
    public function setAccess(DocumentAccess $access): self { $this->access = $access; return $this; }
}
