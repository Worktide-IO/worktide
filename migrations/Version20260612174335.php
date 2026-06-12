<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260612174335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE custom_field_definitions (target VARCHAR(24) NOT NULL, type VARCHAR(16) NOT NULL, field_key VARCHAR(60) NOT NULL, label VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, options JSON NOT NULL, is_required TINYINT NOT NULL, is_archived TINYINT NOT NULL, position INT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_A496729882D40A1F (workspace_id), INDEX IDX_A49672987D182D95 (created_by_user_id), INDEX IDX_A49672982793CC5E (updated_by_user_id), UNIQUE INDEX custom_field_workspace_target_key_unique (workspace_id, target, field_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE custom_field_values (target VARCHAR(24) NOT NULL, target_id BINARY(16) NOT NULL, value JSON NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, definition_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_6B64D7FFD11EA911 (definition_id), INDEX IDX_6B64D7FF7D182D95 (created_by_user_id), INDEX IDX_6B64D7FF2793CC5E (updated_by_user_id), INDEX cfv_target_idx (target, target_id), INDEX cfv_workspace_idx (workspace_id), UNIQUE INDEX cfv_definition_target_unique (definition_id, target_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE custom_field_definitions ADD CONSTRAINT FK_A496729882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE custom_field_definitions ADD CONSTRAINT FK_A49672987D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE custom_field_definitions ADD CONSTRAINT FK_A49672982793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE custom_field_values ADD CONSTRAINT FK_6B64D7FFD11EA911 FOREIGN KEY (definition_id) REFERENCES custom_field_definitions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE custom_field_values ADD CONSTRAINT FK_6B64D7FF82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE custom_field_values ADD CONSTRAINT FK_6B64D7FF7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE custom_field_values ADD CONSTRAINT FK_6B64D7FF2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_members ADD created_by_user_id BINARY(16) DEFAULT NULL, ADD updated_by_user_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE project_members ADD CONSTRAINT FK_D3BEDE9A7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_members ADD CONSTRAINT FK_D3BEDE9A2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D3BEDE9A7D182D95 ON project_members (created_by_user_id)');
        $this->addSql('CREATE INDEX IDX_D3BEDE9A2793CC5E ON project_members (updated_by_user_id)');
        $this->addSql('ALTER TABLE project_statuses ADD version INT DEFAULT 1 NOT NULL, ADD external_source VARCHAR(60) DEFAULT NULL, ADD external_id VARCHAR(200) DEFAULT NULL, ADD created_by_user_id BINARY(16) DEFAULT NULL, ADD updated_by_user_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE project_statuses ADD CONSTRAINT FK_76BE95CA7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_statuses ADD CONSTRAINT FK_76BE95CA2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_76BE95CA7D182D95 ON project_statuses (created_by_user_id)');
        $this->addSql('CREATE INDEX IDX_76BE95CA2793CC5E ON project_statuses (updated_by_user_id)');
        $this->addSql('ALTER TABLE projects ADD version INT DEFAULT 1 NOT NULL, ADD external_source VARCHAR(60) DEFAULT NULL, ADD external_id VARCHAR(200) DEFAULT NULL, ADD created_by_user_id BINARY(16) DEFAULT NULL, ADD updated_by_user_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE projects ADD CONSTRAINT FK_5C93B3A47D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE projects ADD CONSTRAINT FK_5C93B3A42793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5C93B3A47D182D95 ON projects (created_by_user_id)');
        $this->addSql('CREATE INDEX IDX_5C93B3A42793CC5E ON projects (updated_by_user_id)');
        $this->addSql('ALTER TABLE tags ADD version INT DEFAULT 1 NOT NULL, ADD external_source VARCHAR(60) DEFAULT NULL, ADD external_id VARCHAR(200) DEFAULT NULL, ADD created_by_user_id BINARY(16) DEFAULT NULL, ADD updated_by_user_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE tags ADD CONSTRAINT FK_6FBC94267D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tags ADD CONSTRAINT FK_6FBC94262793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6FBC94267D182D95 ON tags (created_by_user_id)');
        $this->addSql('CREATE INDEX IDX_6FBC94262793CC5E ON tags (updated_by_user_id)');
        $this->addSql('ALTER TABLE task_statuses ADD version INT DEFAULT 1 NOT NULL, ADD external_source VARCHAR(60) DEFAULT NULL, ADD external_id VARCHAR(200) DEFAULT NULL, ADD created_by_user_id BINARY(16) DEFAULT NULL, ADD updated_by_user_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE task_statuses ADD CONSTRAINT FK_B30F45D7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_statuses ADD CONSTRAINT FK_B30F45D2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B30F45D7D182D95 ON task_statuses (created_by_user_id)');
        $this->addSql('CREATE INDEX IDX_B30F45D2793CC5E ON task_statuses (updated_by_user_id)');
        $this->addSql('ALTER TABLE tasks ADD version INT DEFAULT 1 NOT NULL, ADD external_source VARCHAR(60) DEFAULT NULL, ADD external_id VARCHAR(200) DEFAULT NULL, ADD created_by_user_id BINARY(16) DEFAULT NULL, ADD updated_by_user_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_505865977D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_505865972793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_505865977D182D95 ON tasks (created_by_user_id)');
        $this->addSql('CREATE INDEX IDX_505865972793CC5E ON tasks (updated_by_user_id)');
        $this->addSql('ALTER TABLE time_entries ADD version INT DEFAULT 1 NOT NULL, ADD external_source VARCHAR(60) DEFAULT NULL, ADD external_id VARCHAR(200) DEFAULT NULL, ADD created_by_user_id BINARY(16) DEFAULT NULL, ADD updated_by_user_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE time_entries ADD CONSTRAINT FK_797F12A37D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE time_entries ADD CONSTRAINT FK_797F12A32793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_797F12A37D182D95 ON time_entries (created_by_user_id)');
        $this->addSql('CREATE INDEX IDX_797F12A32793CC5E ON time_entries (updated_by_user_id)');
        $this->addSql('ALTER TABLE users ADD version INT DEFAULT 1 NOT NULL, ADD external_source VARCHAR(60) DEFAULT NULL, ADD external_id VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE workspace_members ADD created_by_user_id BINARY(16) DEFAULT NULL, ADD updated_by_user_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE workspace_members ADD CONSTRAINT FK_9D9D39F47D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workspace_members ADD CONSTRAINT FK_9D9D39F42793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9D9D39F47D182D95 ON workspace_members (created_by_user_id)');
        $this->addSql('CREATE INDEX IDX_9D9D39F42793CC5E ON workspace_members (updated_by_user_id)');
        $this->addSql('ALTER TABLE workspaces ADD version INT DEFAULT 1 NOT NULL, ADD external_source VARCHAR(60) DEFAULT NULL, ADD external_id VARCHAR(200) DEFAULT NULL, ADD created_by_user_id BINARY(16) DEFAULT NULL, ADD updated_by_user_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE workspaces ADD CONSTRAINT FK_7FE8F3CB7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workspaces ADD CONSTRAINT FK_7FE8F3CB2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_7FE8F3CB7D182D95 ON workspaces (created_by_user_id)');
        $this->addSql('CREATE INDEX IDX_7FE8F3CB2793CC5E ON workspaces (updated_by_user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE custom_field_definitions DROP FOREIGN KEY FK_A496729882D40A1F');
        $this->addSql('ALTER TABLE custom_field_definitions DROP FOREIGN KEY FK_A49672987D182D95');
        $this->addSql('ALTER TABLE custom_field_definitions DROP FOREIGN KEY FK_A49672982793CC5E');
        $this->addSql('ALTER TABLE custom_field_values DROP FOREIGN KEY FK_6B64D7FFD11EA911');
        $this->addSql('ALTER TABLE custom_field_values DROP FOREIGN KEY FK_6B64D7FF82D40A1F');
        $this->addSql('ALTER TABLE custom_field_values DROP FOREIGN KEY FK_6B64D7FF7D182D95');
        $this->addSql('ALTER TABLE custom_field_values DROP FOREIGN KEY FK_6B64D7FF2793CC5E');
        $this->addSql('DROP TABLE custom_field_definitions');
        $this->addSql('DROP TABLE custom_field_values');
        $this->addSql('ALTER TABLE project_members DROP FOREIGN KEY FK_D3BEDE9A7D182D95');
        $this->addSql('ALTER TABLE project_members DROP FOREIGN KEY FK_D3BEDE9A2793CC5E');
        $this->addSql('DROP INDEX IDX_D3BEDE9A7D182D95 ON project_members');
        $this->addSql('DROP INDEX IDX_D3BEDE9A2793CC5E ON project_members');
        $this->addSql('ALTER TABLE project_members DROP created_by_user_id, DROP updated_by_user_id');
        $this->addSql('ALTER TABLE project_statuses DROP FOREIGN KEY FK_76BE95CA7D182D95');
        $this->addSql('ALTER TABLE project_statuses DROP FOREIGN KEY FK_76BE95CA2793CC5E');
        $this->addSql('DROP INDEX IDX_76BE95CA7D182D95 ON project_statuses');
        $this->addSql('DROP INDEX IDX_76BE95CA2793CC5E ON project_statuses');
        $this->addSql('ALTER TABLE project_statuses DROP version, DROP external_source, DROP external_id, DROP created_by_user_id, DROP updated_by_user_id');
        $this->addSql('ALTER TABLE projects DROP FOREIGN KEY FK_5C93B3A47D182D95');
        $this->addSql('ALTER TABLE projects DROP FOREIGN KEY FK_5C93B3A42793CC5E');
        $this->addSql('DROP INDEX IDX_5C93B3A47D182D95 ON projects');
        $this->addSql('DROP INDEX IDX_5C93B3A42793CC5E ON projects');
        $this->addSql('ALTER TABLE projects DROP version, DROP external_source, DROP external_id, DROP created_by_user_id, DROP updated_by_user_id');
        $this->addSql('ALTER TABLE tags DROP FOREIGN KEY FK_6FBC94267D182D95');
        $this->addSql('ALTER TABLE tags DROP FOREIGN KEY FK_6FBC94262793CC5E');
        $this->addSql('DROP INDEX IDX_6FBC94267D182D95 ON tags');
        $this->addSql('DROP INDEX IDX_6FBC94262793CC5E ON tags');
        $this->addSql('ALTER TABLE tags DROP version, DROP external_source, DROP external_id, DROP created_by_user_id, DROP updated_by_user_id');
        $this->addSql('ALTER TABLE task_statuses DROP FOREIGN KEY FK_B30F45D7D182D95');
        $this->addSql('ALTER TABLE task_statuses DROP FOREIGN KEY FK_B30F45D2793CC5E');
        $this->addSql('DROP INDEX IDX_B30F45D7D182D95 ON task_statuses');
        $this->addSql('DROP INDEX IDX_B30F45D2793CC5E ON task_statuses');
        $this->addSql('ALTER TABLE task_statuses DROP version, DROP external_source, DROP external_id, DROP created_by_user_id, DROP updated_by_user_id');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_505865977D182D95');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_505865972793CC5E');
        $this->addSql('DROP INDEX IDX_505865977D182D95 ON tasks');
        $this->addSql('DROP INDEX IDX_505865972793CC5E ON tasks');
        $this->addSql('ALTER TABLE tasks DROP version, DROP external_source, DROP external_id, DROP created_by_user_id, DROP updated_by_user_id');
        $this->addSql('ALTER TABLE time_entries DROP FOREIGN KEY FK_797F12A37D182D95');
        $this->addSql('ALTER TABLE time_entries DROP FOREIGN KEY FK_797F12A32793CC5E');
        $this->addSql('DROP INDEX IDX_797F12A37D182D95 ON time_entries');
        $this->addSql('DROP INDEX IDX_797F12A32793CC5E ON time_entries');
        $this->addSql('ALTER TABLE time_entries DROP version, DROP external_source, DROP external_id, DROP created_by_user_id, DROP updated_by_user_id');
        $this->addSql('ALTER TABLE users DROP version, DROP external_source, DROP external_id');
        $this->addSql('ALTER TABLE workspace_members DROP FOREIGN KEY FK_9D9D39F47D182D95');
        $this->addSql('ALTER TABLE workspace_members DROP FOREIGN KEY FK_9D9D39F42793CC5E');
        $this->addSql('DROP INDEX IDX_9D9D39F47D182D95 ON workspace_members');
        $this->addSql('DROP INDEX IDX_9D9D39F42793CC5E ON workspace_members');
        $this->addSql('ALTER TABLE workspace_members DROP created_by_user_id, DROP updated_by_user_id');
        $this->addSql('ALTER TABLE workspaces DROP FOREIGN KEY FK_7FE8F3CB7D182D95');
        $this->addSql('ALTER TABLE workspaces DROP FOREIGN KEY FK_7FE8F3CB2793CC5E');
        $this->addSql('DROP INDEX IDX_7FE8F3CB7D182D95 ON workspaces');
        $this->addSql('DROP INDEX IDX_7FE8F3CB2793CC5E ON workspaces');
        $this->addSql('ALTER TABLE workspaces DROP version, DROP external_source, DROP external_id, DROP created_by_user_id, DROP updated_by_user_id');
    }
}
