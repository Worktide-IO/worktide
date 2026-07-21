<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum TagScope: string
{
    case Project = 'project';
    case Task = 'task';
    case Customer = 'customer';

    // CRM & Sales
    case Contact = 'contact';
    case Lead = 'lead';
    case Product = 'product';
    case CustomerSystem = 'customer_system';
    case CustomerAgreement = 'customer_agreement';

    // Knowledge & ideas
    case Document = 'document';
    case Idea = 'idea';
    case BrainstormNote = 'brainstorm_note';
    case File = 'file';
    case SavedReply = 'saved_reply';

    // Communication & marketing
    case Conversation = 'conversation';
    case SocialPost = 'social_post';
    case Newsletter = 'newsletter';
    case NewsletterIssue = 'newsletter_issue';
    case ResearchMission = 'research_mission';
    case CustomerBookmark = 'customer_bookmark';

    case Any = 'any';
}
