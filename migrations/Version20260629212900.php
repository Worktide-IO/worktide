<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260629212900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE agreement_types (name VARCHAR(120) NOT NULL, slug VARCHAR(64) NOT NULL, description LONGTEXT DEFAULT NULL, is_mandatory TINYINT DEFAULT 0 NOT NULL, position DOUBLE PRECISION DEFAULT 0 NOT NULL, is_archived TINYINT DEFAULT 0 NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_3BD993D7D182D95 (created_by_user_id), INDEX IDX_3BD993D2793CC5E (updated_by_user_id), INDEX agreement_type_workspace_idx (workspace_id), UNIQUE INDEX agreement_type_ws_slug_uniq (workspace_id, slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE customer_agreement_revisions (version_no INT DEFAULT 1 NOT NULL, status VARCHAR(16) NOT NULL, signed_on DATE DEFAULT NULL, valid_until DATE DEFAULT NULL, reference VARCHAR(120) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, agreement_id BINARY(16) NOT NULL, file_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_5E0EF16893CB796C (file_id), INDEX IDX_5E0EF1687D182D95 (created_by_user_id), INDEX IDX_5E0EF1682793CC5E (updated_by_user_id), INDEX agr_rev_agreement_idx (agreement_id), INDEX agr_rev_workspace_idx (workspace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE customer_agreements (type_slug VARCHAR(64) NOT NULL, status VARCHAR(16) DEFAULT \'none\' NOT NULL, signed_on DATE DEFAULT NULL, valid_until DATE DEFAULT NULL, notes LONGTEXT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, customer_id BINARY(16) NOT NULL, type_id BINARY(16) NOT NULL, current_revision_id BINARY(16) DEFAULT NULL, pending_revision_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_DAEA4A02A32ED756 (current_revision_id), INDEX IDX_DAEA4A02465EF34B (pending_revision_id), INDEX IDX_DAEA4A027D182D95 (created_by_user_id), INDEX IDX_DAEA4A022793CC5E (updated_by_user_id), INDEX customer_agreement_customer_idx (customer_id), INDEX customer_agreement_type_idx (type_id), INDEX customer_agreement_workspace_idx (workspace_id), INDEX customer_agreement_status_idx (status), INDEX customer_agreement_slug_idx (type_slug), INDEX customer_agreement_valid_until_idx (valid_until), UNIQUE INDEX customer_agreement_customer_type_uniq (customer_id, type_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE agreement_types ADD CONSTRAINT FK_3BD993D82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agreement_types ADD CONSTRAINT FK_3BD993D7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE agreement_types ADD CONSTRAINT FK_3BD993D2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_agreement_revisions ADD CONSTRAINT FK_5E0EF16824890B2B FOREIGN KEY (agreement_id) REFERENCES customer_agreements (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_agreement_revisions ADD CONSTRAINT FK_5E0EF16893CB796C FOREIGN KEY (file_id) REFERENCES files (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_agreement_revisions ADD CONSTRAINT FK_5E0EF16882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_agreement_revisions ADD CONSTRAINT FK_5E0EF1687D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_agreement_revisions ADD CONSTRAINT FK_5E0EF1682793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_agreements ADD CONSTRAINT FK_DAEA4A029395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_agreements ADD CONSTRAINT FK_DAEA4A02C54C8C93 FOREIGN KEY (type_id) REFERENCES agreement_types (id)');
        $this->addSql('ALTER TABLE customer_agreements ADD CONSTRAINT FK_DAEA4A02A32ED756 FOREIGN KEY (current_revision_id) REFERENCES customer_agreement_revisions (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_agreements ADD CONSTRAINT FK_DAEA4A02465EF34B FOREIGN KEY (pending_revision_id) REFERENCES customer_agreement_revisions (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_agreements ADD CONSTRAINT FK_DAEA4A0282D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_agreements ADD CONSTRAINT FK_DAEA4A027D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_agreements ADD CONSTRAINT FK_DAEA4A022793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agreement_types DROP FOREIGN KEY FK_3BD993D82D40A1F');
        $this->addSql('ALTER TABLE agreement_types DROP FOREIGN KEY FK_3BD993D7D182D95');
        $this->addSql('ALTER TABLE agreement_types DROP FOREIGN KEY FK_3BD993D2793CC5E');
        $this->addSql('ALTER TABLE customer_agreement_revisions DROP FOREIGN KEY FK_5E0EF16824890B2B');
        $this->addSql('ALTER TABLE customer_agreement_revisions DROP FOREIGN KEY FK_5E0EF16893CB796C');
        $this->addSql('ALTER TABLE customer_agreement_revisions DROP FOREIGN KEY FK_5E0EF16882D40A1F');
        $this->addSql('ALTER TABLE customer_agreement_revisions DROP FOREIGN KEY FK_5E0EF1687D182D95');
        $this->addSql('ALTER TABLE customer_agreement_revisions DROP FOREIGN KEY FK_5E0EF1682793CC5E');
        $this->addSql('ALTER TABLE customer_agreements DROP FOREIGN KEY FK_DAEA4A029395C3F3');
        $this->addSql('ALTER TABLE customer_agreements DROP FOREIGN KEY FK_DAEA4A02C54C8C93');
        $this->addSql('ALTER TABLE customer_agreements DROP FOREIGN KEY FK_DAEA4A02A32ED756');
        $this->addSql('ALTER TABLE customer_agreements DROP FOREIGN KEY FK_DAEA4A02465EF34B');
        $this->addSql('ALTER TABLE customer_agreements DROP FOREIGN KEY FK_DAEA4A0282D40A1F');
        $this->addSql('ALTER TABLE customer_agreements DROP FOREIGN KEY FK_DAEA4A027D182D95');
        $this->addSql('ALTER TABLE customer_agreements DROP FOREIGN KEY FK_DAEA4A022793CC5E');
        $this->addSql('DROP TABLE agreement_types');
        $this->addSql('DROP TABLE customer_agreement_revisions');
        $this->addSql('DROP TABLE customer_agreements');
    }
}
