<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Chat services a user can wire a notification-delivery webhook to. All three
 * accept an "incoming webhook" URL that takes a JSON POST; only the payload
 * shape differs (Slack & Mattermost share it, Teams uses a MessageCard).
 */
enum ChatProvider: string
{
    case Slack = 'slack';
    case Mattermost = 'mattermost';
    case Teams = 'teams';
}
