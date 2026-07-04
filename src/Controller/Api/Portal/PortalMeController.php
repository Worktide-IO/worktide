<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Service\Portal\PortalAccessResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /v1/portal/me — the portal bootstrap call.
 *
 * Returns a curated view of who the portal user is (their Contact + Customer),
 * the workspace display name, and the per-workspace feature flags that drive
 * the portal navigation. Never exposes staff/internal fields.
 *
 * The `features` map spans all planned portal screens so the frontend can
 * render the full navigation with not-yet-enabled items locked. In Phase 1
 * only `tickets` is implemented; the rest default to false unless a workspace
 * admin has flipped them in `settings.portal.features`.
 */
final class PortalMeController
{
    /** Canonical portal feature keys — one per wireframe screen. */
    private const FEATURE_KEYS = [
        'tickets',
        'dashboard',
        'monitoring',
        'agreements',
        'ideas',
        'social',
        'forms',
        'documents',
    ];

    public function __construct(
        private readonly PortalAccessResolver $portal,
    ) {}

    #[Route(
        path: '/v1/portal/me',
        name: 'api_portal_me',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function __invoke(): JsonResponse
    {
        $this->portal->assertPortalEnabled();

        $contact = $this->portal->contact();
        $customer = $this->portal->customer();
        $workspace = $this->portal->workspace();

        return new JsonResponse([
            'contact' => [
                'id' => $contact->getId()?->toRfc4122(),
                'firstName' => $contact->getFirstName(),
                'lastName' => $contact->getLastName(),
                'email' => $contact->getEmail(),
            ],
            'customer' => [
                'id' => $customer->getId()?->toRfc4122(),
                'name' => $customer->getName(),
            ],
            'workspaceName' => $workspace->getName(),
            'features' => $this->features($workspace->getSettings()),
        ]);
    }

    /**
     * @param array<string, mixed>|null $settings
     * @return array<string, bool>
     */
    private function features(?array $settings): array
    {
        $stored = $settings['portal']['features'] ?? [];
        $stored = \is_array($stored) ? $stored : [];

        $features = [];
        foreach (self::FEATURE_KEYS as $key) {
            // `tickets` is live in P1 and on by default; everything else is
            // off unless a workspace admin has explicitly enabled it.
            $default = $key === 'tickets';
            $features[$key] = ($stored[$key] ?? $default) === true;
        }
        return $features;
    }
}
