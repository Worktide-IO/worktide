<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * Thrown when a Channel.adapterCode value has no registered
 * concrete adapter — typically a config-row that pointed at an
 * adapter which was removed, or a typo in the channel-creation API.
 */
final class UnknownAdapterException extends \RuntimeException
{
}
