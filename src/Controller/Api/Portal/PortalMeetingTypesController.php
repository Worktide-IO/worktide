<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\MeetingType;
use App\Repository\MeetingTypeRepository;
use App\Service\Portal\PortalAccessResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bookable meeting types for a logged-in portal customer (roadmap §7 in-portal
 * appointment booking). Lists the enabled meeting types in the customer's
 * workspace so the portal can offer a "Termin buchen" screen without the
 * customer hunting for a public /book/{slug} link.
 *
 * Read-only + curated (same public-safe shape as PublicBookingController::typeDto,
 * no host email / internal config). The actual slot listing + booking still go
 * through the public `/v1/book/{slug}` endpoints (slug is the credential); this
 * only exposes WHICH types exist. Gated by the `booking` portal feature.
 */
final class PortalMeetingTypesController
{
    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly MeetingTypeRepository $meetingTypes,
    ) {}

    #[Route(path: '/v1/portal/meeting-types', name: 'api_portal_meeting_types', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('booking');

        $types = array_map(
            static fn (MeetingType $t): array => [
                'slug' => $t->getSlug(),
                'title' => $t->getTitle(),
                'description' => $t->getDescription(),
                'durationMinutes' => $t->getDurationMinutes(),
                'locationType' => $t->getLocationType(),
                'hostName' => $t->getHost()?->getFullName(),
                // Per-locale title/description overrides (see localize() in the portal).
                'translations' => $t->getTranslations(),
            ],
            $this->meetingTypes->findAllEnabledForWorkspace($this->portal->workspace()),
        );

        return new JsonResponse(['meetingTypes' => $types]);
    }
}
