<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704152843 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE agreement_line_items (description VARCHAR(200) NOT NULL, quantity DOUBLE PRECISION DEFAULT 1 NOT NULL, unit_amount_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, is_recurring TINYINT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, revision_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_1FAA60ED82D40A1F (workspace_id), INDEX agr_line_revision_idx (revision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE agreement_line_items ADD CONSTRAINT FK_1FAA60ED1DFA7C8F FOREIGN KEY (revision_id) REFERENCES customer_agreement_revisions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agreement_line_items ADD CONSTRAINT FK_1FAA60ED82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agreement_line_items DROP FOREIGN KEY FK_1FAA60ED1DFA7C8F');
        $this->addSql('ALTER TABLE agreement_line_items DROP FOREIGN KEY FK_1FAA60ED82D40A1F');
        $this->addSql('DROP TABLE agreement_line_items');
    }
}
