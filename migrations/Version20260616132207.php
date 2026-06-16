<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260616132207 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE document_revisions (name VARCHAR(240) NOT NULL, body LONGTEXT DEFAULT NULL, body_format VARCHAR(16) NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, document_id BINARY(16) NOT NULL, author_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_9B890A24F675F31B (author_id), INDEX IDX_9B890A2482D40A1F (workspace_id), INDEX document_revision_doc_idx (document_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE document_revisions ADD CONSTRAINT FK_9B890A24C33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_revisions ADD CONSTRAINT FK_9B890A24F675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document_revisions ADD CONSTRAINT FK_9B890A2482D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document_revisions DROP FOREIGN KEY FK_9B890A24C33F7837');
        $this->addSql('ALTER TABLE document_revisions DROP FOREIGN KEY FK_9B890A24F675F31B');
        $this->addSql('ALTER TABLE document_revisions DROP FOREIGN KEY FK_9B890A2482D40A1F');
        $this->addSql('DROP TABLE document_revisions');
    }
}
