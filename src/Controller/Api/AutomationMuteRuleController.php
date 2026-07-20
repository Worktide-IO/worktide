<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Automation\AutomationTokenGuard;
use App\Entity\Enum\InboundRuleCombinator;
use App\Entity\InboundMuteRule;
use App\Entity\Workspace;
use App\Repository\InboundMuteRuleRepository;
use App\Service\Inbound\InboundMuteMatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Token-authed CRUD for {@see InboundMuteRule} so the external automation engine
 * (n8n) can create/list/update/delete mute rules without a staff JWT. Worktide
 * stays the single source of truth (DB); n8n just manages the rules through it.
 * Staff manage the same rows via the JWT-secured API-Platform resource.
 *
 *   GET    /v1/automation/mute-rules?workspaceId=<uuid>
 *   POST   /v1/automation/mute-rules   { workspaceId, combinator?, conditions:[{field,operator,value}], isEnabled? }
 *   PATCH  /v1/automation/mute-rules/{id}   { combinator?, conditions?, isEnabled? }
 *   DELETE /v1/automation/mute-rules/{id}
 *
 * Auth: X-Worktide-Automation-Token (AUTOMATION_API_TOKEN).
 */
final class AutomationMuteRuleController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InboundMuteRuleRepository $rules,
        private readonly InboundMuteMatcher $matcher,
        private readonly AutomationTokenGuard $guard,
    ) {}

    #[Route(path: '/v1/automation/mute-rules', name: 'api_automation_mute_rules_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->guard->assert($request);
        $workspace = $this->workspace((string) $request->query->get('workspaceId', ''));

        return new JsonResponse([
            'rules' => array_map($this->serialize(...), $this->rules->findBy(['workspace' => $workspace], ['createdAt' => 'DESC'])),
        ]);
    }

    #[Route(path: '/v1/automation/mute-rules', name: 'api_automation_mute_rules_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->guard->assert($request);
        $data = $this->body($request);
        $workspace = $this->workspace((string) ($data['workspaceId'] ?? ''));

        try {
            $conditions = $this->matcher->normalizeConditions($data['conditions'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $rule = (new InboundMuteRule())
            ->setCombinator($this->combinator($data))
            ->setConditions($conditions)
            ->setIsEnabled((bool) ($data['isEnabled'] ?? true));
        $rule->setWorkspace($workspace);
        $this->em->persist($rule);
        $this->em->flush();

        return new JsonResponse($this->serialize($rule), 201);
    }

    #[Route(path: '/v1/automation/mute-rules/{id}', name: 'api_automation_mute_rules_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $this->guard->assert($request);
        $rule = $this->rule($id);
        $data = $this->body($request);

        if (\array_key_exists('combinator', $data)) {
            $rule->setCombinator($this->combinator($data));
        }
        if (\array_key_exists('conditions', $data)) {
            try {
                $rule->setConditions($this->matcher->normalizeConditions($data['conditions']));
            } catch (\InvalidArgumentException $e) {
                throw new BadRequestHttpException($e->getMessage());
            }
        }
        if (\array_key_exists('isEnabled', $data)) {
            $rule->setIsEnabled((bool) $data['isEnabled']);
        }
        $this->em->flush();

        return new JsonResponse($this->serialize($rule));
    }

    #[Route(path: '/v1/automation/mute-rules/{id}', name: 'api_automation_mute_rules_delete', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        $this->guard->assert($request);
        $this->em->remove($this->rule($id));
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    /** @param array<string, mixed> $data */
    private function combinator(array $data): InboundRuleCombinator
    {
        $c = InboundRuleCombinator::tryFrom((string) ($data['combinator'] ?? InboundRuleCombinator::And->value));
        if ($c === null) {
            throw new BadRequestHttpException('Unbekannter combinator (and|or).');
        }

        return $c;
    }

    private function workspace(string $id): Workspace
    {
        if (!Uuid::isValid($id)) {
            throw new BadRequestHttpException('workspaceId (uuid) ist erforderlich.');
        }
        $workspace = $this->em->find(Workspace::class, Uuid::fromString($id));
        if (!$workspace instanceof Workspace) {
            throw new NotFoundHttpException('Workspace nicht gefunden.');
        }

        return $workspace;
    }

    private function rule(string $id): InboundMuteRule
    {
        if (!Uuid::isValid($id)) {
            throw new BadRequestHttpException('Ungültige Rule-ID.');
        }
        $rule = $this->em->find(InboundMuteRule::class, Uuid::fromString($id));
        if (!$rule instanceof InboundMuteRule) {
            throw new NotFoundHttpException('Regel nicht gefunden.');
        }

        return $rule;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = json_decode($request->getContent() ?: '{}', true);
        if (!\is_array($data)) {
            throw new BadRequestHttpException('Ungültiger Request-Body.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function serialize(InboundMuteRule $rule): array
    {
        return [
            'id' => $rule->getId()?->toRfc4122(),
            'workspaceId' => $rule->getWorkspace()->getId()?->toRfc4122(),
            'combinator' => $rule->getCombinator()->value,
            'conditions' => $rule->getConditions(),
            'isEnabled' => $rule->isEnabled(),
            'matchCount' => $rule->getMatchCount(),
            'lastMatchedAt' => $rule->getLastMatchedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
