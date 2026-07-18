<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Category of a {@see \App\Entity\ContactPhone} number. "Mobile" is kept as its
 * own category (not just "business/private") because the legacy Contact.mobile
 * column mirrors it, and agencies routinely store a separate cell number.
 */
enum PhoneCategory: string
{
    case Business = 'business';
    case Private = 'private';
    case Mobile = 'mobile';
    case Fax = 'fax';
}
