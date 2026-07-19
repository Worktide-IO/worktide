<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Entity\Channel;
use App\Entity\Contact;
use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\Enum\ConversationStatus;
use App\Entity\Enum\InboundEventState;
use App\Entity\Enum\OutboundMessageKind;
use App\Entity\Enum\OutboundMessageStatus;
use App\Entity\InboundEvent;
use App\Entity\OutboundMessage;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Log a phone call as a Conversation (manual ticket for telephone contact).
 *
 *   POST /v1/conversations/phone
 *     { "direction": "inbound" | "outbound",
 *       "subject": "...", "counterparty": "Max Mustermann / +49…",
 *       "summary": "what was discussed",
 *       "occurredAt": "2026-07-19T14:30:00+02:00",   // optional, defaults to now
 *       "durationMinutes": 12,                         // optional
 *       "contact": "<iri|uuid>", "customer": "<iri|uuid>" }  // optional links
 *
 * A phone call has a direction, so it reuses the existing timeline sources
 * rather than a new entity: an inbound call becomes an {@see InboundEvent}
 * (timeline type `customer`), an outbound call an {@see OutboundMessage}
 * (`message`). Both hang off a per-workspace, sync-less `phone` Channel
 * (capabilities: [] → every pull/push cron skips it — it has no adapter).
 *
 * Workspace is resolved from X-Workspace-Id and always membership-checked
 * (Phase T); contact/customer links are rejected if they belong to another
 * workspace.
 */
final class PhoneConversationController
{
    use ResolvesWorkspaceMembership;

    private const PHONE_ADAPTER = 'phone';

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/conversations/phone',
        name: 'api_conversation_phone',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $workspace = $this->resolveWorkspace($request, $user);
        if ($workspace === null || !$this->isActiveMember($workspace, $user)) {
            throw new AccessDeniedHttpException('Kein Zugriff auf diesen Workspace.');
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            throw new BadRequestHttpException('Ungültiger Request-Body.');
        }

        $direction = ($data['direction'] ?? null) === 'outbound' ? 'outbound' : 'inbound';
        $counterparty = trim((string) ($data['counterparty'] ?? ''));
        $summary = trim((string) ($data['summary'] ?? ''));
        if ($summary === '') {
            throw new BadRequestHttpException('Eine Gesprächsnotiz (summary) ist erforderlich.');
        }
        $subject = trim((string) ($data['subject'] ?? ''));
        if ($subject === '') {
            $subject = $counterparty !== '' ? 'Telefonat mit ' . $counterparty : 'Telefonat';
        }

        $occurredAt = $this->parseDate($data['occurredAt'] ?? null) ?? new \DateTimeImmutable();
        $duration = isset($data['durationMinutes']) ? max(0, (int) $data['durationMinutes']) : null;
        $body = $duration !== null ? sprintf("⏱ %d Min\n\n%s", $duration, $summary) : $summary;

        $contact = $this->resolveContact($data['contact'] ?? null, $workspace);
        $customer = $this->resolveCustomer($data['customer'] ?? null, $workspace)
            ?? $contact?->getCustomer();

        $channel = $this->phoneChannel($workspace);

        $conversation = (new Conversation())
            ->setChannel($channel)
            ->setSubject(mb_substr($subject, 0, 250))
            ->setThreadKey('phone-' . Uuid::v7()->toRfc4122())
            ->setStatus(ConversationStatus::Open)
            ->setSenderRaw($counterparty !== '' ? mb_substr($counterparty, 0, 200) : null)
            ->setCustomer($customer)
            ->setAssignee($user)
            ->setLastEventAt($occurredAt);
        $conversation->setWorkspace($workspace);
        $this->em->persist($conversation);

        if ($direction === 'inbound') {
            $event = (new InboundEvent())
                ->setChannel($channel)
                ->setExternalId('phone-' . Uuid::v7()->toRfc4122())
                ->setSenderRaw($counterparty !== '' ? mb_substr($counterparty, 0, 200) : null)
                ->setSenderContact($contact)
                ->setSubject(mb_substr($subject, 0, 250))
                ->setBody($body)
                ->setSourceMetadata(['phone' => ['direction' => 'inbound', 'durationMinutes' => $duration]])
                ->setState(InboundEventState::Processed)
                ->setReceivedAt($occurredAt)
                ->setConversation($conversation);
            $event->setWorkspace($workspace);
            $this->em->persist($event);
        } else {
            $message = (new OutboundMessage())
                ->setChannel($channel)
                ->setRecipientRaw($counterparty !== '' ? mb_substr($counterparty, 0, 200) : '—')
                ->setRecipientContact($contact)
                ->setSubject(mb_substr($subject, 0, 250))
                ->setBody($body)
                ->setKind(OutboundMessageKind::Reply)
                ->setStatus(OutboundMessageStatus::Sent)
                ->setSentAt($occurredAt)
                ->setConversation($conversation)
                ->setCreatedByUser($user);
            $message->setWorkspace($workspace);
            $this->em->persist($message);
        }

        $this->em->flush();

        return new JsonResponse([
            'id' => $conversation->getId()?->toRfc4122(),
            'subject' => $conversation->getSubject(),
            'direction' => $direction,
            'customer' => $customer?->getId()?->toRfc4122(),
        ], JsonResponse::HTTP_CREATED);
    }

    /** Find or create the workspace's sync-less phone channel. */
    private function phoneChannel(Workspace $workspace): Channel
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([
            'workspace' => $workspace,
            'adapterCode' => self::PHONE_ADAPTER,
        ]);
        if ($channel !== null) {
            return $channel;
        }

        $channel = (new Channel())
            ->setName('Telefon')
            ->setAdapterCode(self::PHONE_ADAPTER)
            ->setCapabilities([]) // no adapter → every sync cron skips it
            ->setIsShared(true)
            ->setIsEnabled(true);
        $channel->setWorkspace($workspace);
        $this->em->persist($channel);

        return $channel;
    }

    private function isActiveMember(Workspace $workspace, User $user): bool
    {
        return $this->em->getRepository(WorkspaceMember::class)
            ->findOneBy(['workspace' => $workspace, 'user' => $user, 'isActive' => true]) !== null;
    }

    private function resolveContact(mixed $ref, Workspace $workspace): ?Contact
    {
        $id = $this->uuidFromRef($ref, 'contacts');
        if ($id === null) {
            return null;
        }
        $contact = $this->em->find(Contact::class, $id);
        if ($contact === null || $contact->getWorkspace() !== $workspace) {
            throw new BadRequestHttpException('Unbekannter oder fremder Kontakt.');
        }

        return $contact;
    }

    private function resolveCustomer(mixed $ref, Workspace $workspace): ?Customer
    {
        $id = $this->uuidFromRef($ref, 'customers');
        if ($id === null) {
            return null;
        }
        $customer = $this->em->find(Customer::class, $id);
        if ($customer === null || $customer->getWorkspace() !== $workspace) {
            throw new BadRequestHttpException('Unbekannter oder fremder Kunde.');
        }

        return $customer;
    }

    private function uuidFromRef(mixed $ref, string $resource): ?Uuid
    {
        if (!\is_string($ref) || $ref === '') {
            return null;
        }
        $raw = str_contains($ref, '/') ? (string) substr($ref, (int) strrpos($ref, '/') + 1) : $ref;
        try {
            return Uuid::fromString($raw);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException(sprintf('Ungültige %s-Referenz.', $resource));
        }
    }

    private function parseDate(mixed $v): ?\DateTimeImmutable
    {
        if (!\is_string($v) || $v === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($v);
        } catch (\Exception) {
            return null;
        }
    }
}
