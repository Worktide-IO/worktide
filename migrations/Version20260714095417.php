<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Service catalogue (Migration A): create services, service_versions,
 * service_assignments. The legacy service_subscriptions table is left in place
 * and dropped by a later migration once its data has been migrated across.
 */
final class Version20260714095417 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create service catalogue tables (services, service_versions, service_assignments)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_assignments (started_on DATE NOT NULL, ended_on DATE DEFAULT NULL, notes LONGTEXT DEFAULT NULL, status VARCHAR(16) NOT NULL, auto_renew TINYINT NOT NULL, net_price_override_cents INT DEFAULT NULL, next_billing_on DATE DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, external_source VARCHAR(60) DEFAULT NULL, external_id VARCHAR(200) DEFAULT NULL, customer_id BINARY(16) NOT NULL, system_id BINARY(16) DEFAULT NULL, service_version_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_FC1076717D182D95 (created_by_user_id), INDEX IDX_FC1076712793CC5E (updated_by_user_id), INDEX svc_assign_customer_idx (customer_id), INDEX svc_assign_system_idx (system_id), INDEX svc_assign_version_idx (service_version_id), INDEX svc_assign_workspace_idx (workspace_id), INDEX svc_assign_status_idx (status), INDEX svc_assign_next_billing_idx (next_billing_on), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_versions (version_no INT DEFAULT 1 NOT NULL, label VARCHAR(120) DEFAULT NULL, changelog LONGTEXT DEFAULT NULL, net_price_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, billing_cycle VARCHAR(16) NOT NULL, effective_from DATE DEFAULT NULL, is_current TINYINT DEFAULT 0 NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, service_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_E0AA1BEF7D182D95 (created_by_user_id), INDEX IDX_E0AA1BEF2793CC5E (updated_by_user_id), INDEX service_version_service_idx (service_id), INDEX service_version_workspace_idx (workspace_id), UNIQUE INDEX service_version_uniq (service_id, version_no), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE services (name VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, category VARCHAR(120) DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, current_version_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_7332E1699407EE77 (current_version_id), INDEX IDX_7332E1697D182D95 (created_by_user_id), INDEX IDX_7332E1692793CC5E (updated_by_user_id), INDEX service_workspace_idx (workspace_id), INDEX service_name_idx (workspace_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_assignments ADD CONSTRAINT FK_FC1076719395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_assignments ADD CONSTRAINT FK_FC107671D0952FA5 FOREIGN KEY (system_id) REFERENCES customer_systems (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_assignments ADD CONSTRAINT FK_FC107671DDED1EB1 FOREIGN KEY (service_version_id) REFERENCES service_versions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE service_assignments ADD CONSTRAINT FK_FC10767182D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_assignments ADD CONSTRAINT FK_FC1076717D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_assignments ADD CONSTRAINT FK_FC1076712793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_versions ADD CONSTRAINT FK_E0AA1BEFED5CA9E6 FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_versions ADD CONSTRAINT FK_E0AA1BEF82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_versions ADD CONSTRAINT FK_E0AA1BEF7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_versions ADD CONSTRAINT FK_E0AA1BEF2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE services ADD CONSTRAINT FK_7332E1699407EE77 FOREIGN KEY (current_version_id) REFERENCES service_versions (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE services ADD CONSTRAINT FK_7332E16982D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE services ADD CONSTRAINT FK_7332E1697D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE services ADD CONSTRAINT FK_7332E1692793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_assignments DROP FOREIGN KEY FK_FC1076719395C3F3');
        $this->addSql('ALTER TABLE service_assignments DROP FOREIGN KEY FK_FC107671D0952FA5');
        $this->addSql('ALTER TABLE service_assignments DROP FOREIGN KEY FK_FC107671DDED1EB1');
        $this->addSql('ALTER TABLE service_assignments DROP FOREIGN KEY FK_FC10767182D40A1F');
        $this->addSql('ALTER TABLE service_assignments DROP FOREIGN KEY FK_FC1076717D182D95');
        $this->addSql('ALTER TABLE service_assignments DROP FOREIGN KEY FK_FC1076712793CC5E');
        $this->addSql('ALTER TABLE service_versions DROP FOREIGN KEY FK_E0AA1BEFED5CA9E6');
        $this->addSql('ALTER TABLE service_versions DROP FOREIGN KEY FK_E0AA1BEF82D40A1F');
        $this->addSql('ALTER TABLE service_versions DROP FOREIGN KEY FK_E0AA1BEF7D182D95');
        $this->addSql('ALTER TABLE service_versions DROP FOREIGN KEY FK_E0AA1BEF2793CC5E');
        $this->addSql('ALTER TABLE services DROP FOREIGN KEY FK_7332E1699407EE77');
        $this->addSql('ALTER TABLE services DROP FOREIGN KEY FK_7332E16982D40A1F');
        $this->addSql('ALTER TABLE services DROP FOREIGN KEY FK_7332E1697D182D95');
        $this->addSql('ALTER TABLE services DROP FOREIGN KEY FK_7332E1692793CC5E');
        $this->addSql('DROP TABLE service_assignments');
        $this->addSql('DROP TABLE service_versions');
        $this->addSql('DROP TABLE services');
    }
}
