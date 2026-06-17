<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * Verdict of {@see Testable::selfTest()}. Three states because the
 * binary OK / NOT-OK is too crude for the operator's troubleshooting
 * loop — "almost works" is the most common failure mode (auth OK but
 * folder missing; OAuth OK but missing scope; webhook token valid
 * but channel not enabled).
 */
final class TestResult
{
    /**
     * @param array<string, mixed> $detail
     */
    public function __construct(
        public readonly string $status,        // 'ok' | 'warning' | 'failed'
        public readonly string $message,
        public readonly array $detail = [],
    ) {}

    public static function ok(string $message = 'OK', array $detail = []): self
    {
        return new self('ok', $message, $detail);
    }

    public static function warning(string $message, array $detail = []): self
    {
        return new self('warning', $message, $detail);
    }

    public static function failed(string $message, array $detail = []): self
    {
        return new self('failed', $message, $detail);
    }
}
