<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613110629 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE project_templates (name VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, color VARCHAR(16) NOT NULL, default_budget_minutes INT DEFAULT NULL, default_is_billable_by_default TINYINT NOT NULL, default_deduct_non_billable_hours TINYINT NOT NULL, default_is_multi_assignment_allowed TINYINT NOT NULL, default_is_retainer TINYINT NOT NULL, is_published TINYINT NOT NULL, is_archived TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, project_type_id BINARY(16) DEFAULT NULL, task_bundle_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_7E143859535280F6 (project_type_id), INDEX IDX_7E1438598ED01482 (task_bundle_id), INDEX IDX_7E14385982D40A1F (workspace_id), INDEX IDX_7E1438597D182D95 (created_by_user_id), INDEX IDX_7E1438592793CC5E (updated_by_user_id), UNIQUE INDEX project_template_workspace_name_unique (workspace_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_template_tags (project_template_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_6EE3294DCD15F843 (project_template_id), INDEX IDX_6EE3294DBAD26311 (tag_id), PRIMARY KEY (project_template_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_bundles (name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, color VARCHAR(16) NOT NULL, is_archived TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_C746107282D40A1F (workspace_id), INDEX IDX_C74610727D182D95 (created_by_user_id), INDEX IDX_C74610722793CC5E (updated_by_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_templates (title VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, priority VARCHAR(12) NOT NULL, estimated_minutes INT DEFAULT NULL, due_day_offset INT DEFAULT NULL, position INT NOT NULL, default_checklist JSON NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, bundle_id BINARY(16) NOT NULL, parent_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_1002B0DF727ACA70 (parent_id), INDEX IDX_1002B0DF82D40A1F (workspace_id), INDEX IDX_1002B0DF7D182D95 (created_by_user_id), INDEX IDX_1002B0DF2793CC5E (updated_by_user_id), INDEX task_template_bundle_idx (bundle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE project_templates ADD CONSTRAINT FK_7E143859535280F6 FOREIGN KEY (project_type_id) REFERENCES project_types (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_templates ADD CONSTRAINT FK_7E1438598ED01482 FOREIGN KEY (task_bundle_id) REFERENCES task_bundles (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_templates ADD CONSTRAINT FK_7E14385982D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_templates ADD CONSTRAINT FK_7E1438597D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_templates ADD CONSTRAINT FK_7E1438592793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_template_tags ADD CONSTRAINT FK_6EE3294DCD15F843 FOREIGN KEY (project_template_id) REFERENCES project_templates (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_template_tags ADD CONSTRAINT FK_6EE3294DBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_bundles ADD CONSTRAINT FK_C746107282D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_bundles ADD CONSTRAINT FK_C74610727D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_bundles ADD CONSTRAINT FK_C74610722793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_templates ADD CONSTRAINT FK_1002B0DFF1FAD9D3 FOREIGN KEY (bundle_id) REFERENCES task_bundles (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_templates ADD CONSTRAINT FK_1002B0DF727ACA70 FOREIGN KEY (parent_id) REFERENCES task_templates (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_templates ADD CONSTRAINT FK_1002B0DF82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_templates ADD CONSTRAINT FK_1002B0DF7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_templates ADD CONSTRAINT FK_1002B0DF2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project_templates DROP FOREIGN KEY FK_7E143859535280F6');
        $this->addSql('ALTER TABLE project_templates DROP FOREIGN KEY FK_7E1438598ED01482');
        $this->addSql('ALTER TABLE project_templates DROP FOREIGN KEY FK_7E14385982D40A1F');
        $this->addSql('ALTER TABLE project_templates DROP FOREIGN KEY FK_7E1438597D182D95');
        $this->addSql('ALTER TABLE project_templates DROP FOREIGN KEY FK_7E1438592793CC5E');
        $this->addSql('ALTER TABLE project_template_tags DROP FOREIGN KEY FK_6EE3294DCD15F843');
        $this->addSql('ALTER TABLE project_template_tags DROP FOREIGN KEY FK_6EE3294DBAD26311');
        $this->addSql('ALTER TABLE task_bundles DROP FOREIGN KEY FK_C746107282D40A1F');
        $this->addSql('ALTER TABLE task_bundles DROP FOREIGN KEY FK_C74610727D182D95');
        $this->addSql('ALTER TABLE task_bundles DROP FOREIGN KEY FK_C74610722793CC5E');
        $this->addSql('ALTER TABLE task_templates DROP FOREIGN KEY FK_1002B0DFF1FAD9D3');
        $this->addSql('ALTER TABLE task_templates DROP FOREIGN KEY FK_1002B0DF727ACA70');
        $this->addSql('ALTER TABLE task_templates DROP FOREIGN KEY FK_1002B0DF82D40A1F');
        $this->addSql('ALTER TABLE task_templates DROP FOREIGN KEY FK_1002B0DF7D182D95');
        $this->addSql('ALTER TABLE task_templates DROP FOREIGN KEY FK_1002B0DF2793CC5E');
        $this->addSql('DROP TABLE project_templates');
        $this->addSql('DROP TABLE project_template_tags');
        $this->addSql('DROP TABLE task_bundles');
        $this->addSql('DROP TABLE task_templates');
    }
}
