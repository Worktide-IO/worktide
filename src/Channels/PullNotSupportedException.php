<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * Thrown by inbound adapters that are exclusively push-based — the
 * ChannelRunner cron may safely catch this and skip the channel
 * instead of treating it as a failure.
 */
final class PullNotSupportedException extends \LogicException
{
}
