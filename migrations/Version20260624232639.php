<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260624232639 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sprints (name VARCHAR(80) NOT NULL, goal LONGTEXT DEFAULT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, state VARCHAR(12) DEFAULT \'planned\' NOT NULL, position DOUBLE PRECISION DEFAULT 0 NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, project_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_4EE469717D182D95 (created_by_user_id), INDEX IDX_4EE469712793CC5E (updated_by_user_id), INDEX sprint_project_idx (project_id), INDEX sprint_workspace_idx (workspace_id), INDEX sprint_start_idx (start_date), UNIQUE INDEX sprint_project_name_unique (project_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sprints ADD CONSTRAINT FK_4EE46971166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sprints ADD CONSTRAINT FK_4EE4697182D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sprints ADD CONSTRAINT FK_4EE469717D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sprints ADD CONSTRAINT FK_4EE469712793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tasks ADD sprint_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_505865978C24077B FOREIGN KEY (sprint_id) REFERENCES sprints (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_505865978C24077B ON tasks (sprint_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sprints DROP FOREIGN KEY FK_4EE46971166D1F9C');
        $this->addSql('ALTER TABLE sprints DROP FOREIGN KEY FK_4EE4697182D40A1F');
        $this->addSql('ALTER TABLE sprints DROP FOREIGN KEY FK_4EE469717D182D95');
        $this->addSql('ALTER TABLE sprints DROP FOREIGN KEY FK_4EE469712793CC5E');
        $this->addSql('DROP TABLE sprints');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_505865978C24077B');
        $this->addSql('DROP INDEX IDX_505865978C24077B ON tasks');
        $this->addSql('ALTER TABLE tasks DROP sprint_id');
    }
}
