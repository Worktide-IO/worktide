<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704130710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portal: project_offers + project_proposals.converted_offer_id.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE project_offers (reference VARCHAR(32) NOT NULL, title VARCHAR(200) NOT NULL, amount_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(16) DEFAULT \'open\' NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, customer_id BINARY(16) NOT NULL, project_id BINARY(16) NOT NULL, source_proposal_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_CDE2EF6D166D1F9C (project_id), INDEX IDX_CDE2EF6DC80670F8 (source_proposal_id), INDEX IDX_CDE2EF6D7D182D95 (created_by_user_id), INDEX IDX_CDE2EF6D2793CC5E (updated_by_user_id), INDEX project_offer_customer_idx (customer_id), INDEX project_offer_workspace_idx (workspace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE project_offers ADD CONSTRAINT FK_CDE2EF6D9395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_offers ADD CONSTRAINT FK_CDE2EF6D166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_offers ADD CONSTRAINT FK_CDE2EF6DC80670F8 FOREIGN KEY (source_proposal_id) REFERENCES project_proposals (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_offers ADD CONSTRAINT FK_CDE2EF6D82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_offers ADD CONSTRAINT FK_CDE2EF6D7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_offers ADD CONSTRAINT FK_CDE2EF6D2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_proposals ADD converted_offer_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE project_proposals ADD CONSTRAINT FK_B4867F58754EE286 FOREIGN KEY (converted_offer_id) REFERENCES project_offers (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B4867F58754EE286 ON project_proposals (converted_offer_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project_offers DROP FOREIGN KEY FK_CDE2EF6D9395C3F3');
        $this->addSql('ALTER TABLE project_offers DROP FOREIGN KEY FK_CDE2EF6D166D1F9C');
        $this->addSql('ALTER TABLE project_offers DROP FOREIGN KEY FK_CDE2EF6DC80670F8');
        $this->addSql('ALTER TABLE project_offers DROP FOREIGN KEY FK_CDE2EF6D82D40A1F');
        $this->addSql('ALTER TABLE project_offers DROP FOREIGN KEY FK_CDE2EF6D7D182D95');
        $this->addSql('ALTER TABLE project_offers DROP FOREIGN KEY FK_CDE2EF6D2793CC5E');
        $this->addSql('DROP TABLE project_offers');
        $this->addSql('ALTER TABLE project_proposals DROP FOREIGN KEY FK_B4867F58754EE286');
        $this->addSql('DROP INDEX IDX_B4867F58754EE286 ON project_proposals');
        $this->addSql('ALTER TABLE project_proposals DROP converted_offer_id');
    }
}
