<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260612235237 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE custom_field_options (value VARCHAR(120) NOT NULL, color VARCHAR(16) DEFAULT NULL, position INT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, definition_id BINARY(16) NOT NULL, INDEX cfo_definition_idx (definition_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_types (name VARCHAR(80) NOT NULL, icon VARCHAR(60) DEFAULT NULL, color VARCHAR(16) DEFAULT NULL, description LONGTEXT DEFAULT NULL, position INT NOT NULL, is_archived TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, external_source VARCHAR(60) DEFAULT NULL, external_id VARCHAR(200) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_4A6580AE82D40A1F (workspace_id), INDEX IDX_4A6580AE7D182D95 (created_by_user_id), INDEX IDX_4A6580AE2793CC5E (updated_by_user_id), UNIQUE INDEX project_type_workspace_name_unique (workspace_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_assignees (task_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_6DEED38D8DB60186 (task_id), INDEX IDX_6DEED38DA76ED395 (user_id), PRIMARY KEY (task_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE custom_field_options ADD CONSTRAINT FK_B40F91DCD11EA911 FOREIGN KEY (definition_id) REFERENCES custom_field_definitions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_types ADD CONSTRAINT FK_4A6580AE82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_types ADD CONSTRAINT FK_4A6580AE7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_types ADD CONSTRAINT FK_4A6580AE2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_assignees ADD CONSTRAINT FK_6DEED38D8DB60186 FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_assignees ADD CONSTRAINT FK_6DEED38DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE projects ADD is_private TINYINT NOT NULL, ADD closed_on DATETIME DEFAULT NULL, ADD is_billable_by_default TINYINT NOT NULL, ADD deduct_non_billable_hours TINYINT NOT NULL, ADD has_image TINYINT NOT NULL, ADD is_retainer TINYINT NOT NULL, ADD is_multi_assignment_allowed TINYINT NOT NULL, ADD project_type_id BINARY(16) DEFAULT NULL, ADD closed_by_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE projects ADD CONSTRAINT FK_5C93B3A4535280F6 FOREIGN KEY (project_type_id) REFERENCES project_types (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE projects ADD CONSTRAINT FK_5C93B3A4E1FA7797 FOREIGN KEY (closed_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5C93B3A4535280F6 ON projects (project_type_id)');
        $this->addSql('CREATE INDEX IDX_5C93B3A4E1FA7797 ON projects (closed_by_id)');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY `FK_5058659759EC7D60`');
        $this->addSql('DROP INDEX task_assignee_idx ON tasks');
        $this->addSql('ALTER TABLE tasks ADD is_prio TINYINT NOT NULL, ADD is_hidden_for_connect_users TINYINT NOT NULL, ADD closed_on DATETIME DEFAULT NULL, CHANGE assignee_id closed_by_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_50586597E1FA7797 FOREIGN KEY (closed_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_50586597E1FA7797 ON tasks (closed_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE custom_field_options DROP FOREIGN KEY FK_B40F91DCD11EA911');
        $this->addSql('ALTER TABLE project_types DROP FOREIGN KEY FK_4A6580AE82D40A1F');
        $this->addSql('ALTER TABLE project_types DROP FOREIGN KEY FK_4A6580AE7D182D95');
        $this->addSql('ALTER TABLE project_types DROP FOREIGN KEY FK_4A6580AE2793CC5E');
        $this->addSql('ALTER TABLE task_assignees DROP FOREIGN KEY FK_6DEED38D8DB60186');
        $this->addSql('ALTER TABLE task_assignees DROP FOREIGN KEY FK_6DEED38DA76ED395');
        $this->addSql('DROP TABLE custom_field_options');
        $this->addSql('DROP TABLE project_types');
        $this->addSql('DROP TABLE task_assignees');
        $this->addSql('ALTER TABLE projects DROP FOREIGN KEY FK_5C93B3A4535280F6');
        $this->addSql('ALTER TABLE projects DROP FOREIGN KEY FK_5C93B3A4E1FA7797');
        $this->addSql('DROP INDEX IDX_5C93B3A4535280F6 ON projects');
        $this->addSql('DROP INDEX IDX_5C93B3A4E1FA7797 ON projects');
        $this->addSql('ALTER TABLE projects DROP is_private, DROP closed_on, DROP is_billable_by_default, DROP deduct_non_billable_hours, DROP has_image, DROP is_retainer, DROP is_multi_assignment_allowed, DROP project_type_id, DROP closed_by_id');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_50586597E1FA7797');
        $this->addSql('DROP INDEX IDX_50586597E1FA7797 ON tasks');
        $this->addSql('ALTER TABLE tasks DROP is_prio, DROP is_hidden_for_connect_users, DROP closed_on, CHANGE closed_by_id assignee_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT `FK_5058659759EC7D60` FOREIGN KEY (assignee_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('CREATE INDEX task_assignee_idx ON tasks (assignee_id)');
    }
}
