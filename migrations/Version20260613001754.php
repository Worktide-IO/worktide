<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613001754 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE checklist_items (name VARCHAR(300) NOT NULL, is_done TINYINT NOT NULL, position DOUBLE PRECISION NOT NULL, checked_on DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, task_id BINARY(16) NOT NULL, checked_by_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_DFF66E932199DB86 (checked_by_id), INDEX IDX_DFF66E9382D40A1F (workspace_id), INDEX IDX_DFF66E937D182D95 (created_by_user_id), INDEX IDX_DFF66E932793CC5E (updated_by_user_id), INDEX checklist_item_task_idx (task_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_list_entries (position DOUBLE PRECISION NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, list_id BINARY(16) NOT NULL, task_id BINARY(16) NOT NULL, INDEX task_list_entry_list_idx (list_id), INDEX task_list_entry_task_idx (task_id), UNIQUE INDEX task_list_entry_unique (list_id, task_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_lists (name VARCHAR(120) NOT NULL, color VARCHAR(16) DEFAULT NULL, position DOUBLE PRECISION NOT NULL, is_archived TINYINT NOT NULL, is_hidden_for_connect_users TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, project_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_CF82848582D40A1F (workspace_id), INDEX IDX_CF8284857D182D95 (created_by_user_id), INDEX IDX_CF8284852793CC5E (updated_by_user_id), INDEX task_list_project_idx (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE checklist_items ADD CONSTRAINT FK_DFF66E938DB60186 FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE checklist_items ADD CONSTRAINT FK_DFF66E932199DB86 FOREIGN KEY (checked_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE checklist_items ADD CONSTRAINT FK_DFF66E9382D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE checklist_items ADD CONSTRAINT FK_DFF66E937D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE checklist_items ADD CONSTRAINT FK_DFF66E932793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_list_entries ADD CONSTRAINT FK_39264AE53DAE168B FOREIGN KEY (list_id) REFERENCES task_lists (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_list_entries ADD CONSTRAINT FK_39264AE58DB60186 FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_lists ADD CONSTRAINT FK_CF828485166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_lists ADD CONSTRAINT FK_CF82848582D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_lists ADD CONSTRAINT FK_CF8284857D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_lists ADD CONSTRAINT FK_CF8284852793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE checklist_items DROP FOREIGN KEY FK_DFF66E938DB60186');
        $this->addSql('ALTER TABLE checklist_items DROP FOREIGN KEY FK_DFF66E932199DB86');
        $this->addSql('ALTER TABLE checklist_items DROP FOREIGN KEY FK_DFF66E9382D40A1F');
        $this->addSql('ALTER TABLE checklist_items DROP FOREIGN KEY FK_DFF66E937D182D95');
        $this->addSql('ALTER TABLE checklist_items DROP FOREIGN KEY FK_DFF66E932793CC5E');
        $this->addSql('ALTER TABLE task_list_entries DROP FOREIGN KEY FK_39264AE53DAE168B');
        $this->addSql('ALTER TABLE task_list_entries DROP FOREIGN KEY FK_39264AE58DB60186');
        $this->addSql('ALTER TABLE task_lists DROP FOREIGN KEY FK_CF828485166D1F9C');
        $this->addSql('ALTER TABLE task_lists DROP FOREIGN KEY FK_CF82848582D40A1F');
        $this->addSql('ALTER TABLE task_lists DROP FOREIGN KEY FK_CF8284857D182D95');
        $this->addSql('ALTER TABLE task_lists DROP FOREIGN KEY FK_CF8284852793CC5E');
        $this->addSql('DROP TABLE checklist_items');
        $this->addSql('DROP TABLE task_list_entries');
        $this->addSql('DROP TABLE task_lists');
    }
}
