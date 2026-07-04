<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Default search backend — needs no external service. Runs one workspace-scoped
 * `LIKE` query per searchable type against the live DB, merges and sorts by
 * recency. Good enough until a workspace outgrows `LIKE` (then flip
 * SEARCH_PROVIDER=meilisearch). Indexing is a no-op here.
 */
final class MysqlSearchProvider implements SearchProviderInterface
{
    /**
     * type slug => searchable text columns.
     *
     * @var array<string, string[]>
     */
    private const FIELDS = [
        'conversation' => ['subject', 'senderRaw'],
        'inbound_event' => ['subject', 'body', 'senderRaw'],
        'outbound_message' => ['subject', 'body'],
        'task' => ['title', 'description', 'identifier'],
        'customer' => ['name', 'legalName', 'email', 'notes'],
        'contact' => ['firstName', 'lastName', 'email', 'position', 'notes'],
        'project' => ['name', 'number', 'description'],
        'document' => ['name', 'body'],
        'comment' => ['content'],
        'lead' => ['name', 'email', 'website', 'role', 'industry', 'region', 'notes'],
        'research_mission' => ['prompt', 'summary'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SearchDocumentFactory $factory,
    ) {}

    public function search(string $query, Uuid $workspaceId, array $types = [], int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $workspace = $this->em->getReference(Workspace::class, $workspaceId);

        $hits = [];
        foreach ($this->factory->searchableClasses() as $class) {
            $type = $this->factory->typeForClass($class);
            if ($type === null || ($types !== [] && !\in_array($type, $types, true))) {
                continue;
            }
            $fields = self::FIELDS[$type] ?? [];
            if ($fields === []) {
                continue;
            }

            $qb = $this->em->createQueryBuilder()
                ->select('e')
                ->from($class, 'e')
                ->where('e.workspace = :ws')
                ->setParameter('ws', $workspace)
                ->setMaxResults($limit);

            $or = $qb->expr()->orX();
            foreach ($fields as $i => $field) {
                $or->add(sprintf('e.%s LIKE :q ESCAPE \'\\\'', $field));
            }
            $qb->andWhere($or)->setParameter('q', $like);
            $qb->orderBy('e.updatedAt', 'DESC');

            foreach ($qb->getQuery()->getResult() as $entity) {
                $doc = $this->factory->build($entity);
                if ($doc === null) {
                    continue;
                }
                $hits[] = new SearchHit(
                    type: $doc->type,
                    id: $doc->id->toRfc4122(),
                    iri: $doc->iri,
                    title: $doc->title,
                    snippet: mb_substr($doc->body, 0, 200),
                    updatedAt: $doc->updatedAt->getTimestamp(),
                    parentType: $doc->parentType,
                    parentId: $doc->parentId,
                );
            }
        }

        usort($hits, static fn (SearchHit $a, SearchHit $b): int => ($b->updatedAt ?? 0) <=> ($a->updatedAt ?? 0));

        return \array_slice($hits, 0, $limit);
    }

    public function index(SearchDocument $document): void {}

    public function delete(string $type, Uuid $id): void {}

    public function reindex(iterable $documents): void {}

    public function requiresIndexing(): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
