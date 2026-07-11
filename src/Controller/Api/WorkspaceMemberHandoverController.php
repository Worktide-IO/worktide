<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\TaskAssignee;
use App\Entity\User;
use App\Entity\WorkspaceMember;
use App\Repository\TaskAssigneeRepository;
use App\Repository\WorkspaceMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Removing a workspace member must not orphan their work. Before the membership
 * is deleted, the member's task assignments in that workspace are handed over to
 * another (still active) member — or cleared, if the caller chooses.
 *
 *   GET  /v1/workspace_members/{id}/assignments   → { assignedTaskCount }
 *   POST /v1/workspace_members/{id}/remove         { reassignTo?: <userUuid> }
 *
 * `createdBy` / `closedBy` are audit history and are left untouched (transferring
 * them would falsify who created/closed a task). Both routes need MANAGE on the
 * member's workspace.
 */
final class WorkspaceMemberHandoverController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly TaskAssigneeRepository $taskAssignees,
        private readonly WorkspaceMemberRepository $wsMembers,
    ) {}

    #[Route(
        path: '/v1/workspace_members/{id}/assignments',
        name: 'api_workspace_member_assignments',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function assignments(string $id): JsonResponse
    {
        $member = $this->requireManagedMember($id);
        $count = \count($this->taskAssignees->findUserAssignmentsInWorkspace(
            $member->getWorkspace(),
            $this->userId($member),
        ));

        return new JsonResponse(['assignedTaskCount' => $count]);
    }

    #[Route(
        path: '/v1/workspace_members/{id}/remove',
        name: 'api_workspace_member_remove',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function remove(string $id, Request $request): JsonResponse
    {
        $member = $this->requireManagedMember($id);
        $workspace = $member->getWorkspace();
        $leavingId = $this->userId($member);

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($body)) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }

        // Resolve + validate the reassignment target (optional).
        $target = null;
        $reassignTo = $body['reassignTo'] ?? null;
        if ($reassignTo !== null && $reassignTo !== '') {
            if (!is_string($reassignTo)) {
                throw new BadRequestHttpException('reassignTo must be a user id.');
            }
            try {
                $targetUuid = Uuid::fromString($reassignTo);
            } catch (\InvalidArgumentException) {
                throw new BadRequestHttpException('Invalid reassignTo id.');
            }
            if ($targetUuid->equals($leavingId)) {
                throw new BadRequestHttpException('Cannot reassign to the member being removed.');
            }
            $target = $this->em->find(User::class, $targetUuid);
            if ($target === null
                || $this->wsMembers->findOneBy(['workspace' => $workspace, 'user' => $target, 'isActive' => true]) === null
            ) {
                throw new BadRequestHttpException('reassignTo must be an active member of this workspace.');
            }
        }

        // Hand over (or clear) every task assignment the leaving user holds here.
        $assignments = $this->taskAssignees->findUserAssignmentsInWorkspace($workspace, $leavingId);
        $reassigned = 0;
        foreach ($assignments as $ta) {
            $task = $ta->getTask();
            if ($target !== null) {
                $exists = $this->taskAssignees->findOneByPrincipal($task, AssigneePrincipalType::User, $targetUuid);
                if ($exists === null) {
                    $task->addAssignedPrincipal(
                        (new TaskAssignee())
                            ->setTask($task)
                            ->setPrincipalType(AssigneePrincipalType::User)
                            ->setPrincipalId($targetUuid),
                    );
                }
            }
            $task->removeAssignedPrincipal($ta);
            $reassigned++;
        }

        $this->em->remove($member);
        $this->em->flush();

        return new JsonResponse([
            'removed' => true,
            'reassignedTasks' => $reassigned,
            'reassignedTo' => $target?->getId()?->toRfc4122(),
        ]);
    }

    private function requireManagedMember(string $id): WorkspaceMember
    {
        if (!$this->security->getUser() instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }
        try {
            $member = $this->em->find(WorkspaceMember::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid member id.');
        }
        if ($member === null) {
            throw new NotFoundHttpException('Member not found.');
        }
        if (!$this->security->isGranted('MANAGE', $member->getWorkspace())) {
            throw new AccessDeniedHttpException('You cannot manage this workspace.');
        }

        return $member;
    }

    private function userId(WorkspaceMember $member): Uuid
    {
        $uid = $member->getUser()->getId();
        if ($uid === null) {
            throw new NotFoundHttpException('Member has no user.');
        }

        return $uid;
    }
}
