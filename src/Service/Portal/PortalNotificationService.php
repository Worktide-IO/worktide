<?php

declare(strict_types=1);

namespace App\Service\Portal;

use App\Entity\CustomerAgreement;
use App\Entity\Enum\AgreementStatus;
use App\Entity\Enum\ProposalStatus;
use App\Entity\Enum\SocialPostStatus;
use App\Entity\Task;
use App\Repository\CommentRepository;
use App\Repository\CustomerAgreementRepository;
use App\Repository\CustomerSystemRepository;
use App\Repository\ProjectProposalRepository;
use App\Repository\SocialPostRepository;
use App\Repository\SystemIncidentRepository;
use App\Repository\TaskRepository;

/**
 * Assembles the customer-portal notification feed. It is DERIVED, not stored:
 * each call recomputes "what's new / needs attention" for the current customer
 * from real signals — agency replies on tickets, proposals to review, social
 * posts awaiting approval, offers to sign, open system incidents. Each source
 * is gated by its feature flag. No event-write hooks anywhere; the only stored
 * state is the contact's `portalNotificationsSeenAt` marker (drives read/unread).
 */
final class PortalNotificationService
{
    private const MAX_ITEMS = 30;

    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly TaskRepository $tasks,
        private readonly CommentRepository $comments,
        private readonly ProjectProposalRepository $proposals,
        private readonly SocialPostRepository $socialPosts,
        private readonly CustomerAgreementRepository $agreements,
        private readonly SystemIncidentRepository $incidents,
        private readonly CustomerSystemRepository $systems,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, unreadCount: int}
     */
    public function feed(): array
    {
        $features = $this->portal->features();
        $projects = $this->portal->allowedProjects();
        $seenAt = $this->portal->contact()->getPortalNotificationsSeenAt();

        /** @var list<array{id: string, type: string, title: string, body: ?string, link: string, at: \DateTimeImmutable}> $raw */
        $raw = [];

        // Agency replies on visible tickets (never the customer's own comments).
        $tickets = $this->tasks->findVisiblePortalTickets($projects);
        if ($tickets !== []) {
            /** @var array<string, Task> $byId */
            $byId = [];
            $ids = [];
            foreach ($tickets as $t) {
                $id = $t->getId();
                if ($id !== null) {
                    $byId[$id->toRfc4122()] = $t;
                    $ids[] = $id;
                }
            }
            $selfUserId = $this->portal->contact()->getLinkedUser()?->getId()?->toRfc4122();
            foreach ($this->comments->findRecentForTaskIds($ids) as $comment) {
                if ($comment->getAuthor()->getId()?->toRfc4122() === $selfUserId) {
                    continue; // the customer's own comment isn't a notification
                }
                $task = $byId[$comment->getTargetId()->toRfc4122()] ?? null;
                if ($task === null || $comment->getCreatedAt() === null) {
                    continue;
                }
                $raw[] = [
                    'id' => 'comment:' . $comment->getId()?->toRfc4122(),
                    'type' => 'ticket_reply',
                    'title' => 'Neue Antwort auf ' . $task->getIdentifier(),
                    'body' => $this->excerpt($comment->getContent()),
                    'link' => '/tickets/' . $task->getId()?->toRfc4122(),
                    'at' => $comment->getCreatedAt(),
                ];
            }
        }

        // Proposals awaiting review.
        if (($features['proposals'] ?? false) === true) {
            foreach ($this->proposals->findForPortalProjects($projects) as $proposal) {
                if ($proposal->getStatus() !== ProposalStatus::New || $proposal->getCreatedAt() === null) {
                    continue;
                }
                $raw[] = [
                    'id' => 'proposal:' . $proposal->getId()?->toRfc4122(),
                    'type' => 'proposal',
                    'title' => 'Neuer Vorschlag',
                    'body' => $proposal->getTitle(),
                    'link' => '/proposals',
                    'at' => $proposal->getCreatedAt(),
                ];
            }
        }

        // Social posts awaiting approval.
        if (($features['social'] ?? false) === true) {
            foreach ($this->socialPosts->findForPortalProjects($projects) as $post) {
                if ($post->getStatus() !== SocialPostStatus::PendingApproval || $post->getCreatedAt() === null) {
                    continue;
                }
                $raw[] = [
                    'id' => 'social:' . $post->getId()?->toRfc4122(),
                    'type' => 'social',
                    'title' => 'Social-Beitrag freigeben',
                    'body' => $this->excerpt($post->getBody()),
                    'link' => '/social',
                    'at' => $post->getCreatedAt(),
                ];
            }
        }

        // Offers awaiting signature.
        if (($features['agreements'] ?? false) === true) {
            foreach ($this->agreements->findForPortalCustomer($this->portal->customer()) as $agreement) {
                if (!$this->isSignable($agreement) || $agreement->getUpdatedAt() === null) {
                    continue;
                }
                $revision = $agreement->getCurrentRevision() ?? $agreement->getPendingRevision();
                $raw[] = [
                    'id' => 'agreement:' . $agreement->getId()?->toRfc4122(),
                    'type' => 'agreement',
                    'title' => 'Angebot zur Freigabe',
                    'body' => trim($agreement->getType()->getName() . ' ' . ($revision?->getReference() ?? '')),
                    'link' => '/agreements',
                    'at' => $agreement->getUpdatedAt(),
                ];
            }
        }

        // Open system incidents (Störungen).
        if (($features['monitoring'] ?? false) === true) {
            $systems = $this->systems->findVisiblePortalSystems($this->portal->customer());
            foreach ($this->incidents->findRecentForSystems($systems) as $incident) {
                if (!$incident->isOpen()) {
                    continue;
                }
                $raw[] = [
                    'id' => 'incident:' . $incident->getId()?->toRfc4122(),
                    'type' => 'incident',
                    'title' => 'Störung: ' . $incident->getSystem()->getName(),
                    'body' => $incident->getTitle(),
                    'link' => '/monitoring',
                    'at' => $incident->getStartedAt(),
                ];
            }
        }

        // Newest first, capped.
        usort($raw, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);
        $raw = \array_slice($raw, 0, self::MAX_ITEMS);

        $unread = 0;
        $items = [];
        foreach ($raw as $n) {
            $read = $seenAt !== null && $n['at'] <= $seenAt;
            if (!$read) {
                $unread++;
            }
            $items[] = [
                'id' => $n['id'],
                'type' => $n['type'],
                'title' => $n['title'],
                'body' => $n['body'],
                'link' => $n['link'],
                'occurredAt' => $n['at']->format(\DateTimeInterface::ATOM),
                'read' => $read,
            ];
        }

        return ['items' => $items, 'unreadCount' => $unread];
    }

    private function isSignable(CustomerAgreement $head): bool
    {
        return !$head->getIsSigned()
            && \in_array($head->getStatus(), [AgreementStatus::None, AgreementStatus::Draft, AgreementStatus::InNegotiation], true)
            && ($head->getPendingRevision() ?? $head->getCurrentRevision()) !== null;
    }

    private function excerpt(string $text, int $max = 100): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
