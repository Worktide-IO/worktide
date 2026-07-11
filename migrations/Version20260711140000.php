<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Newsletter issues — a composed, sendable issue (subject + markdown body) of a
 * newsletter node, mailed once to the node's opted-in contacts.
 */
final class Version20260711140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Newsletter issues (composable + sendable newsletter content)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE newsletter_issues (subject VARCHAR(200) NOT NULL, body LONGTEXT DEFAULT NULL, status VARCHAR(16) NOT NULL, sent_at DATETIME DEFAULT NULL, recipient_count INT DEFAULT 0 NOT NULL, version INT DEFAULT 1 NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, newsletter_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX newsletter_issue_workspace_idx (workspace_id), INDEX newsletter_issue_newsletter_idx (newsletter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE newsletter_issues ADD CONSTRAINT FK_newsletter_issue_newsletter FOREIGN KEY (newsletter_id) REFERENCES newsletters (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletter_issues ADD CONSTRAINT FK_newsletter_issue_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletter_issues DROP FOREIGN KEY FK_newsletter_issue_newsletter');
        $this->addSql('ALTER TABLE newsletter_issues DROP FOREIGN KEY FK_newsletter_issue_workspace');
        $this->addSql('DROP TABLE newsletter_issues');
    }
}
