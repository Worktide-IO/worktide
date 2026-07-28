<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add DiscoveredExternalRecord.candidates (JSON) — the ambiguous local match
 * candidates for a sync conflict the operator must resolve. Added nullable +
 * backfilled to '[]' + set NOT NULL so it is safe on tables with existing rows.
 */
final class Version20260728095350 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DiscoveredExternalRecord.candidates (JSON) for sync match-conflict review.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discovered_external_records ADD candidates JSON DEFAULT NULL');
        $this->addSql("UPDATE discovered_external_records SET candidates = '[]' WHERE candidates IS NULL");
        $this->addSql('ALTER TABLE discovered_external_records MODIFY candidates JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discovered_external_records DROP candidates');
    }
}
