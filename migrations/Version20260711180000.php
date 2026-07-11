<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Newsletter templates — reusable, named content (subject + markdown body) that
 * staff apply to pre-fill a newsletter issue.
 */
final class Version20260711180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Newsletter templates (reusable named content)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE newsletter_templates (name VARCHAR(200) NOT NULL, subject VARCHAR(200) NOT NULL, body LONGTEXT DEFAULT NULL, version INT DEFAULT 1 NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX newsletter_template_workspace_idx (workspace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE newsletter_templates ADD CONSTRAINT FK_newsletter_template_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletter_templates DROP FOREIGN KEY FK_newsletter_template_workspace');
        $this->addSql('DROP TABLE newsletter_templates');
    }
}
