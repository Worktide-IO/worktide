<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add TimeTrackingSettings.autoStopMinutes — per-workspace limit after which a
 * running timer is auto-stopped (null = disabled).
 */
final class Version20260713201029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add auto_stop_minutes to time_tracking_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_tracking_settings ADD auto_stop_minutes INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_tracking_settings DROP auto_stop_minutes');
    }
}
