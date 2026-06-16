<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Enum\DocumentBodyFormat;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\DocumentRevisionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only snapshot of a Document at one save-point. Lets the wiki
 * UI walk back through history and restore older bodies.
 *
 * Created by DocumentRevisionListener whenever a Document's name or
 * body changes (preUpdate). The pre-existing values are snapshotted —
 * the listener writes the LAST KNOWN state, not the new one, so the
 * revision list reads like a real history ("Stand vom Datum X").
 *
 * Revisions are read-only — no Patch/Post via the API. Restoring goes
 * through a custom action on Document, not by mutating the revision.
 */
#[ORM\Entity(repositoryClass: DocumentRevisionRepository::class)]
#[ORM\Table(name: 'document_revisions')]
#[ORM\Index(name: 'document_revision_doc_idx', columns: ['document_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'DocumentRevision',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['document' => 'exact', 'workspace' => 'exact'])]
class DocumentRevision
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne(inversedBy: 'revisions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    /** Document name at the snapshot moment. */
    #[ORM\Column(length: 240)]
    private string $name = '';

    /** Body content at the snapshot moment. JSON for richtext, plain string for markdown/html. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    #[ORM\Column(length: 16, enumType: DocumentBodyFormat::class)]
    private DocumentBodyFormat $bodyFormat = DocumentBodyFormat::Markdown;

    /**
     * The user who authored THIS specific version (= the saver
     * immediately before the snapshot). Distinct from
     * Document.createdByUser which always points at the original
     * creator.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    public function getDocument(): Document { return $this->document; }
    public function setDocument(Document $d): self { $this->document = $d; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $n): self { $this->name = $n; return $this; }

    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $b): self { $this->body = $b; return $this; }

    public function getBodyFormat(): DocumentBodyFormat { return $this->bodyFormat; }
    public function setBodyFormat(DocumentBodyFormat $f): self { $this->bodyFormat = $f; return $this; }

    public function getAuthor(): ?User { return $this->author; }
    public function setAuthor(?User $a): self { $this->author = $a; return $this; }
}
