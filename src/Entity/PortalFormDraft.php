<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\PortalFormDraftRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A portal contact's in-progress answers to a {@see PublicForm} — lets a
 * questionnaire (e.g. the SEO-Audit) be filled across multiple sessions and
 * resumed. One draft per (form, contact); it is deleted once the contact
 * actually submits (which materializes the real {@see PublicFormSubmission}).
 *
 * Not an API resource: managed only through the portal draft endpoints.
 */
#[ORM\Entity(repositoryClass: PortalFormDraftRepository::class)]
#[ORM\Table(name: 'portal_form_drafts')]
#[ORM\UniqueConstraint(name: 'portal_form_draft_form_contact_uniq', columns: ['form_id', 'contact_id'])]
#[ORM\Index(name: 'portal_form_draft_contact_idx', columns: ['contact_id'])]
#[ORM\HasLifecycleCallbacks]
class PortalFormDraft
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PublicForm $form;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Contact $contact;

    /** @var array<string, mixed> partial field values keyed by field key */
    #[ORM\Column(type: 'json')]
    private array $answers = [];

    public function getForm(): PublicForm { return $this->form; }
    public function setForm(PublicForm $form): self
    {
        $this->form = $form;
        $this->setWorkspace($form->getWorkspace());
        return $this;
    }

    public function getContact(): Contact { return $this->contact; }
    public function setContact(Contact $contact): self { $this->contact = $contact; return $this; }

    /** @return array<string, mixed> */
    public function getAnswers(): array { return $this->answers; }

    /** @param array<string, mixed> $answers */
    public function setAnswers(array $answers): self { $this->answers = $answers; return $this; }
}
