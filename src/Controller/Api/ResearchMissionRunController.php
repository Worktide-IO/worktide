<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\ResearchMission;
use App\Message\RunResearchMissionMessage;
use App\Security\Voter\WorktidePermission;
use App\Service\Ai\ResearchAssistant;
use App\Service\ExternalSearch\ExternalSearchRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Kicks off one discovery pass for a research mission:
 *
 *   POST /v1/research-missions/{id}/run
 *
 * Fails fast (409) when the LLM or an external-search adapter is unconfigured,
 * or when the `llm` / `external_search` egress modules aren't approved.
 * Otherwise queues a {@see RunResearchMissionMessage} on `ai_agents` and
 * returns 202; the worker fans out to the search adapters and persists leads.
 */
final class ResearchMissionRunController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly ResearchAssistant $assistant,
        private readonly ExternalSearchRegistry $search,
        private readonly EgressGuard $egress,
    ) {}

    #[Route(
        path: '/v1/research-missions/{id}/run',
        name: 'api_research_mission_run',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function run(string $id): JsonResponse
    {
        $mission = $this->em->find(ResearchMission::class, Uuid::fromString($id));
        if (!$mission instanceof ResearchMission) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $mission->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }
        if (!$this->assistant->isAvailable()) {
            throw new ConflictHttpException('AI is not configured (missing LLM credentials).');
        }
        if (!$this->egress->isAllowed(EgressModule::Llm)) {
            throw new ConflictHttpException('LLM egress is not approved (add "llm" to EGRESS_ALLOW).');
        }
        // At least one search source must be usable. The internal (own-database)
        // provider is always available, so this normally passes; external web
        // adapters self-gate on the external_search egress module and are skipped
        // by the registry when it isn't approved — an internal-only run is fine.
        if (!$this->search->isAvailable()) {
            throw new ConflictHttpException('No search provider available.');
        }

        $this->bus->dispatch(new RunResearchMissionMessage($mission->getId() ?? throw new NotFoundHttpException()));

        return new JsonResponse(['status' => 'queued'], Response::HTTP_ACCEPTED);
    }
}
