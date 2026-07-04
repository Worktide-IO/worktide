<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Comment;
use App\Entity\Contact;
use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\Document;
use App\Entity\InboundEvent;
use App\Entity\Lead;
use App\Entity\OutboundMessage;
use App\Entity\Project;
use App\Entity\ResearchMission;
use App\Entity\Task;
use Symfony\Component\Uid\Uuid;

/**
 * Single source of truth for what is searchable and how each entity maps to a
 * {@see SearchDocument}. Used by the indexing pipeline (which types to react to),
 * the reindex command (which classes to walk) and both providers (field shape).
 */
final class SearchDocumentFactory
{
    private const SNIPPET_MAX = 20000;

    /**
     * entity class => [type slug, API-Platform resource slug].
     *
     * @var array<class-string, array{string, string}>
     */
    private const MAP = [
        Conversation::class => ['conversation', 'conversations'],
        InboundEvent::class => ['inbound_event', 'inbound_events'],
        OutboundMessage::class => ['outbound_message', 'outbound_messages'],
        Task::class => ['task', 'tasks'],
        Customer::class => ['customer', 'customers'],
        Contact::class => ['contact', 'contacts'],
        Project::class => ['project', 'projects'],
        Document::class => ['document', 'documents'],
        Comment::class => ['comment', 'comments'],
        Lead::class => ['lead', 'leads'],
        ResearchMission::class => ['research_mission', 'research_missions'],
    ];

    public function build(object $entity): ?SearchDocument
    {
        $cfg = self::MAP[$entity::class] ?? null;
        if ($cfg === null) {
            return null;
        }
        // Soft-deleted rows drop out of the index.
        if (method_exists($entity, 'isDeleted') && $entity->isDeleted() === true) {
            return null;
        }
        $id = $entity->getId();
        if (!$id instanceof Uuid) {
            return null;
        }
        $workspaceId = $entity->getWorkspace()->getId();
        if (!$workspaceId instanceof Uuid) {
            return null;
        }

        [$type, $resource] = $cfg;
        [$title, $body] = $this->extract($entity);
        [$parentType, $parentId] = $this->parent($entity);

        return new SearchDocument(
            type: $type,
            id: $id,
            workspaceId: $workspaceId,
            title: $this->clip($title, 500),
            body: $this->clip($body, self::SNIPPET_MAX),
            iri: '/v1/' . $resource . '/' . $id->toRfc4122(),
            updatedAt: $entity->getUpdatedAt(),
            parentType: $parentType,
            parentId: $parentId,
        );
    }

    /** @return array<class-string> */
    public function searchableClasses(): array
    {
        return array_keys(self::MAP);
    }

    /** @return string[] */
    public function typeSlugs(): array
    {
        return array_map(static fn (array $c): string => $c[0], array_values(self::MAP));
    }

    /** @return class-string|null */
    public function classForType(string $type): ?string
    {
        foreach (self::MAP as $class => [$slug]) {
            if ($slug === $type) {
                return $class;
            }
        }

        return null;
    }

    public function typeForClass(string $class): ?string
    {
        return self::MAP[$class][0] ?? null;
    }

    /**
     * @return array{string, string} [title, body]
     */
    private function extract(object $e): array
    {
        return match ($e::class) {
            Conversation::class => [(string) $e->getSubject(), (string) $e->getSenderRaw()],
            InboundEvent::class => [(string) $e->getSubject(), $this->join([$e->getBody(), $e->getSenderRaw()])],
            OutboundMessage::class => [(string) $e->getSubject(), (string) $e->getBody()],
            Task::class => [(string) $e->getTitle(), $this->join([$e->getIdentifier(), $e->getDescription()])],
            Customer::class => [(string) $e->getName(), $this->join([$e->getLegalName(), $e->getEmail(), $e->getNotes()])],
            Contact::class => [trim($e->getFirstName() . ' ' . $e->getLastName()), $this->join([$e->getPosition(), $e->getEmail(), $e->getNotes()])],
            Project::class => [(string) $e->getName(), $this->join([$e->getNumber(), $e->getDescription()])],
            Document::class => [(string) $e->getName(), (string) $e->getBody()],
            Comment::class => [$this->clip($e->getContent(), 80), $e->getContent()],
            Lead::class => [(string) $e->getName(), $this->join([$e->getEmail(), $e->getPhone(), $e->getWebsite(), $e->getRole(), $e->getIndustry(), $e->getRegion(), $e->getNotes()])],
            ResearchMission::class => [$this->clip($e->getPrompt(), 500), $this->join([$e->getSummary(), $e->getBrief() !== null ? json_encode($e->getBrief(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) : null])],
            default => ['', ''],
        };
    }

    /**
     * Navigable container for message-level hits (a mail body match opens the
     * thread). Other types have no parent.
     *
     * @return array{?string, ?string} [parentType, parentId]
     */
    private function parent(object $entity): array
    {
        if ($entity instanceof InboundEvent || $entity instanceof OutboundMessage) {
            $conversationId = $entity->getConversation()?->getId();
            if ($conversationId instanceof Uuid) {
                return ['conversation', $conversationId->toRfc4122()];
            }
        }

        return [null, null];
    }

    /**
     * @param array<?string> $parts
     */
    private function join(array $parts): string
    {
        return trim(implode("\n", array_filter(array_map('strval', $parts), static fn (string $s): bool => $s !== '')));
    }

    private function clip(string $s, int $max): string
    {
        $s = trim($s);

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }
}
