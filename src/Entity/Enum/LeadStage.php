<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** Acquisition pipeline stage of a lead (not-yet-customer). */
enum LeadStage: string
{
    case Discovered = 'discovered'; // found by the agent, not yet reviewed
    case Qualified = 'qualified';   // vetted as a real fit
    case Contacted = 'contacted';   // first outreach sent
    case Engaged = 'engaged';       // replied / in conversation
    case Won = 'won';               // converted → Customer
    case Lost = 'lost';             // declined / disqualified
    case OnHold = 'on_hold';
}
