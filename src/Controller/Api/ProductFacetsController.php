<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Entity\User;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Facet counts for the product catalogue, scoped to the caller's workspace.
 *
 *   GET /v1/product-facets?name=<search>
 *
 * Returns value+count per facet dimension so the SPA can render a faceted
 * sidebar: category, status, price min/max, in-stock, and — dynamically — every
 * key present in Product.attributes (arbitrary properties). Counts reflect the
 * workspace + type=product + optional name search (they do NOT drill down by the
 * other active facet selections; the list itself is narrowed by the ?attr[…] /
 * category / range filters on GET /v1/products).
 *
 * Built with raw SQL GROUP BY (same approach as AiUsageSummaryController) since
 * facet aggregation is not expressible through API Platform collection filters.
 */
final class ProductFacetsController
{
    use ResolvesWorkspaceMembership;

    /** Cap on distinct values returned per facet. */
    private const int VALUES_PER_FACET = 100;

    /** Allowed attribute-key shape (also used as JSON path guard). */
    private const string KEY_RE = '/^[\p{L}\p{N} _\-.]{1,64}$/u';

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(path: '/v1/product-facets', name: 'api_product_facets', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $workspace = $this->resolveWorkspace($request, $user);
        if ($workspace === null) {
            throw new BadRequestHttpException('A workspace is required (X-Workspace-Id).');
        }

        $conn = $this->em->getConnection();

        $where = "workspace_id = :ws AND type = 'product' AND deleted_at IS NULL";
        $params = ['ws' => $workspace->getId()->toBinary()];
        $types = ['ws' => ParameterType::BINARY];

        $name = trim((string) $request->query->get('name', ''));
        if ($name !== '') {
            $where .= ' AND name LIKE :name';
            $params['name'] = '%' . $name . '%';
            $types['name'] = ParameterType::STRING;
        }

        $countRows = static function (string $expr) use ($conn, $where, $params, $types): array {
            return $conn->executeQuery(
                "SELECT {$expr} AS value, COUNT(*) AS c FROM products WHERE {$where} AND {$expr} IS NOT NULL AND {$expr} <> '' GROUP BY value ORDER BY c DESC, value ASC",
                $params,
                $types,
            )->fetchAllAssociative();
        };

        $shape = static fn (array $rows): array => array_map(
            static fn (array $r): array => ['value' => $r['value'], 'count' => (int) $r['c']],
            $rows,
        );

        $category = $shape($countRows('category'));
        $status = $shape($conn->executeQuery(
            "SELECT status AS value, COUNT(*) AS c FROM products WHERE {$where} GROUP BY status ORDER BY c DESC",
            $params,
            $types,
        )->fetchAllAssociative());

        $price = $conn->executeQuery(
            "SELECT MIN(price_cents) AS mn, MAX(price_cents) AS mx FROM products WHERE {$where} AND price_cents IS NOT NULL",
            $params,
            $types,
        )->fetchAssociative() ?: ['mn' => null, 'mx' => null];

        $total = (int) $conn->executeQuery("SELECT COUNT(*) FROM products WHERE {$where}", $params, $types)->fetchOne();
        $inStock = (int) $conn->executeQuery("SELECT COUNT(*) FROM products WHERE {$where} AND stock_qty > 0", $params, $types)->fetchOne();

        // Dynamic attribute keys present in the JSON map, then value counts per key.
        $keys = $conn->executeQuery(
            "SELECT DISTINCT jt.k AS k FROM products, JSON_TABLE(JSON_KEYS(attributes), '$[*]' COLUMNS (k VARCHAR(191) PATH '$')) jt WHERE {$where} AND attributes IS NOT NULL",
            $params,
            $types,
        )->fetchFirstColumn();

        $attributes = [];
        foreach ($keys as $key) {
            if (!\is_string($key) || !preg_match(self::KEY_RE, $key)) {
                continue;
            }
            $path = '$."' . str_replace('"', '', $key) . '"';
            $rows = $conn->executeQuery(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(attributes, :p)) AS value, COUNT(*) AS c FROM products WHERE {$where} AND JSON_EXTRACT(attributes, :p) IS NOT NULL GROUP BY value ORDER BY c DESC, value ASC LIMIT " . self::VALUES_PER_FACET,
                $params + ['p' => $path],
                $types + ['p' => ParameterType::STRING],
            )->fetchAllAssociative();
            if ($rows !== []) {
                $attributes[$key] = $shape($rows);
            }
        }

        return new JsonResponse([
            'total' => $total,
            'facets' => [
                'category' => $category,
                'status' => $status,
                'price' => [
                    'min' => $price['mn'] !== null ? (int) $price['mn'] : null,
                    'max' => $price['mx'] !== null ? (int) $price['mx'] : null,
                ],
                'inStock' => $inStock,
                // Cast so an empty map serialises as {} (object), not [] (array).
                'attributes' => (object) $attributes,
            ],
        ]);
    }
}
