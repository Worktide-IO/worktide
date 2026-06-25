<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Raised by {@see PublicFormSubmissionService} when a submission fails
 * field-level validation. Carries a per-field error map the controller
 * surfaces as a 422 body: { errors: { <fieldKey>: <message> } }.
 */
final class PublicFormValidationException extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Public form submission validation failed.');
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
