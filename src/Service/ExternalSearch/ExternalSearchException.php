<?php

declare(strict_types=1);

namespace App\Service\ExternalSearch;

/** Thrown on egress denial or a transport/API failure of an external-search provider. */
final class ExternalSearchException extends \RuntimeException
{
}
