<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260614095217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sweep — WorkspaceInvitation pre-registration flow';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE workspace_invitations (email VARCHAR(254) NOT NULL, role VARCHAR(16) NOT NULL, token VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, expires_at DATETIME NOT NULL, accepted_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, accepted_by_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_B371BAB620F699D9 (accepted_by_id), INDEX IDX_B371BAB67D182D95 (created_by_user_id), INDEX IDX_B371BAB62793CC5E (updated_by_user_id), INDEX workspace_invitation_workspace_idx (workspace_id), INDEX workspace_invitation_email_idx (email), UNIQUE INDEX workspace_invitation_token_unique (token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE workspace_invitations ADD CONSTRAINT FK_B371BAB620F699D9 FOREIGN KEY (accepted_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workspace_invitations ADD CONSTRAINT FK_B371BAB682D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workspace_invitations ADD CONSTRAINT FK_B371BAB67D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workspace_invitations ADD CONSTRAINT FK_B371BAB62793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE workspace_invitations DROP FOREIGN KEY FK_B371BAB620F699D9');
        $this->addSql('ALTER TABLE workspace_invitations DROP FOREIGN KEY FK_B371BAB682D40A1F');
        $this->addSql('ALTER TABLE workspace_invitations DROP FOREIGN KEY FK_B371BAB67D182D95');
        $this->addSql('ALTER TABLE workspace_invitations DROP FOREIGN KEY FK_B371BAB62793CC5E');
        $this->addSql('DROP TABLE workspace_invitations');
    }
}
