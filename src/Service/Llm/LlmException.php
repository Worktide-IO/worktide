<?php

declare(strict_types=1);

namespace App\Service\Llm;

/**
 * Raised when an LLM completion cannot be produced — provider not configured,
 * transport/API failure, a model refusal, or an empty response. Callers turn
 * this into a clean HTTP error rather than leaking provider internals.
 *
 * Not final: {@see LlmBudgetExceededException} specialises it so a budget stop
 * is caught by the same `catch (LlmException)` sites but can be told apart.
 */
class LlmException extends \RuntimeException
{
}
