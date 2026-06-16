<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * Thrown by inbound adapters that are exclusively pull-based — the
 * webhook controller responds with 405 (or 400) when a push hits a
 * channel that can't receive one.
 */
final class WebhookNotSupportedException extends \LogicException
{
}
