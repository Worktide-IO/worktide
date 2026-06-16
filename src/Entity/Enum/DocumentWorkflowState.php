<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle of a wiki document.
 *
 *   draft     — work in progress; author + contributors can edit. Default.
 *   review    — submitted for review; assigned reviewers can approve or
 *               request changes. Editing is allowed but flagged in the UI.
 *   published — formally approved; appears as the "current" version for
 *               readers. Edits move the document back to draft.
 *
 * Transitions are validated server-side via dedicated endpoints
 * (`/v1/documents/{id}/submit`, `/approve`, `/request-changes`); the
 * `workflowState` field is read-only via the PATCH operation.
 */
enum DocumentWorkflowState: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';
}
