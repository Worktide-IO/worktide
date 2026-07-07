<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Workspace;
use App\Message\PlanDistributionMessage;
use App\Security\Voter\WorktidePermission;
use App\Service\Ai\AgentActionPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Kicks off the agent's content-distribution planner:
 *
 *   POST /v1/agent/plan-distribution   { content, workspace }
 *
 * The LLM works out one tailored action per connected channel; each is queued as
 * a pending agent-action recommendation for human review. Fail-fast 409 unless
 * the LLM is configured and `llm` egress is approved.
 */
final class AgentDistributionController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly AgentActionPlanner $planner,
        private readonly EgressGuard $egress,
    ) {}

    #[Route(
        path: '/v1/agent/plan-distribution',
        name: 'api_agent_plan_distribution',
        methods: ['POST'],
    )]
    public function planDistribution(Request $request): JsonResponse
    {
        if (!$this->planner->isAvailable()) {
            throw new ConflictHttpException('AI is not configured (missing LLM credentials).');
        }
        if (!$this->egress->isAllowed(EgressModule::Llm)) {
            throw new ConflictHttpException('LLM egress is not approved (add "llm" to EGRESS_ALLOW).');
        }

        $data = json_decode($request->getContent() ?: '{}', true);
        $data = \is_array($data) ? $data : [];
        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') {
            throw new BadRequestHttpException('A "content" text is required.');
        }
        $workspace = $this->resolveWorkspace($data['workspace'] ?? null);
        if (!$this->security->isGranted(WorktidePermission::EDIT, $workspace)) {
            throw new AccessDeniedHttpException();
        }

        $this->bus->dispatch(new PlanDistributionMessage($workspace->getId() ?? throw new NotFoundHttpException(), $content));

        return new JsonResponse(['status' => 'queued'], Response::HTTP_ACCEPTED);
    }

    private function resolveWorkspace(mixed $ref): Workspace
    {
        if (!\is_string($ref) || $ref === '') {
            throw new BadRequestHttpException('A "workspace" is required.');
        }
        $uuid = str_contains($ref, '/') ? substr((string) strrchr($ref, '/'), 1) : $ref;
        try {
            $workspace = $this->em->find(Workspace::class, Uuid::fromString($uuid));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid "workspace" reference.');
        }
        if (!$workspace instanceof Workspace) {
            throw new NotFoundHttpException('Workspace not found.');
        }

        return $workspace;
    }
}
