<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Holds an opaque foreign identifier from an external system this entity
 * was synced from or imported from (e.g. Hubspot contact id, Jira issue key,
 * Asana task gid, awork task id).
 *
 * The pair (externalSource, externalId) must be unique within a workspace
 * so we never sync the same external record twice. Source is a stable string
 * like "hubspot", "jira", "asana", "awork", "csv-import-2026-06-12".
 */
trait ExternalReferenceTrait
{
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $externalSource = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $externalId = null;

    public function getExternalSource(): ?string
    {
        return $this->externalSource;
    }

    public function setExternalSource(?string $source): self
    {
        $this->externalSource = $source;
        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $id): self
    {
        $this->externalId = $id;
        return $this;
    }

    public function getExternalRef(): ?string
    {
        if ($this->externalSource === null || $this->externalId === null) {
            return null;
        }
        return $this->externalSource . ':' . $this->externalId;
    }
}
