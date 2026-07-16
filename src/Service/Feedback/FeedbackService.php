<?php

declare(strict_types=1);

namespace App\Service\Feedback;

use App\Entity\Comment;
use App\Entity\Contact;
use App\Entity\Enum\CommentTarget;
use App\Entity\Enum\FileTarget;
use App\Entity\Enum\TaskCreatedVia;
use App\Entity\Enum\TaskPriority;
use App\Entity\FeedbackSubmission;
use App\Entity\File;
use App\Entity\FileVersion;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\CommentRepository;
use App\Repository\FeedbackSubmissionRepository;
use App\Repository\TaskRepository;
use App\Repository\TaskStatusRepository;
use App\Repository\TrackerRepository;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Domain logic for the shared feedback board — creation, listing, detail,
 * replies and (admin-only) screenshot attachment. Both the staff and portal
 * controllers delegate here; all DTOs come from {@see FeedbackAnonymizer} so
 * nothing sensitive leaks. See docs/feedback-board-plan.md.
 */
final class FeedbackService
{
    public const MAX_SCREENSHOT_BYTES = 10 * 1024 * 1024;

    /** Stable category key → the tracker icon it maps to in the feedback project. */
    private const ICON_BY_CATEGORY = [
        'bug' => 'bug',
        'feature' => 'sparkles',
        'ui_ux' => 'layout',
    ];

    public function __construct(
        private readonly FeedbackProjectLocator $locator,
        private readonly FeedbackAnonymizer $anonymizer,
        private readonly EntityManagerInterface $em,
        private readonly TaskRepository $tasks,
        private readonly TrackerRepository $trackers,
        private readonly TaskStatusRepository $statuses,
        private readonly CommentRepository $comments,
        private readonly FeedbackSubmissionRepository $submissions,
        private readonly FileStorage $storage,
    ) {}

    /**
     * Create a feedback ticket + its admin-only sidecar. Returns the anonymized
     * ticket DTO (the caller reads `id` for a follow-up screenshot upload).
     *
     * @param array<string, mixed>|null $diagnostics
     * @return array<string, mixed>
     */
    public function submit(
        string $title,
        ?string $description,
        ?string $categoryKey,
        User $createdBy,
        ?User $submitterUser,
        ?Contact $submitterContact,
        ?Workspace $originWorkspace,
        string $sourceApp,
        ?string $route,
        ?string $appVersion,
        ?string $userAgent,
        ?array $diagnostics,
    ): array {
        $project = $this->locator->feedbackProjectOrFail();
        $workspace = $project->getWorkspace();
        $tracker = $this->resolveTracker($workspace, $categoryKey);

        $task = (new Task())
            ->setWorkspace($workspace)
            ->setProject($project)
            ->setTitle($title)
            ->setDescription($description)
            ->setStatus($this->defaultStatus($workspace))
            ->setPriority(TaskPriority::Normal)
            ->setTracker($tracker)
            ->setCreatedVia(TaskCreatedVia::Feedback)
            ->setCreatedBy($createdBy)
            ->setIdentifier($project->getKey() . '-' . dechex(random_int(0x1000, 0xFFFF)));
        $task->setIsHiddenForConnectUsers(false);
        $this->em->persist($task);
        $this->em->flush();

        $submission = (new FeedbackSubmission())
            ->setTask($task)
            ->setSubmitterUser($submitterUser)
            ->setSubmitterContact($submitterContact)
            ->setOriginWorkspace($originWorkspace)
            ->setSourceApp($sourceApp)
            ->setRoute($route !== null ? mb_substr($route, 0, 512) : null)
            ->setAppVersion($appVersion !== null ? mb_substr($appVersion, 0, 64) : null)
            ->setUserAgent($userAgent)
            ->setDiagnostics($diagnostics);
        $this->em->persist($submission);
        $this->em->flush();

        return $this->anonymizer->ticketDto($task, $submission, 0, withDescription: true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function board(?string $categoryKey, ?string $statusKey): array
    {
        $project = $this->locator->feedbackProjectOrFail();
        $workspace = $project->getWorkspace();

        $trackerId = null;
        if ($categoryKey !== null && isset(self::ICON_BY_CATEGORY[$categoryKey])) {
            $trackerId = $this->trackerByCategory($workspace, $categoryKey)?->getId();
        }
        $statusId = null;
        if ($statusKey !== null) {
            $statusId = $this->statusByKey($workspace, $statusKey)?->getId();
        }

        return $this->anonymizer->boardList($this->tasks->findFeedbackBoard($project, $trackerId, $statusId));
    }

    /**
     * @return array{ticket: array<string, mixed>, replies: list<array<string, mixed>>}
     */
    public function detail(Uuid $id): array
    {
        $task = $this->requireTicket($id);
        $submission = $this->submissions->findOneByTask($task);
        $comments = $this->comments->findBy(
            ['target' => CommentTarget::Task, 'targetId' => $task->getId(), 'isHiddenForConnectUsers' => false],
            ['createdAt' => 'ASC'],
        );

        return [
            'ticket' => $this->anonymizer->ticketDto($task, $submission, \count($comments), withDescription: true),
            'replies' => $this->anonymizer->replyThread($comments, $submission),
        ];
    }

    /**
     * Add a public reply and return the anonymized reply DTO (author sees
     * their own as "you").
     *
     * @return array<string, mixed>
     */
    public function reply(Uuid $id, string $content, User $author): array
    {
        $task = $this->requireTicket($id);

        $comment = (new Comment())
            ->setTarget(CommentTarget::Task)
            ->setTargetId($task->getId())
            ->setAuthor($author)
            ->setContent($content);
        $comment->setWorkspace($task->getWorkspace());
        $comment->setIsHiddenForConnectUsers(false);
        $this->em->persist($comment);
        $this->em->flush();

        $submission = $this->submissions->findOneByTask($task);

        return $this->anonymizer->replyThread([$comment], $submission)[0];
    }

    /**
     * Attach a screenshot to a ticket — stored as an ADMIN-ONLY (hidden) File
     * on the task and linked on the sidecar. Never surfaced on the board.
     */
    public function attachScreenshot(Uuid $id, UploadedFile $upload, User $uploader): void
    {
        $task = $this->requireTicket($id);
        $workspace = $task->getWorkspace();
        $originalName = $upload->getClientOriginalName() ?: 'screenshot.png';

        $file = (new File())
            ->setWorkspace($workspace)
            ->setTarget(FileTarget::Task)
            ->setTargetId($task->getId())
            ->setName($originalName)
            ->setMimeType($upload->getClientMimeType())
            ->setUploadedBy($uploader);
        // ADMIN-ONLY: hidden from portal clients / the anonymized board.
        $file->setIsHiddenForConnectUsers(true);
        $this->em->persist($file);
        $this->em->flush();

        $version = (new FileVersion())
            ->setFile($file)
            ->setVersionNumber(1)
            ->setOriginalFilename($originalName)
            ->setMimeType($upload->getClientMimeType() ?: 'application/octet-stream')
            ->setChecksum('pending')
            ->setStoragePath('pending')
            ->setUploadedBy($uploader);
        $this->em->persist($version);
        $this->em->flush();

        $info = $this->storage->ingestUpload($upload, $workspace, $file->getId(), $version->getId(), $originalName);
        $version->setSize($info['size'])->setChecksum($info['checksum'])->setStoragePath($info['path']);
        $file->setCurrentVersion($version);

        $submission = $this->submissions->findOneByTask($task);
        $submission?->setScreenshotFile($file);
        $this->em->flush();
    }

    /** The admin-only screenshot File for a ticket, or null. */
    public function screenshotFile(Uuid $id): ?File
    {
        $task = $this->requireTicket($id);

        return $this->submissions->findOneByTask($task)?->getScreenshotFile();
    }

    private function requireTicket(Uuid $id): Task
    {
        $task = $this->tasks->findFeedbackTicket($id, $this->locator->feedbackProjectOrFail());
        if ($task === null) {
            throw new NotFoundHttpException('Feedback ticket not found.');
        }

        return $task;
    }

    private function resolveTracker(Workspace $workspace, ?string $categoryKey): ?Tracker
    {
        if ($categoryKey !== null) {
            $tracker = $this->trackerByCategory($workspace, $categoryKey);
            if ($tracker !== null) {
                return $tracker;
            }
        }

        return $this->trackers->findOneBy(['workspace' => $workspace, 'isDefault' => true]);
    }

    private function trackerByCategory(Workspace $workspace, string $categoryKey): ?Tracker
    {
        $icon = self::ICON_BY_CATEGORY[$categoryKey] ?? null;

        return $icon === null ? null : $this->trackers->findOneBy(['workspace' => $workspace, 'icon' => $icon]);
    }

    private function statusByKey(Workspace $workspace, string $statusKey): ?TaskStatus
    {
        foreach ($this->statuses->findBy(['workspace' => $workspace]) as $status) {
            if ($this->statusKey($status->getName()) === $statusKey) {
                return $status;
            }
        }

        return null;
    }

    private function statusKey(string $name): string
    {
        return match ($name) {
            'New' => 'new',
            'Triaged' => 'triaged',
            'Planned' => 'planned',
            'In progress' => 'in_progress',
            'Done' => 'done',
            'Declined' => 'declined',
            default => trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)) ?? $name, '_'),
        };
    }

    private function defaultStatus(Workspace $workspace): TaskStatus
    {
        $default = $this->statuses->findOneBy(['workspace' => $workspace, 'isDefault' => true]);
        if ($default !== null) {
            return $default;
        }
        $first = $this->statuses->findBy(['workspace' => $workspace], ['position' => 'ASC'], 1)[0] ?? null;
        if ($first === null) {
            throw new \RuntimeException('Feedback workspace has no task statuses — run app:feedback:bootstrap.');
        }

        return $first;
    }
}
