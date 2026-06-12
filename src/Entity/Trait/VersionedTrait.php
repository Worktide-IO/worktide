<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Adds an optimistic-lock version column.
 *
 * Doctrine increments `version` on every UPDATE and raises an OptimisticLockException
 * if the value in the request doesn't match the value in the DB — preventing the
 * classic lost-update problem when two users edit the same entity simultaneously.
 */
trait VersionedTrait
{
    #[ORM\Version]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    public function getVersion(): int
    {
        return $this->version;
    }
}
