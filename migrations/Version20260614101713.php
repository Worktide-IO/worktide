<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260614101713 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRM-1 — Customer + Contact + Project.customer FK';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE contacts (salutation VARCHAR(20) DEFAULT NULL, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, title VARCHAR(40) DEFAULT NULL, position VARCHAR(120) DEFAULT NULL, email VARCHAR(254) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, mobile VARCHAR(40) DEFAULT NULL, is_primary TINYINT NOT NULL, is_active TINYINT NOT NULL, notes LONGTEXT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, external_source VARCHAR(60) DEFAULT NULL, external_id VARCHAR(200) DEFAULT NULL, customer_id BINARY(16) NOT NULL, linked_user_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_33401573CC26EB02 (linked_user_id), INDEX IDX_334015737D182D95 (created_by_user_id), INDEX IDX_334015732793CC5E (updated_by_user_id), INDEX contact_customer_idx (customer_id), INDEX contact_workspace_idx (workspace_id), INDEX contact_email_idx (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE customers (name VARCHAR(200) NOT NULL, legal_name VARCHAR(200) DEFAULT NULL, is_company TINYINT NOT NULL, vat_id VARCHAR(40) DEFAULT NULL, email VARCHAR(254) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, website VARCHAR(2048) DEFAULT NULL, industry VARCHAR(120) DEFAULT NULL, address_line1 VARCHAR(200) DEFAULT NULL, address_line2 VARCHAR(200) DEFAULT NULL, zip VARCHAR(20) DEFAULT NULL, city VARCHAR(120) DEFAULT NULL, country VARCHAR(2) DEFAULT NULL, status VARCHAR(16) NOT NULL, notes LONGTEXT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, external_source VARCHAR(60) DEFAULT NULL, external_id VARCHAR(200) DEFAULT NULL, account_manager_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_62534E2184A5C6C7 (account_manager_id), INDEX IDX_62534E2182D40A1F (workspace_id), INDEX IDX_62534E217D182D95 (created_by_user_id), INDEX IDX_62534E212793CC5E (updated_by_user_id), INDEX customer_workspace_status_idx (workspace_id, status), INDEX customer_name_idx (workspace_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE customer_tag_map (customer_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_8EAE94079395C3F3 (customer_id), INDEX IDX_8EAE9407BAD26311 (tag_id), PRIMARY KEY (customer_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE contacts ADD CONSTRAINT FK_334015739395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contacts ADD CONSTRAINT FK_33401573CC26EB02 FOREIGN KEY (linked_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE contacts ADD CONSTRAINT FK_3340157382D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contacts ADD CONSTRAINT FK_334015737D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE contacts ADD CONSTRAINT FK_334015732793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customers ADD CONSTRAINT FK_62534E2184A5C6C7 FOREIGN KEY (account_manager_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customers ADD CONSTRAINT FK_62534E2182D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customers ADD CONSTRAINT FK_62534E217D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customers ADD CONSTRAINT FK_62534E212793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_tag_map ADD CONSTRAINT FK_8EAE94079395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_tag_map ADD CONSTRAINT FK_8EAE9407BAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE projects ADD customer_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE projects ADD CONSTRAINT FK_5C93B3A49395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5C93B3A49395C3F3 ON projects (customer_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contacts DROP FOREIGN KEY FK_334015739395C3F3');
        $this->addSql('ALTER TABLE contacts DROP FOREIGN KEY FK_33401573CC26EB02');
        $this->addSql('ALTER TABLE contacts DROP FOREIGN KEY FK_3340157382D40A1F');
        $this->addSql('ALTER TABLE contacts DROP FOREIGN KEY FK_334015737D182D95');
        $this->addSql('ALTER TABLE contacts DROP FOREIGN KEY FK_334015732793CC5E');
        $this->addSql('ALTER TABLE customers DROP FOREIGN KEY FK_62534E2184A5C6C7');
        $this->addSql('ALTER TABLE customers DROP FOREIGN KEY FK_62534E2182D40A1F');
        $this->addSql('ALTER TABLE customers DROP FOREIGN KEY FK_62534E217D182D95');
        $this->addSql('ALTER TABLE customers DROP FOREIGN KEY FK_62534E212793CC5E');
        $this->addSql('ALTER TABLE customer_tag_map DROP FOREIGN KEY FK_8EAE94079395C3F3');
        $this->addSql('ALTER TABLE customer_tag_map DROP FOREIGN KEY FK_8EAE9407BAD26311');
        $this->addSql('DROP TABLE contacts');
        $this->addSql('DROP TABLE customers');
        $this->addSql('DROP TABLE customer_tag_map');
        $this->addSql('ALTER TABLE projects DROP FOREIGN KEY FK_5C93B3A49395C3F3');
        $this->addSql('DROP INDEX IDX_5C93B3A49395C3F3 ON projects');
        $this->addSql('ALTER TABLE projects DROP customer_id');
    }
}
