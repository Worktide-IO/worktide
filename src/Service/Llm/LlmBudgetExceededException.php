<?php

declare(strict_types=1);

namespace App\Service\Llm;

/**
 * Thrown by a provider when the caller's workspace has reached its configured
 * monthly AI budget. Extends {@see LlmException} so existing callers surface it
 * as a clean failure; nothing is sent to the model (checked before the API call),
 * so no spend occurs over budget.
 */
final class LlmBudgetExceededException extends LlmException
{
}
