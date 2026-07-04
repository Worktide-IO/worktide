<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Materialize the internal priority score on tasks (sortable/filterable,
 * shipped in the row payload). Written by worktide:priority:recompute.
 */
final class Version20260704130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add priority_score columns to tasks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tasks ADD priority_score INT DEFAULT NULL, ADD priority_score_blocked TINYINT(1) DEFAULT 0 NOT NULL, ADD priority_score_parts JSON DEFAULT NULL, ADD priority_score_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tasks DROP priority_score, DROP priority_score_blocked, DROP priority_score_parts, DROP priority_score_at');
    }
}
