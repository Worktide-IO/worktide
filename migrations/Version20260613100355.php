<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613100355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE file_versions (version_number INT NOT NULL, original_filename VARCHAR(255) NOT NULL, size BIGINT NOT NULL, mime_type VARCHAR(120) NOT NULL, checksum VARCHAR(64) NOT NULL, storage_path VARCHAR(500) NOT NULL, note LONGTEXT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, file_id BINARY(16) NOT NULL, uploaded_by_id BINARY(16) DEFAULT NULL, INDEX IDX_A88CCF4FA2B28FE8 (uploaded_by_id), INDEX file_version_file_idx (file_id), UNIQUE INDEX file_version_number_unique (file_id, version_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE files (target VARCHAR(16) NOT NULL, target_id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, mime_type VARCHAR(120) DEFAULT NULL, external_provider VARCHAR(60) DEFAULT NULL, external_url VARCHAR(2000) DEFAULT NULL, is_external TINYINT NOT NULL, is_hidden_for_connect_users TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, uploaded_by_id BINARY(16) DEFAULT NULL, current_version_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_6354059A2B28FE8 (uploaded_by_id), INDEX IDX_63540599407EE77 (current_version_id), INDEX IDX_63540597D182D95 (created_by_user_id), INDEX IDX_63540592793CC5E (updated_by_user_id), INDEX file_target_idx (target, target_id), INDEX file_workspace_idx (workspace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE file_versions ADD CONSTRAINT FK_A88CCF4F93CB796C FOREIGN KEY (file_id) REFERENCES files (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE file_versions ADD CONSTRAINT FK_A88CCF4FA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE files ADD CONSTRAINT FK_6354059A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE files ADD CONSTRAINT FK_63540599407EE77 FOREIGN KEY (current_version_id) REFERENCES file_versions (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE files ADD CONSTRAINT FK_635405982D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE files ADD CONSTRAINT FK_63540597D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE files ADD CONSTRAINT FK_63540592793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE file_versions DROP FOREIGN KEY FK_A88CCF4F93CB796C');
        $this->addSql('ALTER TABLE file_versions DROP FOREIGN KEY FK_A88CCF4FA2B28FE8');
        $this->addSql('ALTER TABLE files DROP FOREIGN KEY FK_6354059A2B28FE8');
        $this->addSql('ALTER TABLE files DROP FOREIGN KEY FK_63540599407EE77');
        $this->addSql('ALTER TABLE files DROP FOREIGN KEY FK_635405982D40A1F');
        $this->addSql('ALTER TABLE files DROP FOREIGN KEY FK_63540597D182D95');
        $this->addSql('ALTER TABLE files DROP FOREIGN KEY FK_63540592793CC5E');
        $this->addSql('DROP TABLE file_versions');
        $this->addSql('DROP TABLE files');
    }
}
