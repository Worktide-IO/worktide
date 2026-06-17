<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260617093429 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE entity_syncs (entity_type VARCHAR(40) NOT NULL, entity_id BINARY(16) NOT NULL, external_id VARCHAR(200) NOT NULL, external_url VARCHAR(500) DEFAULT NULL, external_updated_at DATETIME DEFAULT NULL, our_last_synced_version INT DEFAULT NULL, etag VARCHAR(100) DEFAULT NULL, sync_mode VARCHAR(16) DEFAULT \'bidirectional\' NOT NULL, conflict_policy VARCHAR(24) DEFAULT \'manual\' NOT NULL, last_synced_at DATETIME DEFAULT NULL, last_reconciled_at DATETIME DEFAULT NULL, last_sync_error LONGTEXT DEFAULT NULL, source_metadata JSON NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, channel_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_70649257D182D95 (created_by_user_id), INDEX IDX_70649252793CC5E (updated_by_user_id), INDEX entity_sync_workspace_idx (workspace_id), INDEX entity_sync_entity_idx (entity_type, entity_id), INDEX entity_sync_channel_idx (channel_id), UNIQUE INDEX entity_sync_channel_external_unique (channel_id, external_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE entity_syncs ADD CONSTRAINT FK_706492572F5A1AA FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entity_syncs ADD CONSTRAINT FK_706492582D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entity_syncs ADD CONSTRAINT FK_70649257D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE entity_syncs ADD CONSTRAINT FK_70649252793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE channels ADD entity_types JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE entity_syncs DROP FOREIGN KEY FK_706492572F5A1AA');
        $this->addSql('ALTER TABLE entity_syncs DROP FOREIGN KEY FK_706492582D40A1F');
        $this->addSql('ALTER TABLE entity_syncs DROP FOREIGN KEY FK_70649257D182D95');
        $this->addSql('ALTER TABLE entity_syncs DROP FOREIGN KEY FK_70649252793CC5E');
        $this->addSql('DROP TABLE entity_syncs');
        $this->addSql('ALTER TABLE channels DROP entity_types');
    }
}
