<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710190118 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Booking free/busy: staff_calendar_connections + calendar_busy_blocks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE calendar_busy_blocks (start_at DATETIME NOT NULL, end_at DATETIME NOT NULL, external_uid VARCHAR(255) DEFAULT NULL, source VARCHAR(16) NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_D320B6AC7E3C61F9 (owner_id), INDEX busy_owner_start_idx (owner_id, start_at), INDEX busy_workspace_idx (workspace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE staff_calendar_connections (ics_url VARCHAR(1000) NOT NULL, is_active TINYINT NOT NULL, last_synced_at DATETIME DEFAULT NULL, last_error VARCHAR(500) DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, owner_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX staff_calendar_workspace_idx (workspace_id), UNIQUE INDEX staff_calendar_user_uniq (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE calendar_busy_blocks ADD CONSTRAINT FK_D320B6AC7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE calendar_busy_blocks ADD CONSTRAINT FK_D320B6AC82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE staff_calendar_connections ADD CONSTRAINT FK_A46A83CE7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE staff_calendar_connections ADD CONSTRAINT FK_A46A83CE82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_busy_blocks DROP FOREIGN KEY FK_D320B6AC7E3C61F9');
        $this->addSql('ALTER TABLE calendar_busy_blocks DROP FOREIGN KEY FK_D320B6AC82D40A1F');
        $this->addSql('ALTER TABLE staff_calendar_connections DROP FOREIGN KEY FK_A46A83CE7E3C61F9');
        $this->addSql('ALTER TABLE staff_calendar_connections DROP FOREIGN KEY FK_A46A83CE82D40A1F');
        $this->addSql('DROP TABLE calendar_busy_blocks');
        $this->addSql('DROP TABLE staff_calendar_connections');
    }
}
