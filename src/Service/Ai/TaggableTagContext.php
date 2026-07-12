<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Contact;
use App\Entity\Conversation;
use App\Entity\Document;
use App\Entity\Enum\TagScope;
use App\Entity\InboundEvent;
use App\Entity\Lead;
use App\Entity\Product;
use App\Entity\TaggableInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Central registry of per-type knowledge for AI tag suggestions:
 *  - which {@see TagScope} a record type maps to, and
 *  - how to distil a record into a short text blob the model reasons over.
 *
 * Adding a new taggable type = one arm in each match() below (and, for the
 * draft/create flow, one entry in {@see self::SCOPES}). Types not listed here
 * simply don't offer AI tag suggestions yet.
 */
final class TaggableTagContext
{
    private const MAX_TEXT = 4000;
    private const MAX_EVENTS = 15;

    /**
     * Draft/create-mode targets → scope. The key is the string a client sends
     * as "target" when the record doesn't exist yet (so there's no entity to
     * infer the scope from).
     *
     * @var array<string, TagScope>
     */
    private const SCOPES = [
        'contact' => TagScope::Contact,
        'lead' => TagScope::Lead,
        'product' => TagScope::Product,
        'document' => TagScope::Document,
        'conversation' => TagScope::Conversation,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return list<string> the target keys accepted in draft mode */
    public function supportedTargets(): array
    {
        return array_keys(self::SCOPES);
    }

    /**
     * Scope for a draft/create request identified by its "target" string.
     *
     * @throws \InvalidArgumentException on an unknown target
     */
    public function scopeForTarget(string $target): TagScope
    {
        $key = strtolower(trim($target));

        return self::SCOPES[$key]
            ?? throw new \InvalidArgumentException(sprintf('Unsupported tag-suggestion target "%s".', $target));
    }

    /**
     * Scope for an existing taggable record.
     *
     * @throws \InvalidArgumentException when the type isn't supported yet
     */
    public function scopeFor(TaggableInterface $entity): TagScope
    {
        return match (true) {
            $entity instanceof Contact => TagScope::Contact,
            $entity instanceof Lead => TagScope::Lead,
            $entity instanceof Product => TagScope::Product,
            $entity instanceof Document => TagScope::Document,
            $entity instanceof Conversation => TagScope::Conversation,
            default => throw new \InvalidArgumentException('This record type does not support AI tag suggestions yet.'),
        };
    }

    /**
     * Distil an existing record into the text the model tags on.
     *
     * @throws \InvalidArgumentException when the type isn't supported yet
     */
    public function textFor(TaggableInterface $entity): string
    {
        $text = match (true) {
            $entity instanceof Contact => $this->fromContact($entity),
            $entity instanceof Lead => $this->fromLead($entity),
            $entity instanceof Product => $this->fromProduct($entity),
            $entity instanceof Document => $this->fromDocument($entity),
            $entity instanceof Conversation => $this->fromConversation($entity),
            default => throw new \InvalidArgumentException('This record type does not support AI tag suggestions yet.'),
        };

        return mb_substr(trim($text), 0, self::MAX_TEXT);
    }

    private function fromContact(Contact $c): string
    {
        return $this->lines([
            'Name' => $c->getFullName(),
            'Position' => $c->getPosition(),
            'Email' => $c->getEmail(),
            'Notes' => $c->getNotes(),
        ]);
    }

    private function fromLead(Lead $l): string
    {
        return $this->lines([
            'Name' => $l->getName(),
            'Company' => $l->isCompany() ? 'yes' : 'no',
            'Role' => $l->getRole(),
            'Industry' => $l->getIndustry(),
            'Region' => $l->getRegion(),
            'Website' => $l->getWebsite(),
            'Notes' => $l->getNotes(),
        ]);
    }

    private function fromProduct(Product $p): string
    {
        return $this->lines([
            'Name' => $p->getName(),
            'Category' => $p->getCategory(),
            'Description' => $p->getDescription(),
        ]);
    }

    private function fromDocument(Document $d): string
    {
        return $this->lines([
            'Title' => $d->getName(),
            'Body' => $d->getBody(),
        ]);
    }

    private function fromConversation(Conversation $c): string
    {
        $parts = ['Subject: ' . $c->getSubject()];

        /** @var list<InboundEvent> $events */
        $events = $this->em->getRepository(InboundEvent::class)->findBy(
            ['conversation' => $c],
            ['receivedAt' => 'ASC'],
            self::MAX_EVENTS,
        );
        foreach ($events as $e) {
            $body = trim((string) $e->getBody());
            if ($body !== '') {
                $parts[] = 'Message from ' . ($e->getSenderRaw() ?? 'customer') . ': ' . $body;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Join "Label: value" lines, dropping empty values.
     *
     * @param array<string, string|null> $fields
     */
    private function lines(array $fields): string
    {
        $out = [];
        foreach ($fields as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $out[] = $label . ': ' . $value;
            }
        }

        return implode("\n", $out);
    }
}
