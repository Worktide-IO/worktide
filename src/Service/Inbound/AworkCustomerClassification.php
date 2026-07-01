<?php

declare(strict_types=1);

namespace App\Service\Inbound;

/**
 * Result of classifying one flat awork "company" record into the real
 * company→contact hierarchy. See {@see AworkCustomerClassifier}.
 */
final class AworkCustomerClassification
{
    public const COMPANY = 'company';   // the record is a company (Customer, isCompany=true)
    public const PERSON = 'person';     // a private person (Customer, isCompany=false)
    public const CONTACT = 'contact';   // an Ansprechpartner OF {companyName}
    public const IGNORE = 'ignore';     // skip entirely (e.g. our own agency)

    public function __construct(
        public readonly string $kind,
        /** Company name — the display name for COMPANY, the parent firm for CONTACT. */
        public readonly ?string $companyName = null,
        public readonly string $firstName = '',
        public readonly string $lastName = '',
    ) {}
}
