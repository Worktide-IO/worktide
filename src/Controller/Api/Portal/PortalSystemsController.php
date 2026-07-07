<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\CustomerSystem;
use App\Entity\Enum\IncidentKind;
use App\Entity\SystemIncident;
use App\Repository\CustomerSystemRepository;
use App\Repository\SystemIncidentRepository;
use App\Repository\SystemUptimeDayRepository;
use App\Service\Portal\PortalAccessResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Customer-portal "Monitoring" screen (wireframe screen 3): the customer's
 * systems with live status, 30-day uptime %, average latency, an uptime
 * sparkline, and the "Vorfälle & Wartung" list.
 *
 * Live status is derived from OPEN {@see SystemIncident}s (Outage → Störung,
 * Degraded → Langsam, Maintenance → Wartung, none → Online); uptime %/latency
 * are aggregated from the last 30 {@see \App\Entity\SystemUptimeDay} rollups
 * written by `app:monitoring:probe`.
 *
 * SECURITY: the DTO OMITS credentialsNotes/notes/adminLoginUrl/stagingUrl.
 * Gated behind the `monitoring` feature flag.
 */
final class PortalSystemsController
{
    /** Selectable "Zeitraum" windows (days); anything else falls back to the default. */
    private const ALLOWED_WINDOWS = [7, 30, 90];
    private const DEFAULT_WINDOW_DAYS = 30;

    private const ENV_LABELS = [
        'production' => 'Produktion',
        'staging' => 'Staging',
        'development' => 'Entwicklung',
    ];

    private const INCIDENT_LABELS = [
        'outage' => 'Störung',
        'degraded' => 'Langsam',
        'maintenance' => 'Wartung',
    ];

    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly CustomerSystemRepository $systems,
        private readonly SystemUptimeDayRepository $uptimeDays,
        private readonly SystemIncidentRepository $incidents,
    ) {}

    #[Route(
        path: '/v1/portal/systems',
        name: 'api_portal_systems_list',
        methods: ['GET'],
    )]
    public function list(Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('monitoring');

        $windowDays = $this->resolveWindow($request->query->getInt('days', self::DEFAULT_WINDOW_DAYS));

        $systems = $this->systems->findVisiblePortalSystems($this->portal->customer());
        $since = (new \DateTimeImmutable('today'))->modify('-' . ($windowDays - 1) . ' days');

        // Uptime rows grouped per system id.
        $uptimeBySystem = [];
        foreach ($this->uptimeDays->findSince($systems, $since) as $row) {
            $uptimeBySystem[$row->getSystem()->getId()?->toRfc4122() ?? ''][] = $row;
        }

        // Recent incidents: one list for "Vorfälle & Wartung" + per-system open state.
        $recent = $this->incidents->findRecentForSystems($systems);
        $openBySystem = [];
        foreach ($recent as $incident) {
            if ($incident->isOpen()) {
                $openBySystem[$incident->getSystem()->getId()?->toRfc4122() ?? ''][] = $incident->getKind();
            }
        }

        return new JsonResponse([
            'systems' => array_map(
                fn (CustomerSystem $s): array => $this->systemDto(
                    $s,
                    $uptimeBySystem[$s->getId()?->toRfc4122() ?? ''] ?? [],
                    $openBySystem[$s->getId()?->toRfc4122() ?? ''] ?? [],
                ),
                $systems,
            ),
            'incidents' => array_map($this->incidentDto(...), $recent),
            'windowDays' => $windowDays,
            'availableWindows' => self::ALLOWED_WINDOWS,
        ]);
    }

    /** Clamp an untrusted `days` param to a supported window. */
    private function resolveWindow(int $days): int
    {
        return \in_array($days, self::ALLOWED_WINDOWS, true) ? $days : self::DEFAULT_WINDOW_DAYS;
    }

    /**
     * @param list<\App\Entity\SystemUptimeDay> $uptime
     * @param list<IncidentKind> $openKinds
     * @return array<string, mixed>
     */
    private function systemDto(CustomerSystem $system, array $uptime, array $openKinds): array
    {
        $env = $system->getEnvironment()->value;
        [$status, $statusLabel] = $this->status($system, $openKinds);

        $uptimePct = null;
        $avgResponseMs = null;
        if ($uptime !== []) {
            $uptimePct = round(array_sum(array_map(static fn ($u) => $u->getUptimePct(), $uptime)) / \count($uptime), 2);
            $latencies = array_values(array_filter(array_map(static fn ($u) => $u->getAvgResponseMs(), $uptime), static fn ($v) => $v !== null));
            $avgResponseMs = $latencies === [] ? null : (int) round(array_sum($latencies) / \count($latencies));
        }

        return [
            'id' => $system->getId()?->toRfc4122(),
            'name' => $system->getName(),
            'type' => $system->getType()->value,
            'systemVersion' => $system->getSystemVersion(),
            'environment' => $env,
            'environmentLabel' => self::ENV_LABELS[$env] ?? $env,
            'url' => $system->getUrl(),
            'hostingProvider' => $system->getHostingProvider(),
            'isActive' => $system->isActive(),
            'status' => $status,
            'statusLabel' => $statusLabel,
            'uptimePct' => $uptimePct,
            'avgResponseMs' => $avgResponseMs,
            // Oldest→newest daily uptime for the sparkline.
            'uptimeDays' => array_map(static fn ($u) => [
                'day' => $u->getDay()->format('Y-m-d'),
                'uptimePct' => round($u->getUptimePct(), 1),
            ], $uptime),
        ];
    }

    /**
     * @param list<IncidentKind> $openKinds
     * @return array{0: string, 1: string}
     */
    private function status(CustomerSystem $system, array $openKinds): array
    {
        if (!$system->isActive()) {
            return ['inactive', 'Inaktiv'];
        }
        // Worst open incident wins.
        if (\in_array(IncidentKind::Outage, $openKinds, true)) {
            return ['down', 'Störung'];
        }
        if (\in_array(IncidentKind::Degraded, $openKinds, true)) {
            return ['degraded', 'Langsam'];
        }
        if (\in_array(IncidentKind::Maintenance, $openKinds, true)) {
            return ['maintenance', 'Wartung'];
        }
        return ['operational', 'Online'];
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentDto(SystemIncident $incident): array
    {
        $kind = $incident->getKind()->value;

        return [
            'id' => $incident->getId()?->toRfc4122(),
            'systemName' => $incident->getSystem()->getName(),
            'kind' => $kind,
            'kindLabel' => self::INCIDENT_LABELS[$kind] ?? $kind,
            'title' => $incident->getTitle(),
            'startedAt' => $incident->getStartedAt()->format(\DateTimeInterface::ATOM),
            'resolvedAt' => $incident->getResolvedAt()?->format(\DateTimeInterface::ATOM),
            'open' => $incident->isOpen(),
        ];
    }
}
