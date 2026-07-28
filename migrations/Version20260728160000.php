<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add channels.sync_started_at (nullable) — marks an in-progress sync for the
 * "läuft…" UI state + concurrency guard. Nullable, so safe on existing rows.
 */
final class Version20260728160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'channels.sync_started_at for in-progress sync state + concurrency guard.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE channels ADD sync_started_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channels DROP sync_started_at');
    }
}
