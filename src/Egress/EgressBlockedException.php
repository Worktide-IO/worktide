<?php

declare(strict_types=1);

namespace App\Egress;

/**
 * Thrown by {@see EgressGuard::assertAllowed()} when an outbound module has not
 * been approved. Outbound call sites catch this and withhold gracefully (leave
 * the work queued for after approval) rather than treating it as a failure.
 */
final class EgressBlockedException extends \RuntimeException
{
    public function __construct(
        public readonly EgressModule $module,
        public readonly ?string $channelId = null,
    ) {
        parent::__construct(sprintf(
            'Egress withheld: module "%s"%s is not approved (EGRESS_ALLOW).',
            $module->value,
            $channelId !== null ? sprintf(' for channel %s', $channelId) : '',
        ));
    }
}
