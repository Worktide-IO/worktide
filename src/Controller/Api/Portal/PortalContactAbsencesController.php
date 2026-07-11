<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\ContactAbsence;
use App\Repository\ContactAbsenceRepository;
use App\Service\Portal\PortalAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Portal self-service for a contact's own away-dates. The client sets when
 * they're unavailable so the agency sees it; staff view them via the
 * ContactAbsence API resource. Gated by the `absence` portal feature.
 *
 * Every operation targets the authenticated contact's own rows — create stamps
 * the current contact, delete re-checks ownership (fail-closed 404), so one
 * customer's contact can never touch another's.
 */
final class PortalContactAbsencesController
{
    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly ContactAbsenceRepository $absences,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(path: '/v1/portal/absences', name: 'api_portal_absences_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('absence');

        return new JsonResponse(['absences' => array_map(
            $this->dto(...),
            $this->absences->findForContact($this->portal->contact()),
        )]);
    }

    #[Route(path: '/v1/portal/absences', name: 'api_portal_absences_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('absence');

        $body = json_decode($request->getContent() ?: '{}', true);
        $body = \is_array($body) ? $body : [];
        $start = $this->parseDate($body['startsOn'] ?? null);
        $end = $this->parseDate($body['endsOn'] ?? null);
        if ($start === null || $end === null) {
            throw new BadRequestHttpException('startsOn and endsOn (ISO dates) are required.');
        }
        if ($end < $start) {
            throw new BadRequestHttpException('endsOn must not be before startsOn.');
        }
        $note = \is_string($body['note'] ?? null) && trim($body['note']) !== '' ? mb_substr(trim($body['note']), 0, 500) : null;

        $absence = (new ContactAbsence())
            ->setContact($this->portal->contact())
            ->setStartsOn($start)
            ->setEndsOn($end)
            ->setNote($note);
        $this->em->persist($absence);
        $this->em->flush();

        return new JsonResponse($this->dto($absence), 201);
    }

    #[Route(path: '/v1/portal/absences/{id}', name: 'api_portal_absences_delete', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $this->portal->assertFeatureEnabled('absence');

        try {
            $absence = $this->em->find(ContactAbsence::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
        // Fail-closed: only the owning contact may delete their own row.
        if ($absence === null || $absence->getContact() !== $this->portal->contact()) {
            throw new NotFoundHttpException();
        }
        $this->em->remove($absence);
        $this->em->flush();

        return new JsonResponse(['deleted' => true]);
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }
        try {
            // Store at noon so a tz shift never moves the calendar day.
            return new \DateTimeImmutable(substr($value, 0, 10) . ' 12:00:00');
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function dto(ContactAbsence $a): array
    {
        return [
            'id' => $a->getId()?->toRfc4122(),
            'startsOn' => $a->getStartsOn()?->format('Y-m-d'),
            'endsOn' => $a->getEndsOn()?->format('Y-m-d'),
            'note' => $a->getNote(),
        ];
    }
}
