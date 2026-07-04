<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** What a research mission is trying to achieve — drives prompt + which providers run. */
enum ResearchObjective: string
{
    case LeadGeneration = 'lead_generation';       // find potential customers
    case PartnerSearch = 'partner_search';          // find partner/key-account candidates
    case MarketResearch = 'market_research';        // open-ended market/competitor insight
    case ContentDistribution = 'content_distribution'; // spread reach (e.g. forums)
    case General = 'general';
}
