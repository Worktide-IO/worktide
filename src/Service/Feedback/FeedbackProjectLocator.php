<?php

declare(strict_types=1);

namespace App\Service\Feedback;

use App\Entity\Project;
use App\Entity\Workspace;
use App\Repository\ProjectRepository;
use App\Repository\WorkspaceRepository;

/**
 * Resolves the shared, cross-tenant feedback project (see
 * docs/feedback-board-plan.md) by well-known markers — a slug/key, NOT an
 * env-injected UUID (so it survives DB dumps/restores and needs no deploy
 * coupling).
 *
 * The platform workspace + feedback project are seeded idempotently by
 * {@see \App\Command\FeedbackBootstrapCommand} (run on every deploy via the
 * docker entrypoint). Resolution is memoized per request.
 *
 * These plain repository lookups are NOT touched by
 * {@see \App\ApiPlatform\Doctrine\WorkspaceScopeExtension} (that only rewrites
 * API-Platform ORM operations), which is exactly why the feedback board can
 * read across tenants safely through curated controllers.
 */
final class FeedbackProjectLocator
{
    public const PLATFORM_WORKSPACE_SLUG = 'worktide-platform';
    public const FEEDBACK_PROJECT_KEY = 'WTFB';

    private ?Workspace $workspace = null;
    private bool $workspaceResolved = false;

    private ?Project $project = null;
    private bool $projectResolved = false;

    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly ProjectRepository $projects,
    ) {}

    public function platformWorkspace(): ?Workspace
    {
        if (!$this->workspaceResolved) {
            $this->workspace = $this->workspaces->findOneBy(['slug' => self::PLATFORM_WORKSPACE_SLUG]);
            $this->workspaceResolved = true;
        }

        return $this->workspace;
    }

    public function feedbackProject(): ?Project
    {
        if (!$this->projectResolved) {
            $workspace = $this->platformWorkspace();
            $this->project = $workspace === null
                ? null
                : $this->projects->findOneBy(['workspace' => $workspace, 'key' => self::FEEDBACK_PROJECT_KEY]);
            $this->projectResolved = true;
        }

        return $this->project;
    }

    /**
     * The feedback project or a hard failure — for controllers that cannot
     * function without it (it is guaranteed to exist post-bootstrap).
     */
    public function feedbackProjectOrFail(): Project
    {
        $project = $this->feedbackProject();
        if ($project === null) {
            throw new \RuntimeException('Feedback project is not bootstrapped. Run app:feedback:bootstrap.');
        }

        return $project;
    }

    public function isFeedbackProject(?Project $project): bool
    {
        $target = $this->feedbackProject();

        return $project !== null
            && $target !== null
            && $project->getId()?->equals($target->getId()) === true;
    }
}
