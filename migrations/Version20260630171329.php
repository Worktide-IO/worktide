<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260630171329 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE customer_products (status VARCHAR(12) DEFAULT \'active\' NOT NULL, acquired_at DATE DEFAULT NULL, notes LONGTEXT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, customer_id BINARY(16) NOT NULL, product_id BINARY(16) NOT NULL, version_id BINARY(16) DEFAULT NULL, system_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_9F7F1975D0952FA5 (system_id), INDEX IDX_9F7F19757D182D95 (created_by_user_id), INDEX IDX_9F7F19752793CC5E (updated_by_user_id), INDEX customer_product_customer_idx (customer_id), INDEX customer_product_product_idx (product_id), INDEX customer_product_version_idx (version_id), INDEX customer_product_workspace_idx (workspace_id), UNIQUE INDEX customer_product_uniq (customer_id, product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_versions (version VARCHAR(60) NOT NULL, release_date DATE DEFAULT NULL, release_notes LONGTEXT DEFAULT NULL, status VARCHAR(12) DEFAULT \'current\' NOT NULL, is_latest TINYINT DEFAULT 0 NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, product_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_D26C2A457D182D95 (created_by_user_id), INDEX IDX_D26C2A452793CC5E (updated_by_user_id), INDEX product_version_product_idx (product_id), INDEX product_version_workspace_idx (workspace_id), UNIQUE INDEX product_version_uniq (product_id, version), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE products (name VARCHAR(160) NOT NULL, slug VARCHAR(64) NOT NULL, type VARCHAR(12) NOT NULL, status VARCHAR(12) DEFAULT \'active\' NOT NULL, description LONGTEXT DEFAULT NULL, category VARCHAR(80) DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, latest_version_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_B3BA5A5A5F67402F (latest_version_id), INDEX IDX_B3BA5A5A7D182D95 (created_by_user_id), INDEX IDX_B3BA5A5A2793CC5E (updated_by_user_id), INDEX product_workspace_idx (workspace_id), INDEX product_type_idx (type), UNIQUE INDEX product_ws_slug_uniq (workspace_id, slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE customer_products ADD CONSTRAINT FK_9F7F19759395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_products ADD CONSTRAINT FK_9F7F19754584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_products ADD CONSTRAINT FK_9F7F19754BBC2705 FOREIGN KEY (version_id) REFERENCES product_versions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE customer_products ADD CONSTRAINT FK_9F7F1975D0952FA5 FOREIGN KEY (system_id) REFERENCES customer_systems (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_products ADD CONSTRAINT FK_9F7F197582D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_products ADD CONSTRAINT FK_9F7F19757D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_products ADD CONSTRAINT FK_9F7F19752793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE product_versions ADD CONSTRAINT FK_D26C2A454584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_versions ADD CONSTRAINT FK_D26C2A4582D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_versions ADD CONSTRAINT FK_D26C2A457D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE product_versions ADD CONSTRAINT FK_D26C2A452793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5A5F67402F FOREIGN KEY (latest_version_id) REFERENCES product_versions (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5A82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5A7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5A2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE customer_products DROP FOREIGN KEY FK_9F7F19759395C3F3');
        $this->addSql('ALTER TABLE customer_products DROP FOREIGN KEY FK_9F7F19754584665A');
        $this->addSql('ALTER TABLE customer_products DROP FOREIGN KEY FK_9F7F19754BBC2705');
        $this->addSql('ALTER TABLE customer_products DROP FOREIGN KEY FK_9F7F1975D0952FA5');
        $this->addSql('ALTER TABLE customer_products DROP FOREIGN KEY FK_9F7F197582D40A1F');
        $this->addSql('ALTER TABLE customer_products DROP FOREIGN KEY FK_9F7F19757D182D95');
        $this->addSql('ALTER TABLE customer_products DROP FOREIGN KEY FK_9F7F19752793CC5E');
        $this->addSql('ALTER TABLE product_versions DROP FOREIGN KEY FK_D26C2A454584665A');
        $this->addSql('ALTER TABLE product_versions DROP FOREIGN KEY FK_D26C2A4582D40A1F');
        $this->addSql('ALTER TABLE product_versions DROP FOREIGN KEY FK_D26C2A457D182D95');
        $this->addSql('ALTER TABLE product_versions DROP FOREIGN KEY FK_D26C2A452793CC5E');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_B3BA5A5A5F67402F');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_B3BA5A5A82D40A1F');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_B3BA5A5A7D182D95');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_B3BA5A5A2793CC5E');
        $this->addSql('DROP TABLE customer_products');
        $this->addSql('DROP TABLE product_versions');
        $this->addSql('DROP TABLE products');
    }
}
