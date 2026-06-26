<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625235624 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Discovered-import: discovered_external_records table (Phase C.7.6)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE discovered_external_records (entity_type VARCHAR(40) NOT NULL, external_id VARCHAR(200) NOT NULL, external_url VARCHAR(500) DEFAULT NULL, title VARCHAR(250) NOT NULL, fields JSON NOT NULL, participants JSON NOT NULL, state VARCHAR(12) DEFAULT \'pending\' NOT NULL, imported_entity_id BINARY(16) DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, channel_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_E52DD18E72F5A1AA (channel_id), INDEX IDX_E52DD18E7D182D95 (created_by_user_id), INDEX IDX_E52DD18E2793CC5E (updated_by_user_id), INDEX discovered_record_workspace_idx (workspace_id), INDEX discovered_record_state_idx (state), UNIQUE INDEX discovered_record_channel_external_unique (channel_id, external_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE discovered_external_records ADD CONSTRAINT FK_E52DD18E72F5A1AA FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE discovered_external_records ADD CONSTRAINT FK_E52DD18E82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE discovered_external_records ADD CONSTRAINT FK_E52DD18E7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE discovered_external_records ADD CONSTRAINT FK_E52DD18E2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE discovered_external_records DROP FOREIGN KEY FK_E52DD18E72F5A1AA');
        $this->addSql('ALTER TABLE discovered_external_records DROP FOREIGN KEY FK_E52DD18E82D40A1F');
        $this->addSql('ALTER TABLE discovered_external_records DROP FOREIGN KEY FK_E52DD18E7D182D95');
        $this->addSql('ALTER TABLE discovered_external_records DROP FOREIGN KEY FK_E52DD18E2793CC5E');
        $this->addSql('DROP TABLE discovered_external_records');
    }
}
