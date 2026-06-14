<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260614103100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRM-2 — CustomerSystem + ServiceSubscription';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE customer_systems (name VARCHAR(200) NOT NULL, type VARCHAR(24) NOT NULL, system_version VARCHAR(40) DEFAULT NULL, url VARCHAR(2048) DEFAULT NULL, staging_url VARCHAR(2048) DEFAULT NULL, admin_login_url VARCHAR(2048) DEFAULT NULL, hosting_provider VARCHAR(120) DEFAULT NULL, environment VARCHAR(24) NOT NULL, credentials_notes LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, is_active TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, external_source VARCHAR(60) DEFAULT NULL, external_id VARCHAR(200) DEFAULT NULL, customer_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_7D707C0C7D182D95 (created_by_user_id), INDEX IDX_7D707C0C2793CC5E (updated_by_user_id), INDEX customer_system_customer_idx (customer_id), INDEX customer_system_workspace_idx (workspace_id), INDEX customer_system_type_idx (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_subscriptions (name VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, price_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, billing_cycle VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, started_on DATE NOT NULL, ended_on DATE DEFAULT NULL, auto_renew TINYINT NOT NULL, next_billing_on DATE DEFAULT NULL, notes LONGTEXT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, external_source VARCHAR(60) DEFAULT NULL, external_id VARCHAR(200) DEFAULT NULL, customer_id BINARY(16) NOT NULL, system_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_D4C830837D182D95 (created_by_user_id), INDEX IDX_D4C830832793CC5E (updated_by_user_id), INDEX svc_sub_customer_idx (customer_id), INDEX svc_sub_system_idx (system_id), INDEX svc_sub_workspace_idx (workspace_id), INDEX svc_sub_status_idx (status), INDEX svc_sub_next_billing_idx (next_billing_on), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE customer_systems ADD CONSTRAINT FK_7D707C0C9395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_systems ADD CONSTRAINT FK_7D707C0C82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_systems ADD CONSTRAINT FK_7D707C0C7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_systems ADD CONSTRAINT FK_7D707C0C2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_subscriptions ADD CONSTRAINT FK_D4C830839395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_subscriptions ADD CONSTRAINT FK_D4C83083D0952FA5 FOREIGN KEY (system_id) REFERENCES customer_systems (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_subscriptions ADD CONSTRAINT FK_D4C8308382D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_subscriptions ADD CONSTRAINT FK_D4C830837D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_subscriptions ADD CONSTRAINT FK_D4C830832793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE customer_systems DROP FOREIGN KEY FK_7D707C0C9395C3F3');
        $this->addSql('ALTER TABLE customer_systems DROP FOREIGN KEY FK_7D707C0C82D40A1F');
        $this->addSql('ALTER TABLE customer_systems DROP FOREIGN KEY FK_7D707C0C7D182D95');
        $this->addSql('ALTER TABLE customer_systems DROP FOREIGN KEY FK_7D707C0C2793CC5E');
        $this->addSql('ALTER TABLE service_subscriptions DROP FOREIGN KEY FK_D4C830839395C3F3');
        $this->addSql('ALTER TABLE service_subscriptions DROP FOREIGN KEY FK_D4C83083D0952FA5');
        $this->addSql('ALTER TABLE service_subscriptions DROP FOREIGN KEY FK_D4C8308382D40A1F');
        $this->addSql('ALTER TABLE service_subscriptions DROP FOREIGN KEY FK_D4C830837D182D95');
        $this->addSql('ALTER TABLE service_subscriptions DROP FOREIGN KEY FK_D4C830832793CC5E');
        $this->addSql('DROP TABLE customer_systems');
        $this->addSql('DROP TABLE service_subscriptions');
    }
}
