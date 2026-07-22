<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Entity\Enum\InboundRuleCombinator;
use App\Entity\Enum\InboundRuleField;
use App\Entity\Enum\InboundRuleOperator;
use App\Entity\InboundMuteRule;
use App\Service\Inbound\InboundMuteMatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * One-click "mute this kind of message": create a Thunderbird-style
 * {@see InboundMuteRule} and immediately hide matching threads.
 *
 *   POST /v1/conversations/{id}/mute-sender
 *   { "combinator": "and", "conditions": [{ "field": "...", "operator": "...", "value": "..." }] }
 *
 * Both fields optional. Default (nothing sent) = a single condition
 * `sender_email equals <the conversation's sender>` — but the SPA typically
 * sends a refined set (e.g. sender AND subject contains "Verification Code") so
 * NOT every mail from that sender is muted. Muted threads keep `mutedAt`
 * (searchable, out of the default inbox); nothing is deleted.
 */
final class ConversationMuteSenderController
{
    /** Safety bound on the back-fill scan (see roadmap: batch/index for scale). */
    private const BACKFILL_LIMIT = 5000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly InboundMuteMatcher $matcher,
    ) {}

    #[Route(
        path: '/v1/conversations/{id}/mute-sender',
        name: 'api_conversation_mute_sender',
        methods: ['POST'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw new BadRequestHttpException('Ungültige Conversation-ID.');
        }
        $conversation = $this->em->find(Conversation::class, Uuid::fromString($id));
        if (!$conversation instanceof Conversation) {
            throw new NotFoundHttpException('Conversation nicht gefunden.');
        }
        if (!$this->security->isGranted('EDIT', $conversation->getWorkspace())) {
            throw new AccessDeniedHttpException('Kein Zugriff auf diesen Workspace.');
        }

        $data = json_decode($request->getContent() ?: '{}', true);
        if (!\is_array($data)) {
            throw new BadRequestHttpException('Ungültiger Request-Body.');
        }

        $combinator = InboundRuleCombinator::tryFrom((string) ($data['combinator'] ?? InboundRuleCombinator::And->value))
            ?? InboundRuleCombinator::And;

        try {
            $conditions = $this->matcher->normalizeConditions($data['conditions'] ?? $this->defaultConditions($conversation));
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $now = new \DateTimeImmutable();
        $rule = (new InboundMuteRule())
            ->setCombinator($combinator)
            ->setConditions($conditions);
        $rule->setWorkspace($conversation->getWorkspace());
        $this->em->persist($rule);

        $muted = $this->backfill($conversation->getWorkspace(), $rule, $now);
        $rule->setMatchCount($muted);
        $rule->setLastMatchedAt($muted > 0 ? $now : null);

        $this->em->flush();

        return new JsonResponse([
            'rule' => [
                'id' => $rule->getId()?->toRfc4122(),
                'combinator' => $rule->getCombinator()->value,
                'conditions' => $rule->getConditions(),
            ],
            'mutedCount' => $muted,
        ], 201);
    }

    /**
     * Default one-click rule when the client sends none: mute the exact sender.
     * (The SPA usually sends a refined set instead.)
     *
     * @return list<array{field: string, operator: string, value: string}>
     */
    private function defaultConditions(Conversation $conversation): array
    {
        $email = $this->matcher->emailFromRaw($conversation->getSenderRaw());
        if ($email === null) {
            throw new BadRequestHttpException('Kein Absender zum Stummschalten — conditions angeben.');
        }

        return [[
            'field' => InboundRuleField::SenderEmail->value,
            'operator' => InboundRuleOperator::Equals->value,
            'value' => $email,
        ]];
    }

    /**
     * Flag every not-yet-muted conversation in the workspace the rule matches.
     * Evaluated in PHP against conversation fields (body conditions only apply
     * to new messages — see matcher::fieldsFromConversation). Returns the count.
     */
    private function backfill(\App\Entity\Workspace $workspace, InboundMuteRule $rule, \DateTimeImmutable $now): int
    {
        /** @var list<Conversation> $candidates */
        $candidates = $this->em->createQueryBuilder()
            ->select('c')->from(Conversation::class, 'c')
            ->where('c.workspace = :ws')->setParameter('ws', $workspace)
            ->andWhere('c.mutedAt IS NULL')
            ->setMaxResults(self::BACKFILL_LIMIT)
            ->getQuery()->getResult();

        $count = 0;
        foreach ($candidates as $c) {
            if ($this->matcher->ruleMatches($rule, $this->matcher->fieldsFromConversation($c))) {
                $c->setMutedAt($now);
                ++$count;
            }
        }

        return $count;
    }
}
