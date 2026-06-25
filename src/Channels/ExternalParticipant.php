<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * One person attached to an external ticket — an assignee or a watcher/Mitleser
 * — expressed in whatever identifiers the source system exposes.
 *
 * Adapters build these from the external payload (Jira `assignee.accountId` +
 * `emailAddress`, Redmine `assigned_to.id` + the watchers list) and hand them to
 * {@see \App\Service\Inbound\InboundImportFilter} to decide whether a ticket
 * involves anyone in the workspace. Either identifier may be absent — some
 * systems omit email for privacy; the filter uses whatever is present.
 */
final class ExternalParticipant
{
    public const ROLE_ASSIGNEE = 'assignee';
    public const ROLE_WATCHER = 'watcher';

    public function __construct(
        public readonly ?string $externalUserId = null,
        public readonly ?string $email = null,
        /** ROLE_ASSIGNEE | ROLE_WATCHER — informational, not used for matching. */
        public readonly string $role = self::ROLE_ASSIGNEE,
    ) {}
}
