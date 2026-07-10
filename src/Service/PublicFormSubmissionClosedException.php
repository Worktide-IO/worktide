<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Raised by {@see PublicFormSubmissionService} when a submission cannot be
 * accepted because the form's submission limit has been reached. Thrown from
 * the atomic slot-claim so it is race-safe (two concurrent submits at the
 * boundary: exactly one wins, the loser gets this instead of a 500). Controllers
 * surface it as HTTP 409.
 */
final class PublicFormSubmissionClosedException extends \RuntimeException
{
}
