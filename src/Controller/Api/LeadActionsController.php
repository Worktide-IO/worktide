<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\LeadActivityType;
use App\Entity\Enum\LeadStage;
use App\Entity\Lead;
use App\Entity\LeadActivity;
use App\Entity\User;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Acquisition actions on a {@see Lead}: move it through the pipeline (logging a
 * {@see LeadActivity}) and convert a won lead into a real {@see Customer}.
 */
final class LeadActionsController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {}

    #[Route(
        '/v1/leads/{id}/stage',
        name: 'api_lead_set_stage',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function setStage(string $id, Request $request): JsonResponse
    {
        [$lead, $user] = $this->loadEditable($id);
        $raw = (string) ($this->json($request)['stage'] ?? '');
        $new = LeadStage::tryFrom($raw) ?? throw new BadRequestHttpException('Unknown stage.');

        $old = $lead->getStage();
        if ($new !== $old) {
            $lead->setStage($new);
            $this->em->persist(
                (new LeadActivity())
                    ->setLead($lead)
                    ->setWorkspace($lead->getWorkspace())
                    ->setType(LeadActivityType::StageChange)
                    ->setActor($user)
                    ->setPayload(['from' => $old->value, 'to' => $new->value]),
            );
        }
        $this->em->flush();

        return new JsonResponse(['id' => $lead->getId()?->toRfc4122(), 'stage' => $new->value]);
    }

    #[Route(
        '/v1/leads/{id}/convert',
        name: 'api_lead_convert',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function convert(string $id): JsonResponse
    {
        [$lead, $user] = $this->loadEditable($id);
        if ($lead->getConvertedCustomer() !== null) {
            throw new ConflictHttpException('Lead already converted.');
        }

        $customer = (new Customer())
            ->setWorkspace($lead->getWorkspace())
            ->setName($lead->getName())
            ->setIsCompany($lead->isCompany())
            ->setStatus(CustomerStatus::Active);
        if ($lead->getEmail() !== null) {
            $customer->setEmail($lead->getEmail());
        }
        if ($lead->getPhone() !== null) {
            $customer->setPhone($lead->getPhone());
        }
        if ($lead->getWebsite() !== null) {
            $customer->setWebsite($lead->getWebsite());
        }
        $this->em->persist($customer);

        $old = $lead->getStage();
        $lead->setConvertedCustomer($customer)->setStage(LeadStage::Won);
        $this->em->persist(
            (new LeadActivity())
                ->setLead($lead)
                ->setWorkspace($lead->getWorkspace())
                ->setType(LeadActivityType::StageChange)
                ->setActor($user)
                ->setOutcome('converted')
                ->setPayload(['from' => $old->value, 'to' => LeadStage::Won->value]),
        );
        $this->em->flush();

        return new JsonResponse([
            'leadId' => $lead->getId()?->toRfc4122(),
            'customerId' => $customer->getId()?->toRfc4122(),
        ], 201);
    }

    /**
     * @return array{0: Lead, 1: User}
     */
    private function loadEditable(string $id): array
    {
        $lead = $this->em->find(Lead::class, Uuid::fromString($id));
        if (!$lead instanceof Lead) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $lead->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }
        /** @var User $user */
        $user = $this->security->getUser();

        return [$lead, $user];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Request $request): array
    {
        $raw = $request->getContent();
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Body must be JSON.');
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
