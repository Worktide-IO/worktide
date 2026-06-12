<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Comment;
use App\Entity\CommentReaction;
use App\Entity\User;
use App\Repository\CommentReactionRepository;
use App\Repository\CommentRepository;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Emoji reactions on comments — awork-compatible operation semantics:
 *   POST /v1/comments/{id}/reactions   { "emoji": "👍", "operation": "add"|"remove" }
 *
 * "add" is idempotent (returns the existing reaction if already there).
 * "remove" is forgiving (no-op if the reaction never existed).
 *
 * Each request returns the updated reactions roster grouped by emoji.
 */
final class CommentReactionsController
{
    private const ALLOWED_OPERATIONS = ['add', 'remove'];
    private const MAX_EMOJI_LENGTH = 32;

    public function __construct(
        private readonly Security $security,
        private readonly CommentRepository $comments,
        private readonly CommentReactionRepository $reactions,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/comments/{id}/reactions',
        name: 'api_comment_reactions',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $comment = $this->comments->find(Uuid::fromString($id));
        if (!$comment instanceof Comment) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $comment)) {
            throw new AccessDeniedHttpException();
        }

        $payload = $this->decodeBody($request);
        $emoji = $payload['emoji'] ?? null;
        $operation = $payload['operation'] ?? 'add';

        if (!\is_string($emoji) || $emoji === '' || mb_strlen($emoji) > self::MAX_EMOJI_LENGTH) {
            throw new BadRequestHttpException('Field "emoji" required (string, max 32 chars).');
        }
        if (!\in_array($operation, self::ALLOWED_OPERATIONS, true)) {
            throw new BadRequestHttpException('Field "operation" must be "add" or "remove".');
        }

        if ($operation === 'add') {
            $existing = $this->reactions->findOneBy([
                'comment' => $comment,
                'user' => $user,
                'emoji' => $emoji,
            ]);
            if ($existing === null) {
                $reaction = (new CommentReaction())
                    ->setComment($comment)
                    ->setUser($user)
                    ->setEmoji($emoji);
                $this->em->persist($reaction);
                $this->em->flush();
            }
        } else {
            $existing = $this->reactions->findOneBy([
                'comment' => $comment,
                'user' => $user,
                'emoji' => $emoji,
            ]);
            if ($existing !== null) {
                $this->em->remove($existing);
                $this->em->flush();
            }
        }

        return new JsonResponse($this->summarise($comment));
    }

    /**
     * @return array{commentId: string|null, reactions: array<string, array{count: int, userIds: list<string>}>}
     */
    private function summarise(Comment $comment): array
    {
        $em = $this->em;
        $em->refresh($comment);

        $grouped = [];
        foreach ($comment->getReactions() as $r) {
            $emoji = $r->getEmoji();
            if (!isset($grouped[$emoji])) {
                $grouped[$emoji] = ['count' => 0, 'userIds' => []];
            }
            $grouped[$emoji]['count']++;
            $uid = $r->getUser()->getId()?->toRfc4122();
            if ($uid !== null) {
                $grouped[$emoji]['userIds'][] = $uid;
            }
        }
        ksort($grouped);

        return [
            'commentId' => $comment->getId()?->toRfc4122(),
            'reactions' => $grouped,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeBody(Request $request): array
    {
        $raw = (string) $request->getContent();
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('Invalid JSON: ' . $e->getMessage(), $e);
        }
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('Body must be a JSON object.');
        }
        return $decoded;
    }
}
