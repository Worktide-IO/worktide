<?php

declare(strict_types=1);

namespace App\Service\Portal;

use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ContactRepository;
use App\Repository\TaskRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * The single authorization choke-point for the customer portal.
 *
 * Portal users are external CRM contacts with their own {@see User}
 * (ROLE_PORTAL, NOT a WorkspaceMember). Because they are not workspace
 * members, {@see \App\ApiPlatform\Doctrine\WorkspaceScopeExtension} would
 * zero out every standard collection for them — which is why the portal has
 * its own `^/v1/portal/*` endpoints that build their own scoped queries and
 * route every read/write through this resolver.
 *
 * Identity chain: authenticated User → Contact (via linkedUser) → Customer →
 * the customer's `isExternal` projects → the tasks/comments within them that
 * are not `isHiddenForConnectUsers`.
 *
 * Every method here fails closed: no contact, disabled portal, or a task
 * outside the allowed set raises 403/404 rather than leaking data.
 */
final class PortalAccessResolver
{
    public function __construct(
        private readonly Security $security,
        private readonly ContactRepository $contacts,
        private readonly TaskRepository $tasks,
    ) {}

    /**
     * The Contact behind the current portal login. 403 if the token does not
     * resolve to a portal-linked contact (defense in depth: a still-valid JWT
     * whose contact was unlinked by a revoke also dies here, regardless of the
     * roles baked into the token).
     */
    public function contact(): Contact
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Not a portal user.');
        }
        $contact = $this->contacts->findOneBy(['linkedUser' => $user]);
        if (!$contact instanceof Contact) {
            throw new AccessDeniedHttpException('No portal access for this account.');
        }
        return $contact;
    }

    public function customer(): Customer
    {
        return $this->contact()->getCustomer();
    }

    public function workspace(): Workspace
    {
        return $this->customer()->getWorkspace();
    }

    /**
     * The portal feature must be explicitly enabled per workspace
     * (`settings.portal.enabled === true`). Absent/false locks the portal.
     */
    public function assertPortalEnabled(): void
    {
        if (!self::isPortalEnabled($this->workspace())) {
            throw new AccessDeniedHttpException('Portal is not enabled for this workspace.');
        }
    }

    public static function isPortalEnabled(Workspace $workspace): bool
    {
        return ($workspace->getSettings()['portal']['enabled'] ?? false) === true;
    }

    /**
     * The projects a portal contact may see: their customer's external,
     * non-archived projects.
     *
     * @return list<Project>
     */
    public function allowedProjects(): array
    {
        $projects = [];
        foreach ($this->customer()->getProjects() as $project) {
            if ($project->isExternal() && !$project->isArchived()) {
                $projects[] = $project;
            }
        }
        return $projects;
    }

    /**
     * Load a single ticket the contact is allowed to see, or 404.
     *
     * A ticket is a {@see Task} that (a) lives in one of the allowed projects
     * and (b) is not `isHiddenForConnectUsers`. NOTE: the hidden flag is
     * enforced by NOTHING else in the codebase for tasks (unlike Comment/File),
     * so this explicit check is security-critical — never remove it.
     */
    public function findTicketOr404(Uuid $id): Task
    {
        $task = $this->tasks->find($id);
        if (!$task instanceof Task || $task->isHiddenForConnectUsers()) {
            throw new NotFoundHttpException('Ticket not found.');
        }
        $project = $task->getProject();
        if ($project === null || !$this->isAllowedProject($project)) {
            throw new NotFoundHttpException('Ticket not found.');
        }
        return $task;
    }

    private function isAllowedProject(Project $project): bool
    {
        foreach ($this->allowedProjects() as $allowed) {
            if ($allowed->getId()?->equals($project->getId()) === true) {
                return true;
            }
        }
        return false;
    }
}
