<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Contact;
use App\Entity\Conversation;
use App\Entity\Customer;
use App\Security\Voter\WorktidePermission;
use App\Service\Inbound\ConversationContactLinker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Turn a conversation's sender into address-book data from the inbox detail view:
 *
 *   POST /v1/conversations/{id}/link-contact
 *     { "mode": "existing", "contact": "<iri|uuid>" }
 *     { "mode": "create", "firstName": "...", "lastName": "...",
 *       "customer": "<iri|uuid>"        // link to an existing customer, OR
 *       "newCustomerName": "..." }      // create a customer on the fly
 *
 * Gated on EDIT of the conversation. A Contact always needs a Customer, so
 * "create" requires either an existing customer or a new-customer name.
 */
final class ConversationLinkContactController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly ConversationContactLinker $linker,
    ) {}

    #[Route(
        path: '/v1/conversations/{id}/link-contact',
        name: 'api_conversation_link_contact',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $conversation = $this->em->find(Conversation::class, Uuid::fromString($id));
        if ($conversation === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $conversation)) {
            throw new AccessDeniedHttpException();
        }

        $data = json_decode($request->getContent(), true);
        $data = \is_array($data) ? $data : [];
        $mode = \is_string($data['mode'] ?? null) ? $data['mode'] : '';

        $result = match ($mode) {
            'existing' => $this->linker->linkToExisting(
                $conversation,
                $this->resolveContact($conversation, $data['contact'] ?? null),
            ),
            'create' => $this->linker->createContact(
                $conversation,
                $this->resolveOrCreateCustomer($conversation, $data),
                \is_string($data['firstName'] ?? null) ? trim($data['firstName']) : '',
                \is_string($data['lastName'] ?? null) ? trim($data['lastName']) : '',
            ),
            default => throw new BadRequestHttpException('mode must be "existing" or "create".'),
        };

        return new JsonResponse([
            'contactId' => $result['contact']->getId()?->toRfc4122(),
            'contactEmailId' => $result['email']?->getId()?->toRfc4122(),
            'customerId' => $result['contact']->getCustomer()->getId()?->toRfc4122(),
        ], Response::HTTP_CREATED);
    }

    private function resolveContact(Conversation $conversation, mixed $ref): Contact
    {
        $contact = $this->em->find(Contact::class, $this->uuid($ref, 'contact'));
        if ($contact === null || $contact->getWorkspace() !== $conversation->getWorkspace()) {
            throw new BadRequestHttpException('Unknown contact.');
        }
        return $contact;
    }

    /** @param array<string, mixed> $data */
    private function resolveOrCreateCustomer(Conversation $conversation, array $data): Customer
    {
        if (\is_string($data['customer'] ?? null) && $data['customer'] !== '') {
            $customer = $this->em->find(Customer::class, $this->uuid($data['customer'], 'customer'));
            if ($customer === null || $customer->getWorkspace() !== $conversation->getWorkspace()) {
                throw new BadRequestHttpException('Unknown customer.');
            }
            return $customer;
        }
        if (\is_string($data['newCustomerName'] ?? null) && trim($data['newCustomerName']) !== '') {
            return $this->linker->createCustomer($conversation, trim($data['newCustomerName']));
        }
        throw new BadRequestHttpException('Provide "customer" or "newCustomerName".');
    }

    private function uuid(mixed $ref, string $field): Uuid
    {
        if (!\is_string($ref) || $ref === '') {
            throw new BadRequestHttpException(sprintf('Missing "%s".', $field));
        }
        $candidate = str_contains($ref, '/') ? substr((string) strrchr($ref, '/'), 1) : $ref;
        if (!Uuid::isValid($candidate)) {
            throw new BadRequestHttpException(sprintf('Invalid "%s".', $field));
        }
        return Uuid::fromString($candidate);
    }
}
