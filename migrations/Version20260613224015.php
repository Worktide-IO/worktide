<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613224015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B9 — DocumentSpace + Document + DocumentContributor';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE document_contributors (access VARCHAR(12) NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, document_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_DF3743137D182D95 (created_by_user_id), INDEX IDX_DF3743132793CC5E (updated_by_user_id), INDEX document_contributor_document_idx (document_id), INDEX document_contributor_user_idx (user_id), UNIQUE INDEX document_contributor_unique (document_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE document_spaces (name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, color VARCHAR(16) DEFAULT NULL, icon VARCHAR(60) DEFAULT NULL, emoji VARCHAR(16) DEFAULT NULL, position DOUBLE PRECISION NOT NULL, is_archived TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_6349D51E82D40A1F (workspace_id), INDEX IDX_6349D51E7D182D95 (created_by_user_id), INDEX IDX_6349D51E2793CC5E (updated_by_user_id), UNIQUE INDEX document_space_workspace_name_unique (workspace_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE documents (name VARCHAR(200) NOT NULL, emoji VARCHAR(16) DEFAULT NULL, body LONGTEXT DEFAULT NULL, body_format VARCHAR(12) NOT NULL, position DOUBLE PRECISION NOT NULL, is_private TINYINT NOT NULL, is_hidden_for_connect_users TINYINT NOT NULL, is_archived TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, space_id BINARY(16) DEFAULT NULL, parent_id BINARY(16) DEFAULT NULL, project_id BINARY(16) DEFAULT NULL, task_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_A2B072887D182D95 (created_by_user_id), INDEX IDX_A2B072882793CC5E (updated_by_user_id), INDEX document_workspace_idx (workspace_id), INDEX document_space_idx (space_id), INDEX document_parent_idx (parent_id), INDEX document_project_idx (project_id), INDEX document_task_idx (task_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE document_contributors ADD CONSTRAINT FK_DF374313C33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_contributors ADD CONSTRAINT FK_DF374313A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_contributors ADD CONSTRAINT FK_DF3743137D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document_contributors ADD CONSTRAINT FK_DF3743132793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document_spaces ADD CONSTRAINT FK_6349D51E82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_spaces ADD CONSTRAINT FK_6349D51E7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document_spaces ADD CONSTRAINT FK_6349D51E2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B0728823575340 FOREIGN KEY (space_id) REFERENCES document_spaces (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B07288727ACA70 FOREIGN KEY (parent_id) REFERENCES documents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B07288166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B072888DB60186 FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B0728882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B072887D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B072882793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document_contributors DROP FOREIGN KEY FK_DF374313C33F7837');
        $this->addSql('ALTER TABLE document_contributors DROP FOREIGN KEY FK_DF374313A76ED395');
        $this->addSql('ALTER TABLE document_contributors DROP FOREIGN KEY FK_DF3743137D182D95');
        $this->addSql('ALTER TABLE document_contributors DROP FOREIGN KEY FK_DF3743132793CC5E');
        $this->addSql('ALTER TABLE document_spaces DROP FOREIGN KEY FK_6349D51E82D40A1F');
        $this->addSql('ALTER TABLE document_spaces DROP FOREIGN KEY FK_6349D51E7D182D95');
        $this->addSql('ALTER TABLE document_spaces DROP FOREIGN KEY FK_6349D51E2793CC5E');
        $this->addSql('ALTER TABLE documents DROP FOREIGN KEY FK_A2B0728823575340');
        $this->addSql('ALTER TABLE documents DROP FOREIGN KEY FK_A2B07288727ACA70');
        $this->addSql('ALTER TABLE documents DROP FOREIGN KEY FK_A2B07288166D1F9C');
        $this->addSql('ALTER TABLE documents DROP FOREIGN KEY FK_A2B072888DB60186');
        $this->addSql('ALTER TABLE documents DROP FOREIGN KEY FK_A2B0728882D40A1F');
        $this->addSql('ALTER TABLE documents DROP FOREIGN KEY FK_A2B072887D182D95');
        $this->addSql('ALTER TABLE documents DROP FOREIGN KEY FK_A2B072882793CC5E');
        $this->addSql('DROP TABLE document_contributors');
        $this->addSql('DROP TABLE document_spaces');
        $this->addSql('DROP TABLE documents');
    }
}
