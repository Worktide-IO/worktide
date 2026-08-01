<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add channels.revenue_synced_at (nullable) — lets LexofficeAdapter throttle
 * the revenue sub-sync independently of the 2-minute channel:pull cadence
 * (see Channel::REVENUE_SYNC_INTERVAL_MINUTES).
 */
final class Version20260801220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'channels.revenue_synced_at to throttle lexoffice revenue re-sync.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE channels ADD revenue_synced_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channels DROP revenue_synced_at');
    }
}
