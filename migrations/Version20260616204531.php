<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260616204531 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE workflow_transitions (allowed_roles JSON DEFAULT NULL, label VARCHAR(80) DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, tracker_id BINARY(16) DEFAULT NULL, from_status_id BINARY(16) NOT NULL, to_status_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_C66989BBFB5230B (tracker_id), INDEX IDX_C66989BB7B6B9507 (from_status_id), INDEX IDX_C66989BB5A54D7CC (to_status_id), INDEX IDX_C66989BB82D40A1F (workspace_id), INDEX IDX_C66989BB7D182D95 (created_by_user_id), INDEX IDX_C66989BB2793CC5E (updated_by_user_id), INDEX workflow_transition_tracker_from_idx (workspace_id, tracker_id, from_status_id), UNIQUE INDEX workflow_transition_unique (workspace_id, tracker_id, from_status_id, to_status_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE workflow_transitions ADD CONSTRAINT FK_C66989BBFB5230B FOREIGN KEY (tracker_id) REFERENCES trackers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflow_transitions ADD CONSTRAINT FK_C66989BB7B6B9507 FOREIGN KEY (from_status_id) REFERENCES task_statuses (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflow_transitions ADD CONSTRAINT FK_C66989BB5A54D7CC FOREIGN KEY (to_status_id) REFERENCES task_statuses (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflow_transitions ADD CONSTRAINT FK_C66989BB82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflow_transitions ADD CONSTRAINT FK_C66989BB7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workflow_transitions ADD CONSTRAINT FK_C66989BB2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE workflow_transitions DROP FOREIGN KEY FK_C66989BBFB5230B');
        $this->addSql('ALTER TABLE workflow_transitions DROP FOREIGN KEY FK_C66989BB7B6B9507');
        $this->addSql('ALTER TABLE workflow_transitions DROP FOREIGN KEY FK_C66989BB5A54D7CC');
        $this->addSql('ALTER TABLE workflow_transitions DROP FOREIGN KEY FK_C66989BB82D40A1F');
        $this->addSql('ALTER TABLE workflow_transitions DROP FOREIGN KEY FK_C66989BB7D182D95');
        $this->addSql('ALTER TABLE workflow_transitions DROP FOREIGN KEY FK_C66989BB2793CC5E');
        $this->addSql('DROP TABLE workflow_transitions');
    }
}
