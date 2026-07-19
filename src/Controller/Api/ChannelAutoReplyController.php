<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Channel;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Repository\WorkspaceMemberRepository;
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
 * Set the per-mailbox auto-reply (receipt acknowledgement):
 *
 *   PUT /v1/channels/{id}/auto-reply
 *     { "enabled": true, "subject": "...", "bodyHtml": "<p>…</p>",
 *       "bodyText": "…", "throttleHours": 24 }
 *
 * Access is deliberately more permissive than full channel EDIT, matching the
 * requirement "anyone with access to a shared mailbox may set its message":
 *   - personal mailbox (isShared=false): the owner (or a workspace admin);
 *   - shared mailbox: any active workspace member.
 *
 * The full channel PATCH (SMTP creds, adapter config) stays admin-only via
 * {@see \App\Security\Voter\ChannelVoter}; this endpoint only touches the
 * auto-reply fields.
 */
final class ChannelAutoReplyController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly WorkspaceMemberRepository $members,
    ) {}

    #[Route(
        path: '/v1/channels/{id}/auto-reply',
        name: 'api_channel_auto_reply',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['PUT'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $channel = $this->em->find(Channel::class, Uuid::fromString($id));
        if ($channel === null) {
            throw new NotFoundHttpException();
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->mayManageAutoReply($channel, $user)) {
            throw new AccessDeniedHttpException('Keine Berechtigung, die Auto-Antwort dieses Postfachs zu setzen.');
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            throw new BadRequestHttpException('Ungültiger Request-Body.');
        }

        $enabled = (bool) ($data['enabled'] ?? $channel->isAutoReplyEnabled());
        $bodyHtml = \array_key_exists('bodyHtml', $data) ? $this->nullableString($data['bodyHtml']) : $channel->getAutoReplyBodyHtml();
        $bodyText = \array_key_exists('bodyText', $data) ? $this->nullableString($data['bodyText']) : $channel->getAutoReplyBodyText();

        // Enabling needs at least one non-empty body variant.
        if ($enabled && ($bodyHtml ?? '') === '' && ($bodyText ?? '') === '') {
            throw new BadRequestHttpException('Zum Aktivieren muss ein Text- oder HTML-Body gesetzt sein.');
        }

        $channel->setAutoReplyEnabled($enabled);
        if (\array_key_exists('subject', $data)) {
            $channel->setAutoReplySubject($this->nullableString($data['subject']));
        }
        $channel->setAutoReplyBodyHtml($bodyHtml);
        $channel->setAutoReplyBodyText($bodyText);
        if (\array_key_exists('throttleHours', $data)) {
            $channel->setAutoReplyThrottleHours((int) $data['throttleHours']);
        }

        $this->em->flush();

        return new JsonResponse([
            'id' => $channel->getId()?->toRfc4122(),
            'autoReplyEnabled' => $channel->isAutoReplyEnabled(),
            'autoReplySubject' => $channel->getAutoReplySubject(),
            'autoReplyBodyHtml' => $channel->getAutoReplyBodyHtml(),
            'autoReplyBodyText' => $channel->getAutoReplyBodyText(),
            'autoReplyThrottleHours' => $channel->getAutoReplyThrottleHours(),
        ]);
    }

    private function mayManageAutoReply(Channel $channel, User $user): bool
    {
        // Personal mailbox: the owner manages their own message.
        if (!$channel->isShared() && $channel->getOwnerUser() === $user) {
            return true;
        }

        $member = $this->members->findOneBy([
            'workspace' => $channel->getWorkspace(),
            'user' => $user,
            'isActive' => true,
        ]);
        if ($member === null) {
            return false;
        }

        // Admins/owners manage any mailbox.
        if (\in_array($member->getRole(), [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true)) {
            return true;
        }

        // Plain members: only shared mailboxes they have access to.
        return $channel->isShared();
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
