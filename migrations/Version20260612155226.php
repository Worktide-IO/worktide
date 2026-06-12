<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260612155226 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE project_members (role VARCHAR(20) NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_D3BEDE9A166D1F9C (project_id), INDEX IDX_D3BEDE9AA76ED395 (user_id), UNIQUE INDEX project_user_unique (project_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_statuses (name VARCHAR(60) NOT NULL, color VARCHAR(16) NOT NULL, position INT NOT NULL, is_completed TINYINT NOT NULL, is_archived TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_76BE95CA82D40A1F (workspace_id), UNIQUE INDEX project_status_workspace_name_unique (workspace_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE projects (name VARCHAR(160) NOT NULL, project_key VARCHAR(16) NOT NULL, description LONGTEXT DEFAULT NULL, color VARCHAR(16) NOT NULL, starts_on DATETIME DEFAULT NULL, due_on DATETIME DEFAULT NULL, is_archived TINYINT NOT NULL, budget_minutes INT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, status_id BINARY(16) NOT NULL, owner_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_5C93B3A46BF700BD (status_id), INDEX IDX_5C93B3A47E3C61F9 (owner_id), INDEX project_workspace_idx (workspace_id), UNIQUE INDEX project_workspace_key_unique (workspace_id, project_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_tags (project_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_562D5C3E166D1F9C (project_id), INDEX IDX_562D5C3EBAD26311 (tag_id), PRIMARY KEY (project_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tags (name VARCHAR(60) NOT NULL, color VARCHAR(16) NOT NULL, scope VARCHAR(12) NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_6FBC942682D40A1F (workspace_id), UNIQUE INDEX tag_workspace_name_scope_unique (workspace_id, name, scope), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_statuses (name VARCHAR(60) NOT NULL, color VARCHAR(16) NOT NULL, position INT NOT NULL, is_completed TINYINT NOT NULL, is_default TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_B30F45D82D40A1F (workspace_id), UNIQUE INDEX task_status_workspace_name_unique (workspace_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tasks (identifier VARCHAR(24) NOT NULL, title VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, priority VARCHAR(12) NOT NULL, due_on DATETIME DEFAULT NULL, started_on DATETIME DEFAULT NULL, estimated_minutes INT DEFAULT NULL, position INT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, project_id BINARY(16) NOT NULL, status_id BINARY(16) NOT NULL, assignee_id BINARY(16) DEFAULT NULL, created_by_id BINARY(16) DEFAULT NULL, parent_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_505865976BF700BD (status_id), INDEX IDX_50586597B03A8386 (created_by_id), INDEX IDX_50586597727ACA70 (parent_id), INDEX task_workspace_idx (workspace_id), INDEX task_project_idx (project_id), INDEX task_assignee_idx (assignee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_tags (task_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_1C0F005D8DB60186 (task_id), INDEX IDX_1C0F005DBAD26311 (tag_id), PRIMARY KEY (task_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE time_entries (starts_at DATETIME NOT NULL, ends_at DATETIME DEFAULT NULL, duration_minutes INT NOT NULL, note LONGTEXT DEFAULT NULL, is_billable TINYINT NOT NULL, is_locked TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, project_id BINARY(16) NOT NULL, task_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX time_entry_workspace_idx (workspace_id), INDEX time_entry_user_idx (user_id), INDEX time_entry_project_idx (project_id), INDEX time_entry_task_idx (task_id), INDEX time_entry_starts_at_idx (starts_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, last_login_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE workspace_members (role VARCHAR(16) NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, workspace_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_9D9D39F482D40A1F (workspace_id), INDEX IDX_9D9D39F4A76ED395 (user_id), UNIQUE INDEX workspace_user_unique (workspace_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE workspaces (name VARCHAR(120) NOT NULL, slug VARCHAR(60) NOT NULL, locale VARCHAR(8) NOT NULL, timezone VARCHAR(64) NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_7FE8F3CB989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE project_members ADD CONSTRAINT FK_D3BEDE9A166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_members ADD CONSTRAINT FK_D3BEDE9AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_statuses ADD CONSTRAINT FK_76BE95CA82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE projects ADD CONSTRAINT FK_5C93B3A46BF700BD FOREIGN KEY (status_id) REFERENCES project_statuses (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE projects ADD CONSTRAINT FK_5C93B3A47E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE projects ADD CONSTRAINT FK_5C93B3A482D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_tags ADD CONSTRAINT FK_562D5C3E166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_tags ADD CONSTRAINT FK_562D5C3EBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tags ADD CONSTRAINT FK_6FBC942682D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_statuses ADD CONSTRAINT FK_B30F45D82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_50586597166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_505865976BF700BD FOREIGN KEY (status_id) REFERENCES task_statuses (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_5058659759EC7D60 FOREIGN KEY (assignee_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_50586597B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_50586597727ACA70 FOREIGN KEY (parent_id) REFERENCES tasks (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_5058659782D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_tags ADD CONSTRAINT FK_1C0F005D8DB60186 FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_tags ADD CONSTRAINT FK_1C0F005DBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE time_entries ADD CONSTRAINT FK_797F12A3A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE time_entries ADD CONSTRAINT FK_797F12A3166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE time_entries ADD CONSTRAINT FK_797F12A38DB60186 FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE time_entries ADD CONSTRAINT FK_797F12A382D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workspace_members ADD CONSTRAINT FK_9D9D39F482D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workspace_members ADD CONSTRAINT FK_9D9D39F4A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project_members DROP FOREIGN KEY FK_D3BEDE9A166D1F9C');
        $this->addSql('ALTER TABLE project_members DROP FOREIGN KEY FK_D3BEDE9AA76ED395');
        $this->addSql('ALTER TABLE project_statuses DROP FOREIGN KEY FK_76BE95CA82D40A1F');
        $this->addSql('ALTER TABLE projects DROP FOREIGN KEY FK_5C93B3A46BF700BD');
        $this->addSql('ALTER TABLE projects DROP FOREIGN KEY FK_5C93B3A47E3C61F9');
        $this->addSql('ALTER TABLE projects DROP FOREIGN KEY FK_5C93B3A482D40A1F');
        $this->addSql('ALTER TABLE project_tags DROP FOREIGN KEY FK_562D5C3E166D1F9C');
        $this->addSql('ALTER TABLE project_tags DROP FOREIGN KEY FK_562D5C3EBAD26311');
        $this->addSql('ALTER TABLE tags DROP FOREIGN KEY FK_6FBC942682D40A1F');
        $this->addSql('ALTER TABLE task_statuses DROP FOREIGN KEY FK_B30F45D82D40A1F');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_50586597166D1F9C');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_505865976BF700BD');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_5058659759EC7D60');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_50586597B03A8386');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_50586597727ACA70');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_5058659782D40A1F');
        $this->addSql('ALTER TABLE task_tags DROP FOREIGN KEY FK_1C0F005D8DB60186');
        $this->addSql('ALTER TABLE task_tags DROP FOREIGN KEY FK_1C0F005DBAD26311');
        $this->addSql('ALTER TABLE time_entries DROP FOREIGN KEY FK_797F12A3A76ED395');
        $this->addSql('ALTER TABLE time_entries DROP FOREIGN KEY FK_797F12A3166D1F9C');
        $this->addSql('ALTER TABLE time_entries DROP FOREIGN KEY FK_797F12A38DB60186');
        $this->addSql('ALTER TABLE time_entries DROP FOREIGN KEY FK_797F12A382D40A1F');
        $this->addSql('ALTER TABLE workspace_members DROP FOREIGN KEY FK_9D9D39F482D40A1F');
        $this->addSql('ALTER TABLE workspace_members DROP FOREIGN KEY FK_9D9D39F4A76ED395');
        $this->addSql('DROP TABLE project_members');
        $this->addSql('DROP TABLE project_statuses');
        $this->addSql('DROP TABLE projects');
        $this->addSql('DROP TABLE project_tags');
        $this->addSql('DROP TABLE tags');
        $this->addSql('DROP TABLE task_statuses');
        $this->addSql('DROP TABLE tasks');
        $this->addSql('DROP TABLE task_tags');
        $this->addSql('DROP TABLE time_entries');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE workspace_members');
        $this->addSql('DROP TABLE workspaces');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
