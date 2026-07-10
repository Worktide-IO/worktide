<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cross-workspace project sharing: project_share_invitations (email invite +
 * accept-token, modeled on workspace_invitations) and project_shares (the
 * accepted link between a project in workspace A and a workspace B).
 */
final class Version20260710203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cross-workspace project sharing: project_share_invitations + project_shares.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE project_share_invitations (
            email VARCHAR(254) NOT NULL,
            role VARCHAR(16) NOT NULL,
            token VARCHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL,
            expires_at DATETIME NOT NULL,
            accepted_at DATETIME DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            send_count INT DEFAULT 0 NOT NULL,
            id BINARY(16) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            project_id BINARY(16) NOT NULL,
            accepted_by_id BINARY(16) DEFAULT NULL,
            workspace_id BINARY(16) NOT NULL,
            created_by_user_id BINARY(16) DEFAULT NULL,
            updated_by_user_id BINARY(16) DEFAULT NULL,
            INDEX project_share_invitation_workspace_idx (workspace_id),
            INDEX project_share_invitation_project_idx (project_id),
            INDEX IDX_PSI_accepted_by (accepted_by_id),
            INDEX IDX_PSI_created_by (created_by_user_id),
            INDEX IDX_PSI_updated_by (updated_by_user_id),
            UNIQUE INDEX project_share_invitation_token_unique (token),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE project_shares (
            role VARCHAR(16) NOT NULL,
            id BINARY(16) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            project_id BINARY(16) NOT NULL,
            shared_with_workspace_id BINARY(16) NOT NULL,
            accepted_by_id BINARY(16) DEFAULT NULL,
            INDEX project_share_project_idx (project_id),
            INDEX project_share_workspace_idx (shared_with_workspace_id),
            INDEX IDX_PS_accepted_by (accepted_by_id),
            UNIQUE INDEX project_share_project_workspace_unique (project_id, shared_with_workspace_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE project_share_invitations ADD CONSTRAINT FK_PSI_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_share_invitations ADD CONSTRAINT FK_PSI_accepted_by FOREIGN KEY (accepted_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_share_invitations ADD CONSTRAINT FK_PSI_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_share_invitations ADD CONSTRAINT FK_PSI_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_share_invitations ADD CONSTRAINT FK_PSI_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE project_shares ADD CONSTRAINT FK_PS_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_shares ADD CONSTRAINT FK_PS_workspace FOREIGN KEY (shared_with_workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_shares ADD CONSTRAINT FK_PS_accepted_by FOREIGN KEY (accepted_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE project_share_invitations');
        $this->addSql('DROP TABLE project_shares');
    }
}
