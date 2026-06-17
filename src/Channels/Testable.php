<?php

declare(strict_types=1);

namespace App\Channels;

use App\Entity\Channel;

/**
 * Optional capability that adapters can advertise — when implemented,
 * the SPA exposes a "Test connection" button on the channel's config
 * form. The endpoint never mutates the channel; it just returns a
 * verdict the user can act on.
 *
 * Implementations should be SHORT (under 5 seconds) and side-effect
 * free. A connection test that creates an InboundEvent or sends a
 * real outbound message is not a test, it's a side-effect — return
 * Failed instead.
 */
interface Testable
{
    public function selfTest(Channel $channel): TestResult;
}
