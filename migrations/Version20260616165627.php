<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260616165627 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE document_reviewers (document_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_2F0F9021C33F7837 (document_id), INDEX IDX_2F0F9021A76ED395 (user_id), PRIMARY KEY (document_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE document_reviewers ADD CONSTRAINT FK_2F0F9021C33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_reviewers ADD CONSTRAINT FK_2F0F9021A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documents ADD workflow_state VARCHAR(12) DEFAULT \'draft\' NOT NULL, ADD submitted_at DATETIME DEFAULT NULL, ADD published_at DATETIME DEFAULT NULL, ADD submitted_by_id BINARY(16) DEFAULT NULL, ADD published_by_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B0728879F7D87D FOREIGN KEY (submitted_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE documents ADD CONSTRAINT FK_A2B072885B075477 FOREIGN KEY (published_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A2B0728879F7D87D ON documents (submitted_by_id)');
        $this->addSql('CREATE INDEX IDX_A2B072885B075477 ON documents (published_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document_reviewers DROP FOREIGN KEY FK_2F0F9021C33F7837');
        $this->addSql('ALTER TABLE document_reviewers DROP FOREIGN KEY FK_2F0F9021A76ED395');
        $this->addSql('DROP TABLE document_reviewers');
        $this->addSql('ALTER TABLE documents DROP FOREIGN KEY FK_A2B0728879F7D87D');
        $this->addSql('ALTER TABLE documents DROP FOREIGN KEY FK_A2B072885B075477');
        $this->addSql('DROP INDEX IDX_A2B0728879F7D87D ON documents');
        $this->addSql('DROP INDEX IDX_A2B072885B075477 ON documents');
        $this->addSql('ALTER TABLE documents DROP workflow_state, DROP submitted_at, DROP published_at, DROP submitted_by_id, DROP published_by_id');
    }
}
