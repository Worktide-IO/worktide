<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Enum\MissionMessageRole;
use App\Entity\Enum\ResearchMissionStatus;
use App\Entity\Enum\ResearchObjective;
use App\Entity\ResearchMission;
use App\Entity\ResearchMissionMessage;
use App\Entity\Workspace;
use App\Security\Voter\WorktidePermission;
use App\Service\Ai\ResearchAssistant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * The clarification dialog that turns a free-text instruction into a runnable
 * research brief:
 *
 *   POST /v1/research-missions/create      → create mission + first agent questions
 *   POST /v1/research-missions/{id}/answer → answer, refine brief, maybe go "ready"
 *
 * Each step runs {@see ResearchAssistant::clarify} synchronously (one LLM call —
 * the user is waiting for questions), so it fails fast with 409 unless the LLM is
 * configured and `llm` egress is approved. When the brief is specific enough the
 * mission flips to `ready` and can be dispatched via the run endpoint.
 */
final class ResearchMissionDialogController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly ResearchAssistant $assistant,
        private readonly EgressGuard $egress,
    ) {}

    #[Route(
        path: '/v1/research-missions/create',
        name: 'api_research_mission_create',
        host: 'api.worktide.ddev.site',
        methods: ['POST'],
    )]
    public function create(Request $request): JsonResponse
    {
        $this->assertLlmAvailable();

        $data = $this->body($request);
        $prompt = trim((string) ($data['prompt'] ?? ''));
        if ($prompt === '') {
            throw new BadRequestHttpException('A "prompt" is required.');
        }
        $workspace = $this->resolveWorkspace($data['workspace'] ?? null);
        if (!$this->security->isGranted(WorktidePermission::EDIT, $workspace)) {
            throw new AccessDeniedHttpException();
        }

        $objective = \is_string($data['objective'] ?? null)
            ? (ResearchObjective::tryFrom($data['objective']) ?? ResearchObjective::General)
            : ResearchObjective::General;

        $mission = (new ResearchMission())
            ->setWorkspace($workspace)
            ->setPrompt($prompt)
            ->setObjective($objective)
            ->setCreatedVia('prompt')
            ->setTargetCount($this->intOrNull($data['targetCount'] ?? null))
            ->setStatus(ResearchMissionStatus::Clarifying);
        $this->em->persist($mission);

        $this->runClarify($mission, []);
        $this->em->flush();

        return $this->respond($mission, Response::HTTP_CREATED);
    }

    #[Route(
        path: '/v1/research-missions/{id}/answer',
        name: 'api_research_mission_answer',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function answer(string $id, Request $request): JsonResponse
    {
        $this->assertLlmAvailable();

        $mission = $this->em->find(ResearchMission::class, Uuid::fromString($id));
        if (!$mission instanceof ResearchMission) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $mission->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }
        if ($mission->getStatus() !== ResearchMissionStatus::Clarifying) {
            throw new ConflictHttpException(sprintf('Mission is %s, not clarifying.', $mission->getStatus()->value));
        }

        $answer = trim((string) ($this->body($request)['answer'] ?? ''));
        if ($answer === '') {
            throw new BadRequestHttpException('An "answer" is required.');
        }

        // Record the user's turn, then re-run clarify over the whole dialog.
        $this->em->persist($this->message($mission, MissionMessageRole::User, $answer));
        $dialog = $this->dialog($mission);
        $dialog[] = ['role' => 'user', 'content' => $answer];

        $this->runClarify($mission, $dialog);
        $this->em->flush();

        return $this->respond($mission);
    }

    /**
     * Runs one clarify turn, appends the agent's message, and accumulates the
     * brief. Flips the mission to `ready` once the assistant is satisfied.
     *
     * @param list<array{role: string, content: string}> $dialog
     */
    private function runClarify(ResearchMission $mission, array $dialog): void
    {
        $result = $this->assistant->clarify($mission, $dialog);

        // Brief accumulates across turns: newer, non-empty values win.
        $brief = array_merge($mission->getBrief() ?? [], $result['brief']);
        $mission->setBrief($brief === [] ? null : $brief);
        if ($result['objective'] instanceof ResearchObjective) {
            $mission->setObjective($result['objective']);
        }

        $this->em->persist(
            $this->message($mission, MissionMessageRole::Agent, $result['message'])
                ->setQuestion($result['questions'] === [] ? null : ['questions' => $result['questions']]),
        );

        $mission->setStatus($result['ready'] ? ResearchMissionStatus::Ready : ResearchMissionStatus::Clarifying);
    }

    private function message(ResearchMission $mission, MissionMessageRole $role, string $content): ResearchMissionMessage
    {
        return (new ResearchMissionMessage())
            ->setWorkspace($mission->getWorkspace())
            ->setMission($mission)
            ->setRole($role)
            ->setContent($content);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function dialog(ResearchMission $mission): array
    {
        $rows = $this->em->getRepository(ResearchMissionMessage::class)
            ->findBy(['mission' => $mission], ['createdAt' => 'ASC']);

        return array_map(
            static fn (ResearchMissionMessage $m): array => ['role' => $m->getRole()->value, 'content' => $m->getContent()],
            $rows,
        );
    }

    private function assertLlmAvailable(): void
    {
        if (!$this->assistant->isAvailable()) {
            throw new ConflictHttpException('AI is not configured (missing LLM credentials).');
        }
        if (!$this->egress->isAllowed(EgressModule::Llm)) {
            throw new ConflictHttpException('LLM egress is not approved (add "llm" to EGRESS_ALLOW).');
        }
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

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $data = json_decode($request->getContent() ?: '{}', true);

        return \is_array($data) ? $data : [];
    }

    private function intOrNull(mixed $v): ?int
    {
        return is_numeric($v) ? max(0, (int) $v) : null;
    }

    private function respond(ResearchMission $mission, int $status = Response::HTTP_OK): JsonResponse
    {
        $lastAgent = null;
        foreach (array_reverse($this->em->getRepository(ResearchMissionMessage::class)
            ->findBy(['mission' => $mission], ['createdAt' => 'ASC'])) as $m) {
            if ($m->getRole() === MissionMessageRole::Agent) {
                $lastAgent = $m;
                break;
            }
        }

        return new JsonResponse([
            'id' => $mission->getId()?->toRfc4122(),
            'status' => $mission->getStatus()->value,
            'objective' => $mission->getObjective()->value,
            'ready' => $mission->getStatus() === ResearchMissionStatus::Ready,
            'message' => $lastAgent?->getContent(),
            'questions' => $lastAgent?->getQuestion()['questions'] ?? [],
            'brief' => $mission->getBrief(),
        ], $status);
    }
}
