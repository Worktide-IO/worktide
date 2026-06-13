<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Access level a DocumentContributor has on a Document.
 *
 *   Read   — can VIEW the document (read body + comments)
 *   Manage — can EDIT the document content + invite further contributors
 *
 * Matches awork's "read | manage" pair. No granular "comment-only" yet —
 * read implies comment in our voter.
 */
enum DocumentAccess: string
{
    case Read = 'read';
    case Manage = 'manage';
}
