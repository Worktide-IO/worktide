<?php

declare(strict_types=1);

namespace App\Controller\Api;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Tag;
use App\Entity\TaggableInterface;
use App\Entity\Workspace;
use App\Security\Voter\WorktidePermission;
use App\Service\Ai\TaggableTagContext;
use App\Service\Ai\TagSuggestionAssistant;
use App\Service\Llm\LlmException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * On-demand AI tag suggestions for any taggable record (human-in-the-loop):
 *
 *   POST /v1/ai/suggest-tags
 *     { "resource": "/v1/contacts/{id}" }                              // existing record
 *     { "target": "contact", "text": "...", "workspace": "/v1/workspaces/{id}" }  // draft / create form
 *
 * Runs the LLM synchronously and returns the suggestion inline — the model may
 * only pick from the workspace's real tags in the record's scope; names it
 * invents come back as `suggestedNewTags` for the user to create. Nothing is
 * persisted or applied here: the client attaches chosen tags via the normal
 * (IRI-writable) `tags` field. One generic route (rather than per-resource)
 * because the feature is cross-cutting and must also serve unsaved drafts.
 *
 * Requires EDIT on the record (existing) or its workspace (draft). Responds 503
 * when no LLM credential is configured or LLM egress isn't approved, 502 on a
 * provider failure.
 */
final class AiSuggestTagsController
{
    public function __construct(
        private readonly Security $security,
        private readonly IriConverterInterface $iriConverter,
        private readonly TaggableTagContext $context,
        private readonly TagSuggestionAssistant $assistant,
        private readonly EgressGuard $egress,
    ) {}

    #[Route(path: '/v1/ai/suggest-tags', name: 'api_ai_suggest_tags', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $data = \is_array($data) ? $data : [];

        [$scope, $context, $workspace] = $this->resolveRequest($data);

        if (!$this->assistant->isAvailable()) {
            throw new HttpException(Response::HTTP_SERVICE_UNAVAILABLE, 'AI suggestions are not configured (set ANTHROPIC_API_KEY).');
        }
        if (!$this->egress->isAllowed(EgressModule::Llm)) {
            throw new HttpException(Response::HTTP_SERVICE_UNAVAILABLE, 'LLM egress is not approved (add "llm" to EGRESS_ALLOW).');
        }

        try {
            $result = $this->assistant->suggest($context, $scope, $workspace);
        } catch (LlmException $e) {
            throw new HttpException(Response::HTTP_BAD_GATEWAY, $e->getMessage());
        }

        $tags = array_map(fn (Tag $t): array => [
            'id' => (string) $t->getId(),
            'iri' => $this->iriConverter->getIriFromResource($t),
            'name' => $t->getName(),
            'color' => $t->getColor(),
        ], $result['tags']);

        return new JsonResponse([
            'tags' => $tags,
            'suggestedNewTags' => $result['suggestedNewTags'],
            'reasoning' => $result['reasoning'],
            'model' => $this->assistant->getModel(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: \App\Entity\Enum\TagScope, 1: string, 2: Workspace}
     */
    private function resolveRequest(array $data): array
    {
        // Existing record: resolve by IRI, derive scope + context from the entity.
        $resource = isset($data['resource']) && \is_string($data['resource']) ? trim($data['resource']) : '';
        if ($resource !== '') {
            $entity = $this->resourceFromIri($resource);
            if (!$entity instanceof TaggableInterface) {
                throw new BadRequestHttpException('Resource is not taggable.');
            }
            if (!$this->security->isGranted(WorktidePermission::EDIT, $entity)) {
                throw new AccessDeniedHttpException();
            }

            try {
                return [$this->context->scopeFor($entity), $this->context->textFor($entity), $entity->getWorkspace()];
            } catch (\InvalidArgumentException $e) {
                throw new BadRequestHttpException($e->getMessage());
            }
        }

        // Draft / create form: caller supplies target + text + workspace.
        $target = isset($data['target']) && \is_string($data['target']) ? $data['target'] : '';
        $text = isset($data['text']) && \is_string($data['text']) ? $data['text'] : '';
        $workspaceIri = isset($data['workspace']) && \is_string($data['workspace']) ? trim($data['workspace']) : '';

        if ($text === '' || $workspaceIri === '') {
            throw new BadRequestHttpException('Provide either "resource", or "target" + "text" + "workspace".');
        }

        try {
            $scope = $this->context->scopeForTarget($target);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $workspace = $this->resourceFromIri($workspaceIri);
        if (!$workspace instanceof Workspace) {
            throw new BadRequestHttpException('"workspace" must be a workspace IRI.');
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $workspace)) {
            throw new AccessDeniedHttpException();
        }

        return [$scope, $text, $workspace];
    }

    private function resourceFromIri(string $iri): object
    {
        try {
            return $this->iriConverter->getResourceFromIri($iri);
        } catch (\Throwable) {
            throw new NotFoundHttpException(sprintf('Unknown resource "%s".', $iri));
        }
    }
}
