<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\Enum\ProposalStatus;
use App\Entity\Enum\TaskCreatedVia;
use App\Entity\Enum\TaskPriority;
use App\Entity\Project;
use App\Entity\ProjectProposal;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Repository\ProjectProposalRepository;
use App\Repository\TaskStatusRepository;
use App\Service\Portal\PortalAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Customer-portal "Ideen-Pitch" — project proposals the customer reviews
 * (wireframe screen 7). Read the proposals for their external projects, then
 * accept / reject / send feedback.
 *
 * Accept materializes a {@see Task} in the project (optionally taking a chosen
 * A/B variant's estimate) and marks the proposal Accepted. NOTE: auto-creating
 * an offer is deferred — {@see \App\Entity\CustomerAgreement} is type-keyed
 * (one head per customer+type), so it doesn't map to per-proposal offers;
 * `convertedAgreement` is left for a later offer model. Gated by `proposals`.
 */
final class PortalProposalsController
{
    private const STATUS_LABELS = [
        'new' => 'Neu',
        'in_review' => 'In Prüfung',
        'accepted' => 'Angenommen',
        'rejected' => 'Abgelehnt',
    ];

    private const ORIGIN_LABELS = [
        'ai' => 'KI-Vorschlag',
        'agency' => 'Von der Agentur',
    ];

    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly ProjectProposalRepository $proposals,
        private readonly TaskStatusRepository $taskStatuses,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {}

    #[Route(
        path: '/v1/portal/proposals',
        name: 'api_portal_proposals_list',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('proposals');

        return new JsonResponse([
            'proposals' => array_map(
                $this->proposalDto(...),
                $this->proposals->findForPortalProjects($this->portal->allowedProjects()),
            ),
        ]);
    }

    #[Route(
        path: '/v1/portal/proposals/{id}/accept',
        name: 'api_portal_proposals_accept',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function accept(string $id, Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('proposals');
        $proposal = $this->findProposalOr404($id);

        if ($proposal->getStatus() === ProposalStatus::Accepted) {
            throw new ConflictHttpException('Proposal already accepted.');
        }

        // Optionally adopt a chosen A/B variant's estimate.
        $body = $this->body($request);
        $variantIndex = $body['variantIndex'] ?? null;
        if (\is_int($variantIndex)) {
            $variant = $proposal->getVariants()[$variantIndex] ?? null;
            if ($variant === null) {
                throw new BadRequestHttpException('Unknown variant.');
            }
            if (isset($variant['effortHours'])) {
                $proposal->setEffortHours((int) $variant['effortHours']);
            }
            if (isset($variant['costCents'])) {
                $proposal->setCostCents((int) $variant['costCents']);
            }
        }

        $task = $this->createTaskFromProposal($proposal);
        $proposal->setStatus(ProposalStatus::Accepted)->setConvertedTask($task);
        $this->em->flush();

        return new JsonResponse($this->proposalDto($proposal));
    }

    #[Route(
        path: '/v1/portal/proposals/{id}/reject',
        name: 'api_portal_proposals_reject',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function reject(string $id): JsonResponse
    {
        $this->portal->assertFeatureEnabled('proposals');
        $proposal = $this->findProposalOr404($id);
        $proposal->setStatus(ProposalStatus::Rejected);
        $this->em->flush();

        return new JsonResponse($this->proposalDto($proposal));
    }

    #[Route(
        path: '/v1/portal/proposals/{id}/feedback',
        name: 'api_portal_proposals_feedback',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function feedback(string $id, Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('proposals');
        $proposal = $this->findProposalOr404($id);

        $message = \is_string($this->body($request)['message'] ?? null) ? trim($this->body($request)['message']) : '';
        if ($message === '') {
            throw new BadRequestHttpException('message required.');
        }

        $proposal->setCustomerFeedback($message);
        // A question moves an untouched proposal into review; a decided one stays.
        if ($proposal->getStatus() === ProposalStatus::New) {
            $proposal->setStatus(ProposalStatus::InReview);
        }
        $this->em->flush();

        return new JsonResponse($this->proposalDto($proposal));
    }

    private function createTaskFromProposal(ProjectProposal $proposal): Task
    {
        $project = $proposal->getProject();
        $description = trim(
            ($proposal->getRationale() ?? '') . "\n\n" . ($proposal->getExpectedBenefit() ?? ''),
        );

        $task = (new Task())
            ->setWorkspace($project->getWorkspace())
            ->setProject($project)
            ->setTitle($proposal->getTitle())
            ->setDescription($description !== '' ? $description : null)
            ->setStatus($this->defaultStatus($project))
            ->setPriority(TaskPriority::Normal)
            ->setCreatedVia(TaskCreatedVia::Portal)
            ->setCreatedBy($this->portalUser())
            ->setIdentifier($project->getKey() . '-' . dechex(random_int(0x1000, 0xFFFF)));

        $this->em->persist($task);

        return $task;
    }

    private function defaultStatus(Project $project): TaskStatus
    {
        $workspace = $project->getWorkspace();
        $default = $this->taskStatuses->findOneBy(['workspace' => $workspace, 'isDefault' => true]);
        if ($default !== null) {
            return $default;
        }
        $first = $this->taskStatuses->findBy(['workspace' => $workspace], ['position' => 'ASC'], 1)[0] ?? null;
        if ($first === null) {
            throw new ConflictHttpException('Workspace has no task statuses.');
        }
        return $first;
    }

    private function findProposalOr404(string $id): ProjectProposal
    {
        $proposal = $this->proposals->find(Uuid::fromString($id));
        if (!$proposal instanceof ProjectProposal || $proposal->getDeletedAt() !== null || !$this->isAllowedProject($proposal->getProject())) {
            throw new NotFoundHttpException('Proposal not found.');
        }
        return $proposal;
    }

    private function isAllowedProject(Project $project): bool
    {
        foreach ($this->portal->allowedProjects() as $allowed) {
            if ($allowed->getId()?->equals($project->getId()) === true) {
                return true;
            }
        }
        return false;
    }

    private function portalUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }
        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalDto(ProjectProposal $p): array
    {
        $status = $p->getStatus()->value;
        $origin = $p->getOrigin()->value;

        return [
            'id' => $p->getId()?->toRfc4122(),
            'projectName' => $p->getProject()->getName(),
            'title' => $p->getTitle(),
            'rationale' => $p->getRationale(),
            'expectedBenefit' => $p->getExpectedBenefit(),
            'effortHours' => $p->getEffortHours(),
            'costCents' => $p->getCostCents(),
            'currency' => $p->getCurrency(),
            'timeframeText' => $p->getTimeframeText(),
            'status' => $status,
            'statusLabel' => self::STATUS_LABELS[$status] ?? $status,
            'origin' => $origin,
            'originLabel' => self::ORIGIN_LABELS[$origin] ?? $origin,
            'variants' => $p->getVariants(),
            'customerFeedback' => $p->getCustomerFeedback(),
            'ticketIdentifier' => $p->getConvertedTask()?->getIdentifier(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $raw = $request->getContent();
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Body must be valid JSON.');
        }
        return \is_array($decoded) ? $decoded : [];
    }
}
