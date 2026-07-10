<?php

declare(strict_types=1);

namespace App\Controller\Api\Concern;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Resolve the caller's workspace for a read-model endpoint: honour an explicit
 * X-Workspace-Id header (or ?workspace=) but ALWAYS membership-check it — never
 * trust a client-supplied workspace id, or a caller could read another tenant's
 * data. Falls back to the caller's first membership when none is given.
 *
 * The using class must expose `private EntityManagerInterface $em`.
 */
trait ResolvesWorkspaceMembership
{
    private function resolveWorkspace(Request $request, User $user): ?Workspace
    {
        $hdr = $request->headers->get('X-Workspace-Id') ?? $request->query->get('workspace');
        if (\is_string($hdr) && $hdr !== '') {
            try {
                $ws = $this->em->find(Workspace::class, Uuid::fromString($hdr));
            } catch (\InvalidArgumentException) {
                return null;
            }
            if ($ws === null || $this->em->getRepository(WorkspaceMember::class)
                    ->findOneBy(['workspace' => $ws, 'user' => $user]) === null) {
                return null;
            }

            return $ws;
        }
        $membership = $this->em->getRepository(WorkspaceMember::class)->findOneBy(['user' => $user]);

        return $membership?->getWorkspace();
    }
}
