<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625233113 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'External identity mapping: external_identities table (Phase C Schicht 5 — import-filter foundation)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE external_identities (external_user_id VARCHAR(191) NOT NULL, external_email VARCHAR(180) DEFAULT NULL, external_display_name VARCHAR(180) DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, channel_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_E0C7A33A72F5A1AA (channel_id), INDEX IDX_E0C7A33AA76ED395 (user_id), INDEX IDX_E0C7A33A7D182D95 (created_by_user_id), INDEX IDX_E0C7A33A2793CC5E (updated_by_user_id), INDEX external_identity_workspace_idx (workspace_id), INDEX external_identity_email_idx (external_email), UNIQUE INDEX external_identity_channel_user_unique (channel_id, external_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE external_identities ADD CONSTRAINT FK_E0C7A33A72F5A1AA FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE external_identities ADD CONSTRAINT FK_E0C7A33AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE external_identities ADD CONSTRAINT FK_E0C7A33A82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE external_identities ADD CONSTRAINT FK_E0C7A33A7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE external_identities ADD CONSTRAINT FK_E0C7A33A2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE external_identities DROP FOREIGN KEY FK_E0C7A33A72F5A1AA');
        $this->addSql('ALTER TABLE external_identities DROP FOREIGN KEY FK_E0C7A33AA76ED395');
        $this->addSql('ALTER TABLE external_identities DROP FOREIGN KEY FK_E0C7A33A82D40A1F');
        $this->addSql('ALTER TABLE external_identities DROP FOREIGN KEY FK_E0C7A33A7D182D95');
        $this->addSql('ALTER TABLE external_identities DROP FOREIGN KEY FK_E0C7A33A2793CC5E');
        $this->addSql('DROP TABLE external_identities');
    }
}
