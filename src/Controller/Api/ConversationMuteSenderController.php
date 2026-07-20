<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Entity\Enum\InboundMuteMatchType;
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
 * One-click "mute this kind of message": create an {@see InboundMuteRule} from a
 * conversation and immediately hide this thread plus any existing matches.
 *
 *   POST /v1/conversations/{id}/mute-sender
 *   { "matchType": "sender_email"|"subject_contains", "value": "…" }   // both optional
 *
 * Defaults: matchType=sender_email, value=the conversation's sender address.
 * Muted threads are NOT deleted — they keep `mutedAt` set (still searchable,
 * just out of the default inbox) and drop out of automation/AI going forward.
 */
final class ConversationMuteSenderController
{
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

        $matchType = InboundMuteMatchType::tryFrom((string) ($data['matchType'] ?? InboundMuteMatchType::SenderEmail->value));
        if ($matchType === null) {
            throw new BadRequestHttpException('Unbekannter matchType.');
        }

        $value = trim((string) ($data['value'] ?? ''));
        if ($value === '' && $matchType === InboundMuteMatchType::SenderEmail) {
            $value = (string) $this->matcher->emailFromRaw($conversation->getSenderRaw());
        }
        if ($value === '') {
            throw new BadRequestHttpException('Kein Wert zum Stummschalten (matchType/value angeben).');
        }

        $now = new \DateTimeImmutable();
        $rule = (new InboundMuteRule())
            ->setMatchType($matchType)
            ->setValue($value);
        $rule->setWorkspace($conversation->getWorkspace());
        $this->em->persist($rule);

        $muted = $this->backfill($conversation, $matchType, $value, $now);
        $rule->setMatchCount($muted);
        $rule->setLastMatchedAt($muted > 0 ? $now : null);

        $this->em->flush();

        return new JsonResponse([
            'rule' => [
                'id' => $rule->getId()?->toRfc4122(),
                'matchType' => $matchType->value,
                'value' => $value,
            ],
            'mutedCount' => $muted,
        ], 201);
    }

    /**
     * Flag every not-yet-muted conversation in the workspace that matches the
     * rule (incl. the one the user clicked). Returns how many were muted.
     */
    private function backfill(Conversation $origin, InboundMuteMatchType $matchType, string $value, \DateTimeImmutable $now): int
    {
        $qb = $this->em->createQueryBuilder()
            ->update(Conversation::class, 'c')
            ->set('c.mutedAt', ':now')->setParameter('now', $now)
            ->where('c.workspace = :ws')->setParameter('ws', $origin->getWorkspace())
            ->andWhere('c.mutedAt IS NULL');

        if ($matchType === InboundMuteMatchType::SenderEmail) {
            // senderRaw is "Name <a@b>" or a bare address — LIKE on the address.
            $qb->andWhere('LOWER(c.senderRaw) LIKE :needle')
                ->setParameter('needle', '%' . mb_strtolower($value) . '%');
        } else {
            $qb->andWhere('LOWER(c.subject) LIKE :needle')
                ->setParameter('needle', '%' . mb_strtolower($value) . '%');
        }

        return (int) $qb->getQuery()->execute();
    }
}
