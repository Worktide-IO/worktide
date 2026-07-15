<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Absence;
use App\Entity\Customer;
use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Enum\OutboundMessageKind;
use App\Entity\Enum\OutboundMessageStatus;
use App\Entity\OutboundMessage;
use App\Entity\Task;
use App\Entity\User;
use App\Message\PlanScheduleMessage;
use App\Repository\ChannelRepository;
use App\Service\Ai\AbsenceIntakeAssistant;
use App\Service\Llm\LlmException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

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
        private readonly ChannelRepository $channels,
        private readonly EgressGuard $egress,
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

        // Compute affected customer-facing tickets BEFORE re-planning moves them.
        $affected = $this->affectedCustomers($user, $workspace, $start, $end);

        $absence = (new Absence())
            ->setWorkspace($workspace)
            ->setUser($user)
            ->setStartsOn($start->setTime(0, 0))
            ->setEndsOn($end->setTime(0, 0))
            ->setType($parsed['type']);
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
            'affected' => $affected,
        ], Response::HTTP_CREATED);
    }

    #[Route(path: '/v1/me/absence-notify', name: 'api_me_absence_notify', methods: ['POST'])]
    public function notify(Request $request): JsonResponse
    {
        [$user, $workspace] = $this->context($request);

        $body = json_decode($request->getContent(), true);
        $taskIds = \is_array($body) && \is_array($body['taskIds'] ?? null) ? $body['taskIds'] : [];
        if ($taskIds === []) {
            throw new BadRequestHttpException('taskIds required.');
        }

        $channel = $this->channels->findEnabledEmailOutbound($workspace)[0] ?? null;

        // Group the (validated) tasks by customer.
        /** @var array<string, array{customer: Customer, titles: list<string>}> $byCustomer */
        $byCustomer = [];
        foreach ($taskIds as $rawId) {
            if (!\is_string($rawId) || !Uuid::isValid($rawId)) {
                continue;
            }
            $task = $this->em->find(Task::class, Uuid::fromString($rawId));
            $customer = $task?->getProject()?->getCustomer();
            if ($task === null || $customer === null || !$task->getWorkspace()->getId()?->equals($workspace->getId())) {
                continue;
            }
            $cid = $customer->getId()?->toRfc4122() ?? '';
            $byCustomer[$cid] ??= ['customer' => $customer, 'titles' => []];
            $byCustomer[$cid]['titles'][] = $task->getTitle();
        }

        $drafted = [];
        foreach ($byCustomer as $entry) {
            $customer = $entry['customer'];
            $recipient = $this->resolveRecipientEmail($customer);
            $result = ['customerId' => $customer->getId()?->toRfc4122(), 'customerName' => $customer->getName(), 'recipient' => $recipient];
            if ($channel === null) {
                $drafted[] = $result + ['created' => false, 'reason' => 'no_email_channel'];
                continue;
            }
            if ($recipient === null) {
                $drafted[] = $result + ['created' => false, 'reason' => 'no_recipient'];
                continue;
            }

            $list = implode('', array_map(static fn (string $t): string => "\n· " . $t, $entry['titles']));
            $message = (new OutboundMessage())
                ->setWorkspace($workspace)
                ->setChannel($channel)
                ->setRecipientRaw($recipient)
                ->setSubject('Kurzfristige Verzögerung bei Ihren offenen Aufgaben')
                ->setBody(
                    "Guten Tag,\n\naufgrund einer kurzfristigen Abwesenheit verzögert sich voraussichtlich die "
                    . "Bearbeitung der folgenden Aufgaben:{$list}\n\nWir melden uns, sobald es weitergeht, und "
                    . "bitten um Ihr Verständnis.\n\nBeste Grüße",
                )
                ->setKind(OutboundMessageKind::Reply)
                ->setStatus(OutboundMessageStatus::Queued)
                ->setCreatedByUser($user);
            $this->em->persist($message);
            $drafted[] = $result + ['created' => true];
        }
        $this->em->flush();

        return new JsonResponse(['drafted' => $drafted]);
    }

    /**
     * Open, customer-facing tickets assigned to the user with a planned slot in
     * the [start, end] window, grouped by customer.
     *
     * @return list<array<string, mixed>>
     */
    private function affectedCustomers(User $user, \App\Entity\Workspace $workspace, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        /** @var list<Task> $tasks */
        $tasks = $this->em->createQueryBuilder()
            ->select('t', 'p', 'c')
            ->from(Task::class, 't')
            ->join('t.status', 's')
            ->join('t.project', 'p')
            ->join('p.customer', 'c')
            ->innerJoin('t.assignedPrincipals', 'ap', 'WITH', 'ap.principalType = :ptype AND ap.principalId = :uid')
            ->andWhere('t.workspace = :ws')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('s.isCompleted = false')
            ->andWhere('t.startOn >= :winStart AND t.startOn <= :winEnd')
            ->setParameter('ptype', AssigneePrincipalType::User)
            ->setParameter('uid', $user->getId(), UuidType::NAME)
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
            ->setParameter('winStart', $start->setTime(0, 0))
            ->setParameter('winEnd', $end->setTime(23, 59, 59))
            ->getQuery()->getResult();

        /** @var array<string, array<string, mixed>> $grouped */
        $grouped = [];
        foreach ($tasks as $task) {
            $customer = $task->getProject()?->getCustomer();
            if ($customer === null) {
                continue;
            }
            $cid = $customer->getId()?->toRfc4122() ?? '';
            $grouped[$cid] ??= [
                'customerId' => $cid,
                'customerName' => $customer->getName(),
                'recipient' => $this->resolveRecipientEmail($customer),
                'tasks' => [],
            ];
            $grouped[$cid]['tasks'][] = [
                'id' => $task->getId()?->toRfc4122(),
                'title' => $task->getTitle(),
                'startOn' => $task->getStartOn()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return array_values($grouped);
    }

    private function resolveRecipientEmail(Customer $customer): ?string
    {
        $email = trim((string) $customer->getEmail());
        if ($email !== '') {
            return $email;
        }
        foreach ($customer->getContacts() as $contact) {
            $contactEmail = trim((string) $contact->getEmail());
            if ($contactEmail !== '') {
                return $contactEmail;
            }
        }

        return null;
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
