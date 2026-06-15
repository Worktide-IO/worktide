<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260615113613 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Polymorphic task assignment (User or Team). Migrates the legacy task_assignees(task_id, user_id) into the new task_assignee_principals shape with principal_type=user; teams can now be assigned too.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_assignee_principals (principal_type VARCHAR(16) NOT NULL, principal_id BINARY(16) NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, task_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_295B1FD08DB60186 (task_id), INDEX task_assignee_principal_idx (principal_type, principal_id), INDEX task_assignee_workspace_idx (workspace_id), UNIQUE INDEX task_assignee_unique (task_id, principal_type, principal_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE task_assignee_principals ADD CONSTRAINT FK_295B1FD08DB60186 FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_assignee_principals ADD CONSTRAINT FK_295B1FD082D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');

        // Carry every existing user assignment over. New UUIDv4 PKs are
        // good enough — the IDs aren't surfaced to clients before this
        // migration runs. Workspace is denormalised from the parent task.
        $this->addSql(<<<'SQL'
            INSERT INTO task_assignee_principals
                (id, task_id, workspace_id, principal_type, principal_id, created_at, updated_at)
            SELECT
                UNHEX(REPLACE(UUID(), '-', '')),
                ta.task_id,
                t.workspace_id,
                'user',
                ta.user_id,
                NOW(),
                NOW()
            FROM task_assignees ta
            INNER JOIN tasks t ON t.id = ta.task_id
        SQL);

        $this->addSql('ALTER TABLE task_assignees DROP FOREIGN KEY `FK_6DEED38D8DB60186`');
        $this->addSql('ALTER TABLE task_assignees DROP FOREIGN KEY `FK_6DEED38DA76ED395`');
        $this->addSql('DROP TABLE task_assignees');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE task_assignees (task_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_6DEED38D8DB60186 (task_id), INDEX IDX_6DEED38DA76ED395 (user_id), PRIMARY KEY (task_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE task_assignees ADD CONSTRAINT `FK_6DEED38D8DB60186` FOREIGN KEY (task_id) REFERENCES tasks (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_assignees ADD CONSTRAINT `FK_6DEED38DA76ED395` FOREIGN KEY (user_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_assignee_principals DROP FOREIGN KEY FK_295B1FD08DB60186');
        $this->addSql('ALTER TABLE task_assignee_principals DROP FOREIGN KEY FK_295B1FD082D40A1F');
        $this->addSql('DROP TABLE task_assignee_principals');
    }
}
