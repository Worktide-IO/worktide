<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nextcloud-like folder tree: new `folders` table (polymorphic like `files`,
 * with a self-referential parent) plus a `files.folder_id` FK. Pre-existing
 * ambient schema drift on unrelated tables was deliberately excluded and
 * belongs in a separate cleanup migration.
 */
final class Version20260712011653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add folders table (file tree) + files.folder_id for Nextcloud-like customer file sharing.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE folders (target VARCHAR(16) NOT NULL, target_id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, is_hidden_for_connect_users TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, parent_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_FE37D30F727ACA70 (parent_id), INDEX IDX_FE37D30F7D182D95 (created_by_user_id), INDEX IDX_FE37D30F2793CC5E (updated_by_user_id), INDEX folder_target_idx (target, target_id), INDEX folder_workspace_idx (workspace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE folders ADD CONSTRAINT FK_FE37D30F727ACA70 FOREIGN KEY (parent_id) REFERENCES folders (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE folders ADD CONSTRAINT FK_FE37D30F82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE folders ADD CONSTRAINT FK_FE37D30F7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE folders ADD CONSTRAINT FK_FE37D30F2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE files ADD folder_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE files ADD CONSTRAINT FK_6354059162CB942 FOREIGN KEY (folder_id) REFERENCES folders (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6354059162CB942 ON files (folder_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE files DROP FOREIGN KEY FK_6354059162CB942');
        $this->addSql('DROP INDEX IDX_6354059162CB942 ON files');
        $this->addSql('ALTER TABLE files DROP folder_id');
        $this->addSql('ALTER TABLE folders DROP FOREIGN KEY FK_FE37D30F727ACA70');
        $this->addSql('ALTER TABLE folders DROP FOREIGN KEY FK_FE37D30F82D40A1F');
        $this->addSql('ALTER TABLE folders DROP FOREIGN KEY FK_FE37D30F7D182D95');
        $this->addSql('ALTER TABLE folders DROP FOREIGN KEY FK_FE37D30F2793CC5E');
        $this->addSql('DROP TABLE folders');
    }
}
