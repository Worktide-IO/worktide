<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\CustomerSystem;
use App\Repository\CustomerSystemRepository;
use App\Service\Portal\PortalAccessResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Customer-portal "Monitoring" screen — the customer's systems inventory and
 * managed status (see docs/wireframes, screen 3).
 *
 * SCOPE NOTE: {@see CustomerSystem} is an operational inventory, not a metrics
 * store — there is no uptime/latency/incident data in the model yet (the
 * wireframe's live charts would need a net-new monitoring pipeline). This
 * endpoint therefore reports the real, honest data: what systems exist, their
 * type/environment, live URL, and whether they are actively managed.
 *
 * SECURITY: the curated DTO deliberately OMITS `credentialsNotes`, `notes`,
 * `adminLoginUrl` and `stagingUrl` — those are privileged/internal and must
 * never reach a customer. Gated behind the `monitoring` feature flag.
 */
final class PortalSystemsController
{
    /** Portal-facing German labels for the internal environment enum. */
    private const ENV_LABELS = [
        'production' => 'Produktion',
        'staging' => 'Staging',
        'development' => 'Entwicklung',
    ];

    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly CustomerSystemRepository $systems,
    ) {}

    #[Route(
        path: '/v1/portal/systems',
        name: 'api_portal_systems_list',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('monitoring');

        $systems = $this->systems->findVisiblePortalSystems($this->portal->customer());

        return new JsonResponse([
            'systems' => array_map($this->systemDto(...), $systems),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function systemDto(CustomerSystem $system): array
    {
        $env = $system->getEnvironment()->value;

        return [
            'id' => $system->getId()?->toRfc4122(),
            'name' => $system->getName(),
            'type' => $system->getType()->value, // typo3 | wordpress | shopware | …
            'systemVersion' => $system->getSystemVersion(),
            'environment' => $env,
            'environmentLabel' => self::ENV_LABELS[$env] ?? $env,
            'url' => $system->getUrl(), // live URL only — never staging/admin
            'hostingProvider' => $system->getHostingProvider(),
            'isActive' => $system->isActive(),
            'statusLabel' => $system->isActive() ? 'Aktiv' : 'Inaktiv',
        ];
    }
}
