<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Project;
use App\Entity\Team;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
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
 * Team membership & project assignment endpoints, matching awork's
 * post-team-add-users / add-projects / remove-users / remove-projects.
 *
 *   POST /v1/teams/{id}/add-users        { userIds: [uuid, ...] }
 *   POST /v1/teams/{id}/remove-users     { userIds: [uuid, ...] }
 *   POST /v1/teams/{id}/add-projects     { projectIds: [uuid, ...] }
 *   POST /v1/teams/{id}/remove-projects  { projectIds: [uuid, ...] }
 *
 * Cross-workspace items are silently skipped (counted) — never moved.
 */
final class TeamActionsController
{
    public function __construct(
        private readonly Security $security,
        private readonly TeamRepository $teams,
        private readonly UserRepository $users,
        private readonly ProjectRepository $projects,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/v1/teams/{id}/add-users', name: 'api_team_add_users', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function addUsers(string $id, Request $request): JsonResponse
    {
        return $this->mutateMembers($id, $request, add: true);
    }

    #[Route('/v1/teams/{id}/remove-users', name: 'api_team_remove_users', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function removeUsers(string $id, Request $request): JsonResponse
    {
        return $this->mutateMembers($id, $request, add: false);
    }

    #[Route('/v1/teams/{id}/add-projects', name: 'api_team_add_projects', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function addProjects(string $id, Request $request): JsonResponse
    {
        return $this->mutateProjects($id, $request, add: true);
    }

    #[Route('/v1/teams/{id}/remove-projects', name: 'api_team_remove_projects', host: 'api.worktide.ddev.site', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function removeProjects(string $id, Request $request): JsonResponse
    {
        return $this->mutateProjects($id, $request, add: false);
    }

    private function mutateMembers(string $id, Request $request, bool $add): JsonResponse
    {
        $team = $this->ownedTeam($id);
        $userIds = $this->extractUuids($request, 'userIds');
        $changed = 0;
        $skipped = 0;
        foreach ($userIds as $uid) {
            $user = $this->users->find($uid);
            if (!$user instanceof User) {
                $skipped++;
                continue;
            }
            if ($add) {
                $team->addMember($user);
            } else {
                $team->removeMember($user);
            }
            $changed++;
        }
        $this->em->flush();
        return new JsonResponse([
            'teamId' => $team->getId()?->toRfc4122(),
            ($add ? 'added' : 'removed') => $changed,
            'skipped' => $skipped,
        ]);
    }

    private function mutateProjects(string $id, Request $request, bool $add): JsonResponse
    {
        $team = $this->ownedTeam($id);
        $projectIds = $this->extractUuids($request, 'projectIds');
        $changed = 0;
        $skipped = 0;
        foreach ($projectIds as $pid) {
            $project = $this->projects->find($pid);
            if (!$project instanceof Project || $project->getWorkspace() !== $team->getWorkspace()) {
                $skipped++;
                continue;
            }
            if ($add) {
                $team->addProject($project);
            } else {
                $team->removeProject($project);
            }
            $changed++;
        }
        $this->em->flush();
        return new JsonResponse([
            'teamId' => $team->getId()?->toRfc4122(),
            ($add ? 'added' : 'removed') => $changed,
            'skipped' => $skipped,
        ]);
    }

    private function ownedTeam(string $id): Team
    {
        $team = $this->teams->find(Uuid::fromString($id));
        if (!$team instanceof Team) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $team->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }
        return $team;
    }

    /** @return list<Uuid> */
    private function extractUuids(Request $request, string $field): array
    {
        $raw = (string) $request->getContent();
        if ($raw === '') {
            throw new BadRequestHttpException('Body required.');
        }
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded) || !isset($decoded[$field]) || !\is_array($decoded[$field])) {
            throw new BadRequestHttpException(sprintf('Field %s[] required.', $field));
        }
        $out = [];
        foreach ($decoded[$field] as $r) {
            if (!\is_string($r)) {
                continue;
            }
            try {
                $out[] = Uuid::fromString($r);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }
        return $out;
    }
}
