<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704134907 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portal: portal_form_drafts table.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE portal_form_drafts (answers JSON NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, form_id BINARY(16) NOT NULL, contact_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_81C55F6E5FF69B7D (form_id), INDEX IDX_81C55F6E82D40A1F (workspace_id), INDEX portal_form_draft_contact_idx (contact_id), UNIQUE INDEX portal_form_draft_form_contact_uniq (form_id, contact_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE portal_form_drafts ADD CONSTRAINT FK_81C55F6E5FF69B7D FOREIGN KEY (form_id) REFERENCES public_forms (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portal_form_drafts ADD CONSTRAINT FK_81C55F6EE7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portal_form_drafts ADD CONSTRAINT FK_81C55F6E82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE portal_form_drafts DROP FOREIGN KEY FK_81C55F6E5FF69B7D');
        $this->addSql('ALTER TABLE portal_form_drafts DROP FOREIGN KEY FK_81C55F6EE7A1254A');
        $this->addSql('ALTER TABLE portal_form_drafts DROP FOREIGN KEY FK_81C55F6E82D40A1F');
        $this->addSql('DROP TABLE portal_form_drafts');
    }
}
