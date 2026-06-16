<?php

declare(strict_types=1);

namespace App\Channels\OAuth;

/**
 * Thrown when a channel needs OAuth credentials but neither the
 * per-channel override nor the global env-backed default is set.
 *
 * Distinct from a runtime token failure (those are
 * {@see OAuth2TokenException}) so the SPA can route the two cases to
 * different remediations: "ask the admin to register an app" vs.
 * "click reconnect".
 */
final class OAuth2ConfigurationException extends \RuntimeException
{
}
