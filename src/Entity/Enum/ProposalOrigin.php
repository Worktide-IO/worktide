<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Where a {@see \App\Entity\ProjectProposal} came from — drives the portal
 * source label ("KI-Vorschlag" / "von der Agentur").
 */
enum ProposalOrigin: string
{
    case Ai = 'ai';
    case Agency = 'agency';
}
