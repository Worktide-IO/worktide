<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Channels\ExternalParticipant;
use App\Entity\Channel;
use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\WorkspaceMemberRepository;

/**
 * Decides whether an external ticket is "addressed to someone in this workspace"
 * — the relevance rule that keeps a ticket-system connection from sucking in
 * entire foreign projects (ROADMAP: Phase C Schicht 5).
 *
 * A ticket is relevant when at least one of its participants (assignee or
 * watcher/Mitleser) resolves to a workspace member. Resolution is two-stage:
 *
 *   1. explicit {@see \App\Entity\ExternalIdentity} mapping on the channel
 *      (external account id → Worktide user), then
 *   2. email match against the channel workspace's members.
 *
 * This is the shared filter the future discovered-import path (C.7.6) and any
 * adapter-side pre-filter both call, so backfill and live webhooks filter
 * identically. It is intentionally side-effect free — pure resolution, no
 * persistence — so callers decide what to do with an irrelevant ticket
 * (skip vs. mark Dismissed).
 */
final class InboundImportFilter
{
    public function __construct(
        private readonly ExternalIdentityRepository $identities,
        private readonly WorkspaceMemberRepository $members,
    ) {}

    /**
     * True when any participant maps to a member of the channel's workspace.
     *
     * @param iterable<ExternalParticipant> $participants
     */
    public function isRelevant(Channel $channel, iterable $participants): bool
    {
        foreach ($participants as $participant) {
            if ($this->resolveUser($channel, $participant) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve one external participant to a Worktide user, or null. Explicit
     * channel mapping wins; email match against workspace members is the
     * fallback for participants nobody mapped yet.
     */
    public function resolveUser(Channel $channel, ExternalParticipant $participant): ?User
    {
        if ($participant->externalUserId !== null && $participant->externalUserId !== '') {
            $user = $this->identities->findUserByExternalUserId($channel, $participant->externalUserId);
            if ($user !== null) {
                return $user;
            }
        }

        if ($participant->email !== null && $participant->email !== '') {
            $member = $this->members->findByWorkspaceAndEmail($channel->getWorkspace(), $participant->email);
            if ($member !== null) {
                return $member->getUser();
            }
        }

        return null;
    }
}
