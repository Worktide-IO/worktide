<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Entity\User;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Collision detection — broadcasts "who is looking at this conversation".
 *
 *   POST /v1/conversations/{id}/presence   { "state": "viewing" | "left" }
 *
 * The SPA can't publish to the Mercure hub directly (its token is
 * subscribe-only), so a viewer announces itself through this voter-gated
 * endpoint, which relays a presence frame to the per-conversation topic
 * `worktide:conversation:{id}:presence`. Other viewers subscribed to that
 * topic show a "also here" hint.
 *
 * Deliberately stateless: no server-side presence store. Clients gossip a
 * heartbeat while the conversation is open and expire stale peers locally;
 * a `left` frame is a best-effort fast-path for a clean unmount. Presence
 * carries only the viewer's display name (already visible to workspace
 * members) — the VIEW voter here is what keeps announcements in-tenant.
 */
final class ConversationPresenceController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly HubInterface $hub,
    ) {}

    #[Route(
        path: '/v1/conversations/{id}/presence',
        name: 'api_conversation_presence',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function __invoke(string $id, Request $request): Response
    {
        $conversation = $this->em->find(Conversation::class, Uuid::fromString($id));
        if ($conversation === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $conversation)) {
            throw new AccessDeniedHttpException();
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $data = json_decode($request->getContent(), true);
        $state = \is_array($data) && ($data['state'] ?? null) === 'left' ? 'left' : 'viewing';

        $this->hub->publish(new Update(
            topics: ['worktide:conversation:' . $id . ':presence'],
            data: json_encode([
                'userId' => $user->getId()?->toRfc4122(),
                'name' => $user->getFullName(),
                'state' => $state,
                'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ]) ?: '{}',
            private: true,
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
