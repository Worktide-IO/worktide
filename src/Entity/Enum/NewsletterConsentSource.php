<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Where a newsletter opt-in originated — the audit trail for consent. `Portal`
 * is a contact self-subscribing in the customer portal; `Staff` is an internal
 * user enabling it on the contact's behalf; `Import`/`Api` cover migrated or
 * programmatic sign-ups.
 */
enum NewsletterConsentSource: string
{
    case Portal = 'portal';
    case Staff = 'staff';
    case Import = 'import';
    case Api = 'api';
}
