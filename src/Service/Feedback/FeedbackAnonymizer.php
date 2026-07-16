<?php

declare(strict_types=1);

namespace App\Service\Feedback;

use App\Entity\Comment;
use App\Entity\FeedbackSubmission;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\FeedbackSubmissionRepository;
use App\Repository\WorkspaceMemberRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The SINGLE place feedback-board DTOs are built. Everything the shared,
 * cross-tenant board exposes goes through here so identity can never leak:
 * non-super-admins only ever see role LABEL keys ('you' | 'team' | 'reporter'
 * | 'user'), never names/emails/workspace ids. Super-admins (Worktide) get the
 * real identity.
 *
 * Category + status are emitted as STABLE KEYS the SPA translates
 * (label.feedback.category.* / label.feedback.status.*) so the board renders in
 * each viewer's own language; the raw name rides along only as a fallback.
 */
final class FeedbackAnonymizer
{
    /** Tracker icon → stable category key. */
    private const CATEGORY_BY_ICON = [
        'bug' => 'bug',
        'sparkles' => 'feature',
        'layout' => 'ui_ux',
    ];

    /** Seeded status name → stable status key. */
    private const STATUS_KEY_BY_NAME = [
        'New' => 'new',
        'Triaged' => 'triaged',
        'Planned' => 'planned',
        'In progress' => 'in_progress',
        'Done' => 'done',
        'Declined' => 'declined',
    ];

    /** @var list<string>|null memoized rfc4122 ids of platform-workspace members */
    private ?array $platformMemberIds = null;

    public function __construct(
        private readonly Security $security,
        private readonly FeedbackProjectLocator $locator,
        private readonly WorkspaceMemberRepository $members,
        private readonly FeedbackSubmissionRepository $submissions,
        private readonly CommentRepository $comments,
    ) {}

    /**
     * Anonymized board list for a set of feedback tickets (bulk-loads sidecars
     * + reply counts to avoid N+1).
     *
     * @param list<Task> $tasks
     * @return list<array<string, mixed>>
     */
    public function boardList(array $tasks): array
    {
        $ids = array_values(array_filter(array_map(static fn (Task $t) => $t->getId(), $tasks)));
        $subs = $this->submissions->mapByTaskIds($ids);
        $counts = $this->comments->countByTaskIds($ids);

        $out = [];
        foreach ($tasks as $task) {
            $key = $task->getId()?->toRfc4122() ?? '';
            $out[] = $this->ticketDto($task, $subs[$key] ?? null, $counts[$key] ?? 0);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function ticketDto(Task $task, ?FeedbackSubmission $submission, int $replyCount, bool $withDescription = false): array
    {
        $reporter = $this->reporterOf($submission);
        $tracker = $task->getTracker();
        $status = $task->getStatus();

        $dto = [
            'id' => $task->getId()?->toRfc4122(),
            'identifier' => $task->getIdentifier(),
            'title' => $task->getTitle(),
            'category' => [
                'key' => $tracker !== null ? (self::CATEGORY_BY_ICON[$tracker->getIcon()] ?? 'other') : 'other',
                'label' => $tracker?->getName(),
                'icon' => $tracker?->getIcon(),
                'color' => $tracker?->getColor(),
            ],
            'status' => [
                'key' => self::STATUS_KEY_BY_NAME[$status->getName()] ?? $this->slug($status->getName()),
                'label' => $status->getName(),
                'isCompleted' => $status->isCompleted(),
            ],
            'authorLabel' => $this->reporterLabel($reporter),
            'isMine' => $this->isViewer($reporter),
            'replyCount' => $replyCount,
            'createdAt' => $task->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $task->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];

        if ($withDescription) {
            $dto['description'] = $task->getDescription();
        }

        // The Worktide team (platform-workspace members / super-admins) also see
        // who filed it and — in the detail view — the diagnostics + screenshot.
        if ($this->isViewerPlatformAdmin()) {
            $dto['submitter'] = $this->adminSubmitter($submission);
            if ($withDescription) {
                $dto['diagnostics'] = $submission?->getDiagnostics();
                $dto['hasScreenshot'] = $submission?->getScreenshotFile() !== null;
            }
        }

        return $dto;
    }

    /**
     * True when the current viewer is on the Worktide side (a member of the
     * platform feedback workspace, or a super-admin) — the only viewers who see
     * submitter identity, diagnostics and screenshots. Everyone else gets the
     * anonymized board.
     */
    public function isViewerPlatformAdmin(): bool
    {
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }
        $viewer = $this->security->getUser();
        $id = $viewer instanceof User ? $viewer->getId()?->toRfc4122() : null;

        return $id !== null && \in_array($id, $this->platformMemberIds(), true);
    }

    /** @return array{name: ?string, workspace: ?string, sourceApp: ?string, route: ?string} */
    private function adminSubmitter(?FeedbackSubmission $submission): array
    {
        $contact = $submission?->getSubmitterContact();
        $name = $contact !== null
            ? trim($contact->getFirstName() . ' ' . $contact->getLastName())
            : $this->reporterOf($submission)?->getFullName();

        return [
            'name' => $name !== null && $name !== '' ? $name : null,
            'workspace' => $submission?->getOriginWorkspace()?->getName(),
            'sourceApp' => $submission?->getSourceApp(),
            'route' => $submission?->getRoute(),
        ];
    }

    /**
     * Anonymized reply thread for a ticket.
     *
     * @param list<Comment> $comments
     * @return list<array<string, mixed>>
     */
    public function replyThread(array $comments, ?FeedbackSubmission $submission): array
    {
        $reporter = $this->reporterOf($submission);

        return array_map(fn (Comment $c): array => [
            'id' => $c->getId()?->toRfc4122(),
            'authorLabel' => $this->authorLabel($c->getAuthor(), $reporter),
            'content' => $c->getContent(),
            'createdAt' => $c->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $comments);
    }

    public function reporterOf(?FeedbackSubmission $submission): ?User
    {
        if ($submission === null) {
            return null;
        }

        return $submission->getSubmitterUser() ?? $submission->getSubmitterContact()?->getLinkedUser();
    }

    /**
     * Label for the ORIGINAL reporter on a ticket card: 'you' | 'team' | 'user'
     * (never 'reporter' — that only distinguishes the OP within a thread).
     */
    private function reporterLabel(?User $reporter): string
    {
        if ($this->isViewer($reporter)) {
            return 'you';
        }
        if ($this->isPlatformSide($reporter)) {
            return 'team';
        }

        return 'user';
    }

    /**
     * Label for a reply author, from the viewer's perspective. Super-admin
     * viewers see the real identity instead of a role key.
     *
     * @return string|array{name: ?string}
     */
    private function authorLabel(?User $author, ?User $reporter): string|array
    {
        if ($this->isViewerPlatformAdmin()) {
            return $this->identity($author);
        }
        if ($this->isViewer($author)) {
            return 'you';
        }
        if ($this->isPlatformSide($author)) {
            return 'team';
        }
        if ($author !== null && $reporter !== null && $this->sameUser($author, $reporter)) {
            return 'reporter';
        }

        return 'user';
    }

    /** @return array{name: ?string} */
    private function identity(?User $user): array
    {
        return ['name' => $user?->getFullName()];
    }

    private function isViewer(?User $user): bool
    {
        $viewer = $this->security->getUser();

        return $user !== null && $viewer instanceof User && $this->sameUser($user, $viewer);
    }

    private function isPlatformSide(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if (\in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }
        $id = $user->getId()?->toRfc4122();

        return $id !== null && \in_array($id, $this->platformMemberIds(), true);
    }

    /** @return list<string> */
    private function platformMemberIds(): array
    {
        if ($this->platformMemberIds === null) {
            $workspace = $this->locator->platformWorkspace();
            $this->platformMemberIds = $workspace === null ? [] : $this->members->userIdsForWorkspace($workspace);
        }

        return $this->platformMemberIds;
    }

    private function sameUser(User $a, User $b): bool
    {
        $ia = $a->getId()?->toRfc4122();
        $ib = $b->getId()?->toRfc4122();

        return $ia !== null && $ia === $ib;
    }

    private function slug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? $slug;

        return trim($slug, '_');
    }
}
