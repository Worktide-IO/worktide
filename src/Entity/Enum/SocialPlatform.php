<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Platform of a {@see \App\Entity\SocialProfile}. "Other" carries a free-text
 * label on the profile itself for anything not in the common set.
 */
enum SocialPlatform: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case LinkedIn = 'linkedin';
    case X = 'x';
    case YouTube = 'youtube';
    case Xing = 'xing';
    case GitHub = 'github';
    case Mastodon = 'mastodon';
    case Website = 'website';
    case Other = 'other';
}
