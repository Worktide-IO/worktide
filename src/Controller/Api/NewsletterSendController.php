<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Enum\NewsletterIssueStatus;
use App\Entity\NewsletterIssue;
use App\Entity\User;
use App\Message\SendNewsletterMessage;
use App\Repository\NewsletterRepository;
use App\Repository\NewsletterSubscriptionRepository;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Sends a draft {@see NewsletterIssue} to its node's opted-in contacts. A plain
 * controller (not an API Platform operation) because it mutates state + fans out
 * mail; loads by id and authorizes with the EDIT voter on the issue's workspace
 * (em->find bypasses the read-side WorkspaceScopeExtension, so the check is
 * explicit). One send only — a sent issue is terminal (duplicate to resend).
 */
final class NewsletterSendController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly NewsletterSubscriptionRepository $subscriptions,
        private readonly NewsletterRepository $newsletters,
        private readonly EgressGuard $egress,
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route(
        path: '/v1/newsletter_issues/{id}/send',
        name: 'api_newsletter_issue_send',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        if (!$this->security->getUser() instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }
        $body = json_decode($request->getContent() ?: '{}', true);
        $includeDescendants = \is_array($body) && ($body['includeDescendants'] ?? false) === true;

        try {
            $issue = $this->em->find(NewsletterIssue::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
        if ($issue === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $issue->getWorkspace())) {
            throw new AccessDeniedHttpException('You cannot manage this workspace.');
        }
        if ($issue->isSent()) {
            throw new ConflictHttpException('This newsletter issue has already been sent.');
        }
        if (!$this->egress->isAllowed(EgressModule::NewsletterSend)) {
            throw new ConflictHttpException('Newsletter sending is disabled (set EGRESS_ALLOW=newsletter_send).');
        }

        $newsletter = $issue->getNewsletter();
        $nodes = $newsletter === null
            ? []
            : ($includeDescendants ? $this->newsletters->findSubtree($newsletter) : [$newsletter]);

        // Union recipients across the target node(s), deduped by contact — a
        // contact opted into both a parent and a child gets a single mail.
        $recipients = [];
        foreach ($nodes as $node) {
            foreach ($this->subscriptions->findActiveRecipientsForNewsletter($node) as $contact) {
                $cid = $contact->getId()?->toRfc4122();
                if ($cid !== null) {
                    $recipients[$cid] = $contact;
                }
            }
        }
        $recipients = array_values($recipients);

        $issue->setStatus(NewsletterIssueStatus::Sent)
            ->setSentAt(new \DateTimeImmutable())
            ->setRecipientCount(\count($recipients));
        $this->em->flush();

        foreach ($recipients as $contact) {
            $contactId = $contact->getId();
            $issueId = $issue->getId();
            if ($contactId !== null && $issueId !== null) {
                $this->bus->dispatch(new SendNewsletterMessage($issueId, $contactId));
            }
        }

        return new JsonResponse(['sent' => true, 'recipientCount' => \count($recipients)]);
    }
}
