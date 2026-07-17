<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Absence;
use App\Entity\User;
use App\Message\PlanScheduleMessage;
use App\Service\Absence\AbsenceConflictResolver;
use App\Service\Ai\AbsenceIntakeAssistant;
use App\Service\Llm\LlmException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Conversational absence intake (Phase 3): a staff member tells the AI about a
 * sickness / spontaneous absence in free text; the LLM parses it (asking one
 * clarifying question when ambiguous), we record the Absence, re-plan their
 * schedule, and surface the customer-facing tickets that were scheduled in the
 * absent window so the staff can offer to notify those customers.
 *
 *   POST /v1/me/absence-intake   { text }                 → clarify | created(+affected)
 *   POST /v1/me/absence-notify   { taskIds: [...] }        → egress-gated draft per customer
 */
final class AbsenceIntakeController
{
    use ResolvesWorkspaceMembership;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly AbsenceIntakeAssistant $assistant,
        private readonly EgressGuard $egress,
        private readonly AbsenceConflictResolver $conflicts,
    ) {}

    #[Route(path: '/v1/me/absence-intake', name: 'api_me_absence_intake', methods: ['POST'])]
    public function intake(Request $request): JsonResponse
    {
        [$user, $workspace] = $this->context($request);

        $text = $this->stringField($request, 'text');
        if ($text === '') {
            throw new BadRequestHttpException('text required.');
        }
        if (!$this->assistant->isAvailable() || !$this->egress->isAllowed(EgressModule::Llm)) {
            throw new HttpException(Response::HTTP_SERVICE_UNAVAILABLE, 'AI is not configured / LLM egress not approved.');
        }

        try {
            $parsed = $this->assistant->parse($text, $workspace);
        } catch (LlmException) {
            throw new HttpException(Response::HTTP_BAD_GATEWAY, 'The AI provider failed to parse the absence.');
        }

        if ($parsed['clarify'] !== null || $parsed['startsOn'] === null || $parsed['endsOn'] === null) {
            return new JsonResponse([
                'status' => 'clarify',
                'question' => $parsed['clarify'] ?? 'Bitte gib Start- und Enddatum der Abwesenheit an.',
                'parsed' => $parsed,
            ]);
        }

        $start = new \DateTimeImmutable($parsed['startsOn']);
        $end = new \DateTimeImmutable($parsed['endsOn']);

        // Compute affected appointments + customer-facing tickets BEFORE re-planning moves them.
        $affected = $this->conflicts->findConflicts($user, $workspace, $start, $end);

        $absence = (new Absence())
            ->setWorkspace($workspace)
            ->setUser($user)
            ->setStartsOn($start->setTime(0, 0))
            ->setEndsOn($end->setTime(0, 0))
            ->setType($parsed['type'])
            ->setAvailabilityPercent($parsed['availabilityPercent']);
        $this->em->persist($absence);
        $this->em->flush();

        // Free time changed → re-plan the staff's schedule around the absence.
        $uid = $user->getId();
        $wid = $workspace->getId();
        if ($uid !== null && $wid !== null) {
            $this->bus->dispatch(new PlanScheduleMessage($uid, $wid));
        }

        return new JsonResponse([
            'status' => 'created',
            'absenceId' => $absence->getId()?->toRfc4122(),
            'startsOn' => $parsed['startsOn'],
            'endsOn' => $parsed['endsOn'],
            'type' => $parsed['type'],
            'availabilityPercent' => $parsed['availabilityPercent'],
            'affected' => $affected['customers'],
            'affectedBookings' => $affected['bookings'],
        ], Response::HTTP_CREATED);
    }

    #[Route(path: '/v1/me/absence-notify', name: 'api_me_absence_notify', methods: ['POST'])]
    public function notify(Request $request): JsonResponse
    {
        [$user, $workspace] = $this->context($request);

        $body = json_decode($request->getContent(), true);
        $taskIds = \is_array($body) && \is_array($body['taskIds'] ?? null) ? array_values($body['taskIds']) : [];
        if ($taskIds === []) {
            throw new BadRequestHttpException('taskIds required.');
        }

        $result = $this->conflicts->resolve($user, $workspace, [], $taskIds);

        return new JsonResponse(['drafted' => $result['drafted']]);
    }

    /** @return array{0: User, 1: \App\Entity\Workspace} */
    private function context(Request $request): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }
        $workspace = $this->resolveWorkspace($request, $user);
        if ($workspace === null) {
            throw new AccessDeniedHttpException('No workspace context.');
        }

        return [$user, $workspace];
    }

    private function stringField(Request $request, string $key): string
    {
        $body = json_decode($request->getContent(), true);
        $value = \is_array($body) ? ($body[$key] ?? null) : null;

        return \is_string($value) ? trim($value) : '';
    }
}
