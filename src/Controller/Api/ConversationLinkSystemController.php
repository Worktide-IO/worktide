<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\CustomerSystem;
use App\Security\Voter\WorktidePermission;
use App\Service\Inbound\ConversationSystemLinker;
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
 * Assign a Zabbix monitoring host to a customer from the inbox detail view:
 *
 *   POST /v1/conversations/{id}/link-system
 *     { "mode": "existing", "system": "<iri|uuid>" }   // adopt an existing CustomerSystem
 *     { "mode": "customer", "customer": "<iri|uuid>" }  // create the host mapping under a customer
 *     { "mode": "create", "newCustomerName": "..." }    // create a customer on the fly + mapping
 *
 * Gated on EDIT of the conversation. Only meaningful for Zabbix host threads —
 * the persistent relation lives on {@see CustomerSystem} (externalSource=zabbix,
 * externalId=<hostid>) so future alerts auto-link.
 */
final class ConversationLinkSystemController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly ConversationSystemLinker $linker,
    ) {}

    #[Route(
        path: '/v1/conversations/{id}/link-system',
        name: 'api_conversation_link_system',
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

        try {
            $system = match ($mode) {
                'existing' => $this->linker->assignToExistingSystem(
                    $conversation,
                    $this->resolveSystem($conversation, $data['system'] ?? null),
                ),
                'customer' => $this->linker->assignToCustomer(
                    $conversation,
                    $this->resolveCustomer($conversation, $data['customer'] ?? null),
                ),
                'create' => $this->linker->assignToNewCustomer(
                    $conversation,
                    \is_string($data['newCustomerName'] ?? null) ? trim($data['newCustomerName']) : '',
                ),
                default => throw new BadRequestHttpException('mode must be "existing", "customer" or "create".'),
            };
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return new JsonResponse([
            'systemId' => $system->getId()?->toRfc4122(),
            'customerId' => $system->getCustomer()->getId()?->toRfc4122(),
        ], Response::HTTP_CREATED);
    }

    private function resolveSystem(Conversation $conversation, mixed $ref): CustomerSystem
    {
        $system = $this->em->find(CustomerSystem::class, $this->uuid($ref, 'system'));
        if ($system === null || $system->getWorkspace() !== $conversation->getWorkspace()) {
            throw new BadRequestHttpException('Unknown system.');
        }

        return $system;
    }

    private function resolveCustomer(Conversation $conversation, mixed $ref): Customer
    {
        $customer = $this->em->find(Customer::class, $this->uuid($ref, 'customer'));
        if ($customer === null || $customer->getWorkspace() !== $conversation->getWorkspace()) {
            throw new BadRequestHttpException('Unknown customer.');
        }

        return $customer;
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
