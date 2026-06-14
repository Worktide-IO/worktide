<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260614101034 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sweep — TimeTrackingSettings (rounding/min/lock policy)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE time_tracking_settings (rounding_minutes INT NOT NULL, minimum_minutes INT NOT NULL, lock_after_days INT DEFAULT NULL, allow_future_entries TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_6C7A79257D182D95 (created_by_user_id), INDEX IDX_6C7A79252793CC5E (updated_by_user_id), UNIQUE INDEX time_tracking_settings_workspace_unique (workspace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE time_tracking_settings ADD CONSTRAINT FK_6C7A792582D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE time_tracking_settings ADD CONSTRAINT FK_6C7A79257D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE time_tracking_settings ADD CONSTRAINT FK_6C7A79252793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE time_tracking_settings DROP FOREIGN KEY FK_6C7A792582D40A1F');
        $this->addSql('ALTER TABLE time_tracking_settings DROP FOREIGN KEY FK_6C7A79257D182D95');
        $this->addSql('ALTER TABLE time_tracking_settings DROP FOREIGN KEY FK_6C7A79252793CC5E');
        $this->addSql('DROP TABLE time_tracking_settings');
    }
}
