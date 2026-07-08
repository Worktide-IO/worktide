<?php

declare(strict_types=1);

namespace App\Channels\Adapter\EmailGraph;

/**
 * Thrown when Graph reports a subscription as 404/410 during renew — it has
 * expired or been deleted upstream and must be recreated rather than patched.
 */
final class GraphSubscriptionGoneException extends \RuntimeException
{
}
