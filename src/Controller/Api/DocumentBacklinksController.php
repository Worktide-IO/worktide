<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Document;
use App\Entity\User;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * GET /v1/documents/{id}/backlinks
 *
 * Returns every other document in the same workspace whose body
 * references this document. Two reference shapes are recognised:
 *
 *   /v1/documents/<uuid>     — IRI references (BlockNote richtext
 *                              bodies that the editor will support as
 *                              link-cards once Smart-Links lands)
 *   <uuid>                   — bare UUID, useful for markdown bodies
 *                              that embed `[[uuid]]` style references
 *
 * Both patterns end up as a `LIKE %uuid%` SQL filter — the UUID is
 * unique enough that false-positives only happen for the very rare
 * case of someone literally writing the UUID as prose.
 *
 * Visibility follows the standard VIEW voter — backlink rows the
 * caller can't read are filtered out client-side via per-row VIEW
 * checks.
 */
final class DocumentBacklinksController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/documents/{id}/backlinks',
        name: 'api_documents_backlinks',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['GET'],
    )]
    public function __invoke(string $id): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $target = $this->em->find(Document::class, Uuid::fromString($id));
        if ($target === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $target)) {
            throw new AccessDeniedHttpException();
        }

        // Workspace-scoped scan. Both UUID-string and IRI variants are
        // substrings of the body — a single LIKE catches both.
        $rows = $this->em->createQueryBuilder()
            ->select('d.id', 'd.name', 'd.emoji', 'd.body', 'd.bodyFormat')
            ->from(Document::class, 'd')
            ->where('d.workspace = :ws')
            ->andWhere('d.id != :self')
            ->andWhere('d.body LIKE :needle')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('ws', $target->getWorkspace())
            ->setParameter('self', $target->getId(), 'uuid')
            ->setParameter('needle', '%' . $id . '%')
            ->orderBy('d.updatedAt', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getArrayResult();

        $backlinks = [];
        foreach ($rows as $row) {
            // Strip the body — the client only needs context (a short
            // snippet around the match), not the full document.
            $snippet = $this->extractSnippet((string) ($row['body'] ?? ''), $id);
            $rowId = $row['id'];
            $idString = $rowId instanceof Uuid ? $rowId->toRfc4122() : (string) $rowId;
            $backlinks[] = [
                'id' => $idString,
                '@id' => '/v1/documents/' . $idString,
                'name' => $row['name'] ?? 'Untitled',
                'emoji' => $row['emoji'] ?? null,
                'snippet' => $snippet,
            ];
        }

        return new JsonResponse([
            'document' => '/v1/documents/' . $id,
            'count' => count($backlinks),
            'backlinks' => $backlinks,
        ]);
    }

    /**
     * Extracts ~80 chars around the first match so the SPA can show
     * a useful preview. Strips JSON noise (block-IDs, type names) by
     * collapsing whitespace runs and dropping curly braces.
     */
    private function extractSnippet(string $body, string $needle): ?string
    {
        $pos = stripos($body, $needle);
        if ($pos === false) return null;
        $start = max(0, $pos - 60);
        $end = min(strlen($body), $pos + strlen($needle) + 60);
        $slice = substr($body, $start, $end - $start);
        // Make JSON / richtext payloads readable: replace structural
        // characters with spaces, collapse whitespace.
        $clean = preg_replace('/[\{\}\[\]"\\\\]+/', ' ', $slice) ?? $slice;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = trim((string) $clean);
        if ($start > 0) $clean = '… ' . $clean;
        if ($end < strlen($body)) $clean .= ' …';
        return $clean;
    }
}
